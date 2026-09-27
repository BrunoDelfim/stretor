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
    private const VERSAO_CACHE = 1;

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
