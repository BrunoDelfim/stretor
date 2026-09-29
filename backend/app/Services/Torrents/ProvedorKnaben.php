<?php

namespace App\Services\Torrents;

use App\Contracts\ProvedorPorLote;
use App\Contracts\ProvedorTorrents;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Provedor Knaben — meta-buscador de indexadores públicos.
 *
 * O Knaben agrega o acervo de dezenas de trackers (ThePirateBay, LimeTorrents,
 * entre outros) atrás de uma única API JSON, sem chave e sem HTML. É a aposta da
 * Fase 2 para as séries antigas: como cobre trackers que os nossos provedores
 * nativos não varrem, ele devolve justamente os packs nacionais que a busca
 * atual não enxerga — nos testes, o termo "American Horror Story S01 completa"
 * trouxe o pack "American Horror Story S01 Completa Legendado PT-BR", e o termo
 * "Temporada 1" trouxe o "1ª Temporada [2011 DUAL AUDIO] 720p".
 *
 * A armadilha do contrato é o `search_type`. Com `"score"` — que é o que a
 * maioria dos exemplos por aí usa — a API **ignora a query** e devolve os
 * torrents mais semeados do acervo inteiro: perguntar por "American Horror
 * Story" devolvia Adobe Photoshop. Só com `"100%"` a API trata a `query` como
 * busca de verdade (exigindo que todos os termos casem). Foi essa a diferença
 * entre zero e o pack certo, então o valor fica fixo em `"100%"` e comentado —
 * trocá-lo de volta por `"score"` ressuscita o bug silencioso.
 *
 * O `order_by` aceita `seeders`, `date`, `size` e `peers`; `relevance` **não**
 * existe e a API responde 400. Ordenamos por seeds para os packs vivos subirem.
 */
class ProvedorKnaben implements ProvedorPorLote, ProvedorTorrents
{
    use ConsultaComOrcamento;
    use NormalizaFonte;

    public function __construct(
        private readonly ClienteHttp $cliente,
        private readonly OrcamentoBusca $orcamento,
    ) {}

    public function identificador(): string
    {
        return 'knaben';
    }

    public function rotulo(): string
    {
        return 'Knaben';
    }

    /**
     * Provedor público, mas atrás de uma chave liga/desliga.
     *
     * Diferente dos outros nativos, o Knaben bate num único host externo que
     * agrega muitos trackers; se ele cair ou passar a limitar requisições, os
     * quatro termos por episódio viram quatro erros. A configuração permite
     * desligá-lo sem reverter código.
     */
    public function disponivel(): bool
    {
        return (bool) config('services.torrents.knaben_habilitado', true)
            && trim((string) config('services.torrents.knaben_url', '')) !== '';
    }

    public function buscar(
        string $titulo,
        ?int $ano = null,
        ?string $imdbId = null,
        ?int $temporada = null,
        ?int $episodio = null,
    ): array {
        if (! $this->disponivel() || trim($titulo) === '') {
            return [];
        }

        try {
            $resposta = $this->pedir($titulo);
        } catch (\Throwable) {
            // Provedor externo fora do ar não pode derrubar a cascata.
            return [];
        }

        if ($resposta === null || $resposta->failed()) {
            return [];
        }

        return $this->fontesDoCorpo($resposta->json('hits'), $temporada, $episodio);
    }

    /**
     * Busca vários termos numa única rodada, em paralelo.
     *
     * A cascata pergunta termo a termo, e cada pergunta era um POST em série: no
     * "Lanternas S01E01" isso virava oito idas e voltas e dez segundos só neste
     * provedor. O pool dispara todos os termos juntos e a espera passa a ser a da
     * resposta mais lenta. A deduplicação por hash acontece no fim, sobre o
     * conjunto inteiro — o mesmo torrent pode aparecer em mais de um termo.
     *
     * @param  array<int, string>  $termos
     * @return array<int, array<string, mixed>>
     */
    public function buscarVarios(
        array $termos,
        ?int $ano = null,
        ?string $imdbId = null,
        ?int $temporada = null,
        ?int $episodio = null,
    ): array {
        $termos = array_values(array_filter(
            array_unique(array_map('trim', $termos)),
            fn (string $termo) => $termo !== '',
        ));

        if (! $this->disponivel() || $termos === []) {
            return [];
        }

        /*
         * O lote inteiro cabe numa única espera, então o teto do pool é o que
         * sobra do orçamento global. Sem esta leitura, o Knaben abria o pool com
         * os 15 s cheios por termo mesmo a segundos do fim da busca — gastava o
         * que restava e voltava para uma rodada já encerrada, sem entregar nada.
         * Com o teto encolhido, ele devolve o que der dentro do prazo real.
         */
        $timeout = $this->tempoDeConsulta((int) config('services.torrents.tempo_limite', 15));

        if ($timeout <= 0) {
            return [];
        }

        /*
         * A closure do pool precisa **devolver** o pedido montado, não executá-lo:
         * é o pool que dispara todos juntos. Por isso aqui usamos `requisicao()`,
         * que só monta, e não `pedir()`, que já executa.
         */
        $respostas = Http::pool(fn ($pool) => array_map(
            fn (string $termo) => $this->requisicao($termo, $pool->as(md5($termo)), $timeout),
            $termos
        ));

        $fontes = [];
        $respondeu = false;

        foreach ($termos as $termo) {
            $resposta = $respostas[md5($termo)] ?? null;

            // O pool devolve a exceção no lugar da resposta quando a conexão
            // falha; só seguimos com respostas HTTP de fato.
            if (! $resposta instanceof Response) {
                continue;
            }

            /*
             * Qualquer resposta HTTP — inclusive um 403 — conta como "o Knaben
             * está de pé". É essa distinção que decide se vale abrir o navegador
             * do FlareSolverr: um 403 prova que o bloqueio é do Cloudflare, e o
             * FlareSolverr esbarra no mesmo desafio. Já a ausência total de
             * resposta aponta para um bloqueio de rede, que o navegador pode
             * contornar.
             */
            $respondeu = true;

            if ($resposta->failed()) {
                continue;
            }

            foreach ($this->fontesDoCorpo($resposta->json('hits'), $temporada, $episodio) as $fonte) {
                $fontes[$fonte['id']] = $fonte;
            }
        }

        /*
         * O socorro pelo FlareSolverr só entra quando **nenhuma** resposta HTTP
         * chegou — sinal de bloqueio de rede, não de Cloudflare. Quando o pool
         * devolveu 403, o navegador não ajudaria: ele esbarra no mesmo desafio e
         * ainda gasta o orçamento para devolver erro.
         *
         * O socorro é de **um termo só**, e não termo a termo: cada chamada abre
         * um navegador, e o primeiro termo (o título puro, sem a tag de idioma) é
         * o mais promissor. Se o Knaben estiver bloqueado, os termos seguintes
         * também estariam — insistir só repetiria a espera.
         */
        if (! $respondeu && $this->cliente->proxyDisponivel() && $this->temOrcamento()) {
            $resposta = $this->cliente->post(
                rtrim((string) config('services.torrents.knaben_url', ''), '/'),
                $this->corpoDoPedido($termos[0]),
                (string) config('services.torrents.user_agent', 'Mozilla/5.0'),
                $this->tempoDeConsulta((int) config('services.torrents.tempo_limite', 15)),
            );

            if ($resposta !== null && ! $resposta->failed()) {
                foreach ($this->fontesDoCorpo($resposta->json('hits'), $temporada, $episodio) as $fonte) {
                    $fontes[$fonte['id']] = $fonte;
                }
            }
        }

        return array_values($fontes);
    }

    /**
     * Monta o pedido do Knaben para um termo, sem disparar.
     *
     * O cliente é injetado pelo pool quando a busca é em lote; na busca isolada
     * ele nasce aqui. O corpo é o mesmo nos dois caminhos — só muda quem dispara.
     */
    private function requisicao(string $termo, mixed $cliente = null, ?int $timeout = null): mixed
    {
        $base = rtrim((string) config('services.torrents.knaben_url', ''), '/');

        /*
         * O teto chega pronto do `buscarVarios()`, que já o encolheu ao orçamento
         * global. Quando a chamada é isolada (sem pool), ele é calculado aqui —
         * assim os dois caminhos respeitam o mesmo prazo.
         */
        $timeout ??= $this->tempoDeConsulta((int) config('services.torrents.tempo_limite', 15));

        /*
         * O `connectTimeout` corta a fase de conexão, que o `timeout()` não
         * cobre: com o host bloqueado, o TCP fica pendurado no handshake e o
         * Guzzle espera muito além do teto. Com o corte, um host morto falha
         * rápido e a vez volta para quem ainda tem tempo.
         */
        return ($cliente ?? Http::asJson())
            ->connectTimeout(max(1, min(3, $timeout)))
            ->timeout($timeout)
            ->withUserAgent((string) config('services.torrents.user_agent', 'Mozilla/5.0'))
            ->post($base, $this->corpoDoPedido($termo));
    }

    /**
     * Corpo JSON do pedido ao Knaben, compartilhado entre o caminho isolado e o
     * lote — assim o contrato do `search_type` fica num lugar só.
     *
     * @return array<string, mixed>
     */
    private function corpoDoPedido(string $termo): array
    {
        return [
            // "100%" é o único modo que aplica a query (ver docblock da classe).
            'search_type' => '100%',
            'search_field' => 'title',
            'query' => $termo,
            'order_by' => 'seeders',
            'order_direction' => 'desc',
            'size' => (int) config('services.torrents.knaben_limite', 20),
            'hide_unsafe' => false,
            'hide_xxx' => true,
        ];
    }

    /**
     * Executa o pedido de um termo na busca isolada.
     */
    private function pedir(string $termo): Response
    {
        /*
         * O caminho isolado passa pelo [`ClienteHttp`], que tenta direto e cai
         * para o FlareSolverr quando a API responde bloqueio. O lote
         * (`buscarVarios()`) continua usando o pool direto — o socorro ali é
         * feito termo a termo, logo abaixo, quando o pool inteiro volta vazio.
         */
        $base = rtrim((string) config('services.torrents.knaben_url', ''), '/');
        $timeout = (int) config('services.torrents.tempo_limite', 15);

        $resposta = $this->cliente->post(
            $base,
            $this->corpoDoPedido($termo),
            (string) config('services.torrents.user_agent', 'Mozilla/5.0'),
            $timeout,
        );

        if ($resposta !== null) {
            return $resposta;
        }

        // Sem resposta nenhuma (nem direta, nem pelo proxy), devolvemos uma
        // resposta vazia para o chamador tratar como "nada encontrado".
        return Http::response('', 503);
    }

    /**
     * Traduz a lista de hits do Knaben no contrato de fontes.
     *
     * @param  mixed  $hits
     * @return array<int, array<string, mixed>>
     */
    private function fontesDoCorpo(mixed $hits, ?int $temporada, ?int $episodio): array
    {
        if (! is_array($hits)) {
            return [];
        }

        $fontes = [];

        foreach ($hits as $hit) {
            if (! is_array($hit)) {
                continue;
            }

            $hash = strtolower(trim((string) ($hit['hash'] ?? '')));

            // Só infohash SHA-1 de verdade: o resto é índice quebrado.
            if (! preg_match('/^[a-f0-9]{40}$/', $hash)) {
                continue;
            }

            $nome = $this->limparTexto((string) ($hit['title'] ?? ''));

            if ($nome === '') {
                continue;
            }

            /*
             * Mesma peneira dos outros provedores por nome: um pack de "S01"
             * pode vir junto de um release "S10E01" da mesma série. Sem o corte,
             * um dublado de outra temporada subiria ao topo e o player abriria o
             * episódio errado. Releases sem numeração (os packs) passam.
             */
            if ($temporada !== null && $episodio !== null
                && ! TermosBusca::correspondeAoEpisodio($nome, $temporada, $episodio)) {
                continue;
            }

            /*
             * O piso de seeds (`SEEDS_NAO_MEDIDOS`) vale aqui apesar de o Knaben
             * informar a contagem: os números dele vêm de indexadores em cache e
             * os packs nacionais antigos aparecem com 0 mesmo vivos — foi o caso
             * do próprio "S01 Completa Legendado PT-BR". O corte de `ordenar()`
             * descarta quem tem 0 seeds, então tratar esse 0 como definitivo
             * repetiria, no Knaben, o descarte que a Fase 2 veio resolver. Quem
             * confirma se a fonte vive é o media-service, que mede os peers na
             * prática antes de abrir a reprodução.
             */
            $seeds = max(self::SEEDS_NAO_MEDIDOS, (int) ($hit['seeders'] ?? 0));

            // A última ocorrência vence — o array por hash já deduplica o que o
            // Knaben devolve duas vezes (mesmo torrent em dois indexadores).
            $fontes[$hash] = $this->montarFonte([
                'id' => $hash,
                'titulo' => $nome,
                'magnet' => $this->magnetDoHash($hash, $nome),
                'tamanho_bytes' => (int) ($hit['bytes'] ?? 0),
                'seeds' => $seeds,
                'peers' => (int) ($hit['peers'] ?? 0),
            ], $this->identificador(), $this->rotulo());
        }

        return array_values($fontes);
    }
}
