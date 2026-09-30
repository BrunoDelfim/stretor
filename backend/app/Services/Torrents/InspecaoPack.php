<?php

namespace App\Services\Torrents;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Consulta o conteúdo de um pack para descobrir se o áudio é PT-BR.
 *
 * O nome do torrent é a primeira prova de idioma, e é barata: vem junto do
 * resultado do provedor. Quando ele não prova nada ("S01 1080p WEB-DL"), a
 * única forma de saber é abrir o torrent e olhar os caminhos internos — muitos
 * packs nacionais marcam a pasta ("Dublado/...") ou o nome de cada episódio, e
 * não o título do torrent.
 *
 * Quem lê o conteúdo é o media-service, que já carrega o WebTorrent: abrir o
 * magnet, esperar os metadados e listar os arquivos é o trabalho dele. Aqui só
 * ficam o contrato HTTP e o cache.
 *
 * O cache é por infohash e guarda **apenas respostas definitivas** — as em que o
 * metadata-service conseguiu ler os metadados e deu um veredito. Uma falha (magnet
 * morto, tempo esgotado, serviço fora do ar) devolve `null` e **não** é cacheada:
 * gravar "sem PT-BR" ali envenenaria o resultado até o TTL inteiro, e a próxima
 * busca merece uma nova tentativa.
 */
class InspecaoPack
{
    /**
     * Sal de versão da chave de cache das inspeções.
     *
     * Mesmo raciocínio do [`CatalogoProvedores::VERSAO_CACHE`]: subir isto a cada
     * mudança na lógica de leitura (a lista de indícios, por exemplo) faz a
     * reconsulta valer de imediato, sem depender de limpar o Redis à mão.
     */
    private const VERSAO_CACHE = 2;

    /**
     * Apura se o pack tem indício de áudio PT-BR no conteúdo.
     *
     * @param  string  $magnet    Magnet do pack (o media-service o abre)
     * @param  string  $infohash  Identificador estável, usado como chave de cache
     * @return bool|null  `true` há indício; `false` o conteúdo foi lido e não há;
     *                    `null` não deu para concluir (não cacheado)
     */
    public function apurar(string $magnet, string $infohash): ?bool
    {
        if (trim($magnet) === '') {
            return null;
        }

        $chave = 'torrent:pack:'.self::VERSAO_CACHE.':'.$infohash;

        // O cache guarda só `true`/`false`; `get()` devolve `null` quando a chave
        // não existe, que é exatamente o "ainda não sei" que queremos propagar.
        $cacheado = Cache::get($chave);

        if ($cacheado !== null) {
            return (bool) $cacheado;
        }

        $veredito = $this->consultar($magnet);

        if ($veredito !== null) {
            $ttl = (int) config('services.torrents.inspecao_cache_ttl', 86400);

            Cache::put($chave, $veredito, $ttl);
        }

        return $veredito;
    }

    /**
     * Apura vários packs numa única rodada, em paralelo.
     *
     * A inspeção sequencial era o gargalo real da busca de episódio: cada pack
     * custa uma conexão e uma espera no media-service, e o teto de packs
     * (`inspecao_packs_limite`) multiplicava esse custo. Com seis packs e o
     * `inspecao_timeout` de 12 s, o pior caso passava de 70 s só nesta etapa —
     * muito acima do orçamento da busca inteira, e era isso que estourava o
     * tempo do frontend mesmo com todos os provedores vindo do cache.
     *
     * Aqui os packs são abertos **ao mesmo tempo**, com o mesmo `Http::pool` que
     * os provedores já usam. O tempo de parede passa a ser o do pack mais lento,
     * não a soma de todos. O teto de cada requisição é o menor entre o
     * `inspecao_timeout` e o que resta do orçamento global: quando o prazo já
     * acabou, o pool nem é aberto e todos ficam sem veredito — o que é correto,
     * porque a busca está encerrando e não há mais tempo para provar idioma.
     *
     * O cache é consultado antes de montar o pool: um pack já julgado não gasta
     * conexão. Só os que ainda não têm veredito entram na rodada.
     *
     * @param  array<string, string>  $magnetPorId  infohash => magnet
     * @param  OrcamentoBusca  $orcamento  Relógio da busca, para encolher o teto
     * @return array<string, bool>  infohash => veredito (só os definitivos)
     */
    public function apurarVarios(array $magnetPorId, OrcamentoBusca $orcamento): array
    {
        $vereditos = [];
        $pendentes = [];

        foreach ($magnetPorId as $id => $magnet) {
            if (trim((string) $magnet) === '') {
                continue;
            }

            $chave = 'torrent:pack:'.self::VERSAO_CACHE.':'.$id;
            $cacheado = Cache::get($chave);

            if ($cacheado !== null) {
                $vereditos[$id] = (bool) $cacheado;

                continue;
            }

            $pendentes[$id] = (string) $magnet;
        }

        if ($pendentes === []) {
            return $vereditos;
        }

        $base = rtrim((string) config('services.media_service.url', ''), '/');

        if ($base === '') {
            return $vereditos;
        }

        $teto = (int) config('services.torrents.inspecao_timeout', 12);
        $restante = $orcamento->restante();

        // Sem orçamento em curso, vale o teto próprio. Com orçamento, o teto é o
        // menor entre ele e o que sobra — e zero significa "não abra o pool".
        $tempoLimite = $restante === null ? $teto : min($teto, $restante);

        if ($tempoLimite <= 0) {
            return $vereditos;
        }

        // A espera pedida ao media-service fica abaixo do tempo limite do HTTP
        // para que ele responda primeiro: assim recebemos o veredito ou uma falha
        // tratada, em vez de estourar o timeout com a conexão aberta.
        $esperaMs = max(1, $tempoLimite - 2) * 1000;

        /*
         * O `connectTimeout` corta a fase de conexão, que o `timeout()` não
         * cobre: se o media-service estiver ocupado, o TCP fica pendurado no
         * handshake e o pool espera muito além do teto. Com o corte, uma conexão
         * travada falha rápido e não segura os demais packs da rodada.
         */
        $conexao = max(1, min(3, $tempoLimite));

        $respostas = Http::pool(fn ($pool) => array_map(
            fn (string $id) => $pool->as($id)
                ->connectTimeout($conexao)
                ->timeout($tempoLimite)
                ->acceptJson()
                ->post($base.'/api/media/metadados', [
                    'magnet' => $pendentes[$id],
                    'espera_ms' => $esperaMs,
                ]),
            array_keys($pendentes)
        ));

        $ttl = (int) config('services.torrents.inspecao_cache_ttl', 86400);

        foreach ($pendentes as $id => $magnet) {
            $resposta = $respostas[$id] ?? null;

            // Falha de rede no pool chega como exceção dentro do array; qualquer
            // coisa que não seja uma resposta bem-sucedida é "não sei", e não é
            // cacheada — a próxima busca tenta de novo.
            if (! $resposta instanceof \Illuminate\Http\Client\Response || ! $resposta->successful()) {
                continue;
            }

            $dados = $resposta->json();

            if (! is_array($dados) || ($dados['ok'] ?? false) !== true) {
                continue;
            }

            $indicio = (bool) ($dados['indicio_pt_br'] ?? false);

            Log::debug('Inspeção de pack concluída.', [
                'nome' => $dados['nome'] ?? '',
                'arquivos' => $dados['arquivos'] ?? 0,
                'indicio_pt_br' => $indicio,
                'prova' => $dados['prova'] ?? null,
            ]);

            Cache::put('torrent:pack:'.self::VERSAO_CACHE.':'.$id, $indicio, $ttl);

            $vereditos[$id] = $indicio;
        }

        return $vereditos;
    }

    /**
     * Chama o endpoint de metadados do media-service e traduz a resposta.
     *
     * A espera pedida ao media-service fica **abaixo** do tempo limite do HTTP
     * para que ele seja o primeiro a responder: assim o backend recebe ou o
     * veredito ou uma falha tratada, em vez de estourar o timeout com a conexão
     * aberta.
     *
     * @return bool|null
     */
    private function consultar(string $magnet): ?bool
    {
        $base = rtrim((string) config('services.media_service.url', ''), '/');

        if ($base === '') {
            return null;
        }

        $tempoLimite = (int) config('services.torrents.inspecao_timeout', 12);
        $esperaMs = max(1, $tempoLimite - 2) * 1000;

        try {
            $resposta = Http::timeout($tempoLimite)
                ->acceptJson()
                ->post($base.'/api/media/metadados', [
                    'magnet' => $magnet,
                    'espera_ms' => $esperaMs,
                ]);

            if (! $resposta->successful()) {
                return null;
            }

            $dados = $resposta->json();

            // `ok = false` é falha de leitura, não veredito: não marca o pack como
            // "sem PT-BR" só porque o torrent não abriu a tempo.
            if (! is_array($dados) || ($dados['ok'] ?? false) !== true) {
                return null;
            }

            $indicio = (bool) ($dados['indicio_pt_br'] ?? false);

            Log::debug('Inspeção de pack concluída.', [
                'nome' => $dados['nome'] ?? '',
                'arquivos' => $dados['arquivos'] ?? 0,
                'indicio_pt_br' => $indicio,
                'prova' => $dados['prova'] ?? null,
            ]);

            return $indicio;
        } catch (\Throwable $excecao) {
            report($excecao);

            return null;
        }
    }
}
