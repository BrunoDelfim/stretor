<?php

namespace App\Services\Torrents;

use App\Contracts\ProvedorTorrents;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Provedor BT4G — metabuscador público com magnet na própria página de busca.
 *
 * O BT4G indexa por DHT e entrega o link do magnet direto no resultado, o que
 * dispensa abrir a página de cada lançamento. É o provedor de HTML mais barato
 * de consultar, e cobre releases que os indexadores de catálogo não têm.
 *
 * Sem API e sem chave, a leitura é por HTML — por isso a extração é genérica de
 * propósito: em vez de amarrar em classes CSS (que mudam a cada redesenho),
 * procuramos qualquer link de magnet e lemos o contexto ao redor para achar
 * tamanho e seeds. Se o layout mudar, a busca continua funcionando; no pior caso
 * perdemos os metadados opcionais, não a fonte.
 *
 * Como o site troca de endereço com frequência (espelho novo a cada bloqueio),
 * a lista de espelhos vem da configuração e é percorrida até um responder.
 */
class ProvedorBt4g implements ProvedorTorrents
{
    use ConsultaComOrcamento;
    use NormalizaFonte;

    public function __construct(
        private readonly ClienteHttp $cliente,
        private readonly OrcamentoBusca $orcamento,
    ) {}

    public function identificador(): string
    {
        return 'bt4g';
    }

    public function rotulo(): string
    {
        return 'BT4G';
    }

    /** Provedor público: sempre disponível, sem credencial. */
    public function disponivel(): bool
    {
        return ! empty($this->espelhos());
    }

    public function buscar(
        string $titulo,
        ?int $ano = null,
        ?string $imdbId = null,
        ?int $temporada = null,
        ?int $episodio = null,
    ): array {
        // O BT4G busca por nome, então o título já chega com a numeração do
        // episódio quando for o caso. A diferença é que o ano não entra no termo
        // de episódio: o release de um episódio traz o ano de exibição dele, não
        // o da série, e filtrar pelo ano da série derrubaria o resultado.
        $episodioDeSerie = $temporada !== null && $episodio !== null;

        /*
         * Em episódio o TorrentService já entrega as variações dubladas prontas
         * ("... S01E01 dublado"). Reanexar a tag aqui geraria "dublado dublado",
         * que não casa com release nenhum — por isso só completamos o termo
         * quando ele ainda não traz a tag.
         */
        if ($episodioDeSerie) {
            $termos = [$titulo];

            if (! TermosBusca::jaEDublado($titulo)) {
                $termos[] = "{$titulo} dublado";
                $termos[] = "{$titulo} dual áudio";
            }
        } else {
            $termos = array_merge(
                [TermosBusca::base($titulo, $ano)],
                array_slice(TermosBusca::paraDublado($titulo, $ano), 0, 2),
            );
        }

        foreach ($this->espelhos() as $espelho) {
            $htmls = $this->baixar($espelho, $termos);

            // Espelho fora do ar: nenhum termo respondeu. Tenta o próximo.
            if ($htmls === []) {
                continue;
            }

            $fontes = [];

            foreach ($htmls as $html) {
                $fontes = array_merge($fontes, $this->extrair($html, $temporada, $episodio));
            }

            if (! empty($fontes)) {
                return $fontes;
            }
        }

        return [];
    }

    /**
     * Baixa o HTML de todos os termos do espelho em paralelo.
     *
     * Antes isto era um `foreach` com uma requisição por vez: com os termos de
     * episódio, pack e série somando mais de uma dezena, a espera era a soma de
     * todas as respostas — foi o que fez a busca de uma série nova passar de um
     * minuto e o navegador abortar. O pool dispara tudo junto e a espera passa a
     * ser a da resposta mais lenta. Termo que falha simplesmente não entra; o
     * espelho só é considerado fora do ar quando nenhum respondeu.
     *
     * @param  array<int, string>  $termos
     * @return array<int, string>
     */
    private function baixar(string $base, array $termos): array
    {
        /*
         * O teto do pool é o que sobra do orçamento global, limitado ao teto
         * curto do acervo mundial. Sem a leitura do orçamento, o BT4G abria todas
         * as conexões com os 15 s cheios mesmo a segundos do fim da busca —
         * gastava o que restava e voltava para uma rodada já encerrada. E sem o
         * teto curto, ele segurava a rodada de abertura sozinho: nos testes foram
         * 30 s (dois espelhos) para devolver zero fontes PT-BR, o que empurrava
         * os provedores por identificador para fora do orçamento.
         */
        $timeout = $this->tempoDeConsulta(
            (int) config('services.torrents.acervo_mundial_tempo_limite', 8)
        );

        if ($timeout <= 0) {
            return [];
        }

        /*
         * O `connectTimeout` é o que faz o teto valer de verdade. O `timeout()`
         * sozinho limita a resposta, mas não a fase de conexão: quando o espelho
         * está bloqueado, o TCP fica pendurado no handshake e o Guzzle espera
         * muito além do teto — foi assim que o BT4G gastou 36 s com o teto em
         * 8 s. Com o corte na conexão, um espelho morto falha rápido e a vez
         * volta para quem ainda tem tempo.
         */
        $conexao = max(1, min(3, $timeout));

        $respostas = Http::pool(fn ($pool) => array_map(
            fn (string $termo) => $pool->as(md5($termo))
                ->baseUrl($base)
                ->withUserAgent($this->navegador())
                ->connectTimeout($conexao)
                ->timeout($timeout)
                ->get('/search', [
                    'q' => TermosBusca::limpar($termo),
                    'orderby' => 'seeders',
                ]),
            $termos
        ));

        $htmls = [];
        $respondeu = false;

        foreach ($termos as $termo) {
            $resposta = $respostas[md5($termo)] ?? null;

            // O pool devolve a exceção no lugar da resposta quando a conexão
            // falha; só seguimos com respostas HTTP de fato.
            if (! $resposta instanceof Response) {
                continue;
            }

            /*
             * Qualquer resposta HTTP — inclusive o 403 do Cloudflare — conta como
             * "o espelho está de pé". É essa distinção que decide se vale abrir o
             * navegador do FlareSolverr: um 403 prova que o Cloudflare barrou o
             * acesso direto, e o FlareSolverr **também** não passa por ele (nos
             * testes ele devolveu 500 depois de ~12 s). Já a ausência total de
             * resposta aponta para um bloqueio de rede, que o navegador pode
             * contornar.
             */
            $respondeu = true;

            if ($resposta->failed()) {
                continue;
            }

            $htmls[] = $resposta->body();
        }

        /*
         * O socorro pelo FlareSolverr só entra quando **nenhuma** resposta HTTP
         * chegou — sinal de bloqueio de rede, não de Cloudflare. Quando o pool
         * devolveu 403, o navegador não ajudaria: ele esbarra no mesmo desafio e
         * ainda gasta ~12 s do orçamento para devolver 500. Era esse o custo que
         * fazia o BT4G segurar a busca inteira sem entregar nada.
         *
         * O socorro é de **um termo só**, e não termo a termo: cada chamada abre
         * um navegador, e o primeiro termo (o título puro, sem a tag de idioma) é
         * o mais promissor. Se o espelho estiver bloqueado, os termos seguintes
         * também estariam — insistir só repetiria a espera.
         */
        if ($htmls === [] && ! $respondeu && $this->cliente->proxyDisponivel()) {
            $resposta = $this->cliente->get(
                $base.'/search',
                ['q' => TermosBusca::limpar($termos[0]), 'orderby' => 'seeders'],
                $this->navegador(),
                $timeout,
            );

            if ($resposta !== null && ! $resposta->failed()) {
                $htmls[] = $resposta->body();
            }
        }

        return $htmls;
    }

    /**
     * Varre o HTML atrás de links de magnet.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extrair(string $html, ?int $temporada = null, ?int $episodio = null): array
    {
        $documento = $this->carregarHtml($html);

        if ($documento === null) {
            return [];
        }

        $xpath = new \DOMXPath($documento);

        $links = $xpath->query(
            '//a[contains(@href, "/magnet/") or starts-with(@href, "magnet:")]'
        );

        $fontes = [];
        $vistos = [];

        foreach ($links as $link) {
            /** @var \DOMElement $link */
            $hash = $this->hashDoLink($link->getAttribute('href'));

            if ($hash === '' || isset($vistos[$hash])) {
                continue;
            }

            $titulo = $this->limparTexto($link->textContent);

            if ($titulo === '') {
                continue;
            }

            /*
             * O BT4G agrega o DHT inteiro, então a busca por nome traz temporadas
             * vizinhas. A peneira descarta o que declara numeração diferente da
             * pedida — sem ela, um "S10E01" dublado poderia se passar por
             * "S01E01" e ser a primeira fonte tentada pelo player.
             */
            if ($temporada !== null && $episodio !== null
                && ! TermosBusca::correspondeAoEpisodio($titulo, $temporada, $episodio)) {
                continue;
            }

            $contexto = $this->textoDoContexto($link);

            $vistos[$hash] = true;

            $fontes[] = $this->montarFonte([
                'id' => $hash,
                'titulo' => $titulo,
                'magnet' => $this->magnetDoHash($hash, $titulo),
                'tamanho_bytes' => $this->tamanhoDoTexto($contexto),
                'seeds' => $this->seedsDoTexto($contexto),
                'peers' => 0,
                // Sem campo de idioma próprio: o BT4G não informa, então quem
                // decide é a dedução pela tag do nome, feita em montarFonte().
            ], $this->identificador(), $this->rotulo());
        }

        return $fontes;
    }

    /**
     * Extrai o infohash tanto do `magnet:` completo quanto do caminho
     * `/magnet/<hash>` usado pelo site.
     */
    private function hashDoLink(string $href): string
    {
        if (preg_match('/magnet:\?xt=urn:btih:([A-Za-z0-9]{32,40})/', $href, $achados)) {
            return strtolower($achados[1]);
        }

        if (preg_match('#/magnet/([A-Za-z0-9]{32,40})#', $href, $achados)) {
            return strtolower($achados[1]);
        }

        return '';
    }

    /**
     * Junta o texto do link e dos ancestrais próximos.
     *
     * Tamanho e seeds ficam em elementos irmãos do link, não dentro dele. Subir
     * três níveis alcança o cartão do resultado inteiro sem chegar ao container
     * da página, onde os números de outros resultados contaminariam a leitura.
     */
    private function textoDoContexto(\DOMElement $link): string
    {
        $texto = $link->textContent;
        $no = $link->parentNode;

        for ($nivel = 0; $nivel < 3 && $no instanceof \DOMElement; $nivel++) {
            $texto .= ' '.$no->textContent;
            $no = $no->parentNode;
        }

        return $this->limparTexto($texto);
    }

    /** Lê o tamanho do contexto ("1.4 GB", "700 MiB"). */
    private function tamanhoDoTexto(string $texto): ?int
    {
        if (preg_match('/\b([\d.,]+\s*[KMGT]?i?B)\b/i', $texto, $achados)) {
            return $this->tamanhoEmBytes($achados[1]);
        }

        return null;
    }

    /**
     * Lê a contagem de seeds do contexto.
     *
     * Quando o site não publica a contagem, devolvemos o piso de seeds não
     * medidos: sem ele a fonte seria descartada como morta, e uma fonte PT-BR
     * plausível vale mais que um número faltando.
     */
    private function seedsDoTexto(string $texto): int
    {
        if (preg_match('/\b(\d+)\s*(?:seeders?|seeds|semead(?:or|ores))\b/i', $texto, $achados)) {
            return (int) $achados[1];
        }

        return self::SEEDS_NAO_MEDIDOS;
    }

    /** @return array<int, string> */
    private function espelhos(): array
    {
        $bruto = (string) config('services.torrents.bt4g_urls', 'https://bt4gprx.com');

        return array_values(array_filter(array_map(
            fn (string $url) => rtrim(trim($url), '/'),
            explode(',', $bruto)
        )));
    }

    /** Cria o DOM a partir do HTML cru, tolerando marcação quebrada. */
    private function carregarHtml(string $html): ?\DOMDocument
    {
        if (trim($html) === '') {
            return null;
        }

        $anterior = libxml_use_internal_errors(true);

        try {
            $documento = new \DOMDocument();

            // O prefixo com a declaração de encoding força a interpretação em
            // UTF-8; sem ele o parser assume Latin-1 e os títulos com acento
            // chegam corrompidos, quebrando a dedução de idioma.
            $documento->loadHTML(
                '<?xml encoding="UTF-8">'.$html,
                LIBXML_NOWARNING | LIBXML_NOERROR
            );

            $documento->encoding = 'UTF-8';

            return $documento;
        } catch (\Throwable) {
            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
        }
    }

    /** Evita o bloqueio por user-agent vazio, que vários trackers aplicam. */
    private function navegador(): string
    {
        return (string) config(
            'services.torrents.user_agent',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36'
        );
    }
}
