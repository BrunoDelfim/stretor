<?php

namespace App\Services\Torrents;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * Resolve páginas candidatas a partir de um termo, usando motores de busca leves.
 *
 * O scraper de stream direto precisa de uma lista de URLs de páginas de streaming
 * para abrir. Ele não tem um catálogo próprio nem uma API: quem sabe onde as
 * páginas estão é o motor de busca. Este serviço é a ponte — recebe um termo e
 * devolve os endereços que o motor apontou.
 *
 * O motor é o **SearXNG interno do compose**, consultado em
 * `http://searxng:8080/search` com `format=json`. Ele sobe junto com o stack, tem
 * cota própria e devolve JSON limpo — não depende de instância pública nem de
 * terceiros, e não sofre o bloqueio por IP que os buscadores comerciais aplicam
 * aos containers. O DuckDuckGo HTML/Lite foi removido do projeto: além de
 * bloquear o IP dos containers com status 202, exigia parsing de HTML e detecção
 * de captcha que só existiam para contornar o bloqueio.
 *
 * Cada endereço em `stream_direto_motores` é consultado por um **tipo** de motor,
 * e o tipo decide como montar a requisição e como ler a resposta:
 *
 * - `searxng` — instância SearXNG (interna ou pública), com `format=json`.
 *   Resultados em `results[].url`.
 * - `brave` — API oficial do Brave Search, exige chave (`stream_direto_brave_key`).
 *   Resultados em `web.results[].url`.
 *
 * O tipo é inferido do endereço quando não declarado explicitamente: um host com
 * `searx` vira `searxng`, um host com `brave` vira `brave`. Também dá para forçar
 * o tipo com o prefixo `tipo:url` na lista de motores.
 *
 * Com mais de um endereço, o serviço **percorre todos** e junta o que cada um
 * devolveu: um motor que respondeu com poucos resultados não impede o outro de
 * contribuir. E um motor que falha não consome o orçamento dos termos seguintes —
 * ele é apenas pulado, e o próximo endereço assume.
 *
 * A leitura é sempre por `json_decode`, que é barato e não depende de parser de
 * DOM: os motores devolvem JSON estruturado, então não há HTML para raspar.
 */
class MotorBuscaWeb
{
    use ConsultaComOrcamento;

    /**
     * Domínios que nunca têm vídeo extraível e só gastam orçamento.
     *
     * O motor de busca devolve, para qualquer título conhecido, uma fileira de
     * páginas de catálogo e metadados — JustWatch, IMDb, Plex, YouTube oficial,
     * Wikipédia, TMDB. Elas ranqueiam alto porque são autoritativas, mas nenhuma
     * serve ao scraper: o JustWatch lista onde assistir (não hospeda o vídeo), o
     * IMDb é ficha técnica, o Plex é catálogo de assinatura, o YouTube oficial
     * traz trailer, e a Wikipédia/TMDB são verbete. Abrir cada uma custa uma
     * requisição e uma espera para, no fim, o extrator não achar nada.
     *
     * O descarte é por sufixo de domínio, e não por substring: `imdb.com` precisa
     * barrar `www.imdb.com` e `m.imdb.com`, mas **não** um hipotético
     * `naoimdb.com`. A comparação é feita sobre o host, com o ponto à frente.
     */
    private const DOMINIOS_IGNORADOS = [
        'justwatch.com',
        'imdb.com',
        'plex.tv',
        'youtube.com',
        'youtu.be',
        'wikipedia.org',
        'tmdb.org',
        'themoviedb.org',
    ];

    /**
     * Motores padrão, na ordem em que são tentados.
     *
     * O SearXNG interno do compose é o único motor padrão: ele sobe junto com o
     * stack, tem cota própria, devolve JSON limpo e não depende de terceiros. Se
     * um dia for preciso redundância, ela deve vir de outra instância SearXNG —
     * nunca de um buscador comercial que bloqueia o IP dos containers.
     *
     * O endereço é só o endpoint de busca (`http://searxng:8080/search`) — a
     * query e o `format=json` entram na hora da requisição. O host interno
     * `searxng` contém `searx`, então `tipoDoMotor()` o reconhece como SearXNG
     * sem precisar do prefixo `tipo:url`.
     */
    private const MOTORES_PADRAO = [
        'http://searxng:8080/search',
    ];

    public function __construct(
        private readonly OrcamentoBusca $orcamento,
        private readonly ClienteHttp $cliente,
    ) {
    }

    /**
     * Busca um termo e devolve as URLs de resultado, na ordem em que vieram.
     *
     * Percorre todos os motores configurados e agrega os resultados. Um motor que
     * falha (rede, erro HTTP, JSON inválido) não impede os outros: a busca
     * degrada, não quebra. Quando um motor falha, o próximo assume — e a falha
     * não consome o orçamento dos termos seguintes.
     *
     * @return array<int, string>
     */
    public function procurar(string $termo): array
    {
        $termo = trim($termo);

        if ($termo === '') {
            return [];
        }

        $motores = $this->motores();

        Log::debug('Stream direto: consultando motores de busca.', [
            'termo' => $termo,
            'motores' => $motores,
        ]);

        $urls = [];
        $primeiraConsulta = true;

        foreach ($motores as $motor) {
            if (! $this->temOrcamento()) {
                Log::debug('Stream direto: orçamento esgotado durante a busca.', [
                    'termo' => $termo,
                    'motor' => $motor,
                ]);

                break;
            }

            /*
             * A espera vale entre consultas, não antes da primeira: atrasar a
             * abertura da busca só somaria latência sem proteger nada. Com mais
             * de um motor, a rajada é o que os buscadores punem.
             */
            if (! $primeiraConsulta) {
                $this->aguardarIntervalo();
            }

            $primeiraConsulta = false;

            $encontradas = $this->consultarMotor($motor, $termo);

            Log::debug('Stream direto: motor respondeu.', [
                'termo' => $termo,
                'motor' => $motor,
                'links' => count($encontradas),
            ]);

            /*
             * Um motor que falhou devolve zero links, mas não é o mesmo que "não
             * achou nada": o próximo endereço da lista ainda pode responder. O
             * laço segue em frente em vez de parar — é o fallback entre motores.
             */
            $urls = array_merge($urls, $encontradas);
        }

        $urls = array_values(array_unique($urls));

        Log::debug('Stream direto: busca concluída.', [
            'termo' => $termo,
            'total_de_links' => count($urls),
        ]);

        return $urls;
    }

    /**
     * Consulta um motor e extrai os links de resultado.
     *
     * O tipo do motor decide a rota: o SearXNG e o Brave montam a requisição de
     * um jeito e leem a resposta de outro, mas ambos devolvem JSON. Uma falha
     * devolve lista vazia e o chamador tenta o próximo.
     *
     * @return array<int, string>
     */
    private function consultarMotor(string $motor, string $termo): array
    {
        $teto = $this->tempoDeConsulta((int) config('services.torrents.stream_direto_tempo_limite', 10));

        if ($teto <= 0) {
            return [];
        }

        $tipo = $this->tipoDoMotor($motor);

        try {
            $resposta = $this->requisitar($tipo, $motor, $termo, $teto);
        } catch (\Throwable $excecao) {
            Log::warning('Stream direto: falha ao consultar o motor de busca.', [
                'motor' => $motor,
                'tipo' => $tipo,
                'termo' => $termo,
                'erro' => $excecao->getMessage(),
            ]);

            return [];
        }

        if ($resposta === null) {
            Log::warning('Stream direto: motor não respondeu (sem tempo ou sem rede).', [
                'motor' => $motor,
                'tipo' => $tipo,
                'termo' => $termo,
            ]);

            return [];
        }

        if ($resposta->failed()) {
            Log::warning('Stream direto: motor devolveu erro HTTP.', [
                'motor' => $motor,
                'tipo' => $tipo,
                'termo' => $termo,
                'status' => $resposta->status(),
            ]);

            return [];
        }

        /*
         * A validação do corpo é o próprio `json_decode`: um corpo que não é JSON
         * (página de erro, HTML de bloqueio) já devolve lista vazia. Não há mais
         * varredura por marcas de bloqueio nem tratamento de status 202 — isso
         * existia só para o HTML do DuckDuckGo, que respondia 200 com página de
         * captcha no corpo.
         */
        return $this->extrairResultadosJson((string) $resposta->body());
    }

    /**
     * Monta a requisição conforme o tipo do motor.
     *
     * O SearXNG recebe o termo por query string (`q`) e pede `format=json` para
     * devolver JSON em vez de HTML. O Brave também recebe `q`, mas exige o
     * cabeçalho de chave.
     */
    private function requisitar(string $tipo, string $motor, string $termo, int $teto): ?Response
    {
        return match ($tipo) {
            'brave' => $this->cliente->get(
                $motor,
                ['q' => $termo],
                $this->navegador(),
                $teto,
                $this->cabecalhosBrave()
            ),
            default => $this->cliente->get(
                $motor,
                ['q' => $termo, 'format' => 'json'],
                $this->navegador(),
                $teto
            ),
        };
    }

    /**
     * Cabeçalhos da API do Brave Search.
     *
     * A API oficial exige a chave em `X-Subscription-Token` e o `Accept` de JSON.
     * Sem a chave configurada, o motor é pulado antes de chegar aqui.
     *
     * @return array<string, string>
     */
    private function cabecalhosBrave(): array
    {
        return [
            'Accept' => 'application/json',
            'X-Subscription-Token' => (string) config('services.torrents.stream_direto_brave_key', ''),
        ];
    }

    /**
     * Descobre o tipo de um motor a partir do endereço ou do prefixo declarado.
     *
     * O prefixo `tipo:url` vence sempre — é o jeito de forçar um tipo quando o
     * host não denuncia (uma instância SearXNG em domínio próprio, por exemplo).
     * Sem prefixo, o host decide: `searx` vira SearXNG, `brave` vira Brave. Um
     * endereço que não casa com nenhum dos dois é tratado como SearXNG, que é o
     * motor padrão do projeto.
     */
    private function tipoDoMotor(string $motor): string
    {
        if (str_contains($motor, ':')) {
            [$possivelTipo, $resto] = explode(':', $motor, 2);

            if (in_array($possivelTipo, ['searxng', 'brave'], true) && $resto !== '') {
                return $possivelTipo;
            }
        }

        $host = strtolower((string) parse_url($motor, PHP_URL_HOST));

        if (str_contains($host, 'brave')) {
            return 'brave';
        }

        return 'searxng';
    }

    /**
     * Extrai as URLs de resultado de uma resposta JSON (SearXNG ou Brave).
     *
     * O SearXNG devolve `{"results": [{"url": "..."}]}`; o Brave devolve
     * `{"web": {"results": [{"url": "..."}]}}`. Os dois formatos são aceitos, e a
     * lista negra de domínios vale igual.
     *
     * @return array<int, string>
     */
    private function extrairResultadosJson(string $corpo): array
    {
        if ($corpo === '') {
            return [];
        }

        $dados = json_decode($corpo, true);

        if (! is_array($dados)) {
            return [];
        }

        $itens = $dados['results'] ?? $dados['web']['results'] ?? [];

        if (! is_array($itens)) {
            return [];
        }

        $urls = [];
        $ignorados = [];

        foreach ($itens as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = trim((string) ($item['url'] ?? ''));

            if ($url === '' || ! preg_match('#^https?://#i', $url)) {
                continue;
            }

            if ($this->dominioIgnorado($url)) {
                $ignorados[] = $url;

                continue;
            }

            $urls[] = $url;
        }

        if ($ignorados !== []) {
            Log::debug('Stream direto: domínios de catálogo ignorados.', [
                'quantidade' => count($ignorados),
                'dominios' => array_values(array_unique(array_map(
                    static fn (string $url): string => (string) parse_url($url, PHP_URL_HOST),
                    $ignorados
                ))),
            ]);
        }

        return array_values(array_unique($urls));
    }

    /**
     * Espera um intervalo sorteado antes da próxima consulta ao motor.
     *
     * Buscadores bloqueiam rajadas: consultar os termos em sequência, sem pausa,
     * é o caminho mais curto para o rate limit. O atraso é sorteado entre o
     * mínimo e o máximo a cada consulta — uma cadência fixa também é padrão de
     * bot, e o sorteio imita o ritmo irregular de quem digita e clica.
     *
     * A espera nunca ultrapassa o orçamento restante: se o que sobra é menor que
     * o intervalo sorteado, dormir até o fim só atrasaria a resposta sem ganhar
     * consulta nenhuma. Nesse caso, o laço de chamada já vai parar pelo
     * `temOrcamento()`.
     */
    private function aguardarIntervalo(): void
    {
        $minimo = max(0, (int) config('services.torrents.stream_direto_intervalo_min', 800));
        $maximo = max($minimo, (int) config('services.torrents.stream_direto_intervalo_max', 2200));

        if ($maximo <= 0) {
            return;
        }

        $espera = random_int($minimo, $maximo);

        $restante = $this->orcamento->restante();

        if ($restante !== null) {
            $restanteMs = $restante * 1000;

            if ($restanteMs <= 0) {
                return;
            }

            $espera = min($espera, $restanteMs);
        }

        Log::debug('Stream direto: aguardando intervalo entre consultas.', [
            'ms' => $espera,
        ]);

        usleep($espera * 1000);
    }

    /**
     * User-Agent de navegador comum para as consultas ao motor de busca.
     *
     * Um User-Agent de robô (`GuzzleHttp/...`, `curl/...`) é o primeiro item que
     * um filtro anti-bot olha. O valor vem da mesma chave dos provedores nativos
     * (`services.torrents.user_agent`), para não haver dois agentes diferentes
     * no mesmo processo.
     */
    private function navegador(): string
    {
        $agente = (string) config('services.torrents.user_agent', '');

        // A chave pode existir e vir vazia (env ausente no ambiente de teste),
        // e nesse caso o `config()` devolve string vazia em vez do padrão. O
        // `?:` garante que o agente de navegador sempre valha.
        return $agente !== ''
            ? $agente
            : 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
    }

    /**
     * Diz se a URL pertence a um domínio de catálogo/metadados da lista negra.
     *
     * A checagem é pelo host, com o ponto à frente, para casar subdomínios
     * (`www.imdb.com`) sem casar domínios que apenas terminam com o mesmo texto
     * (`naoimdb.com`). URLs sem host válido não são descartadas aqui — quem
     * decide se servem é o extrator.
     */
    private function dominioIgnorado(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        foreach (self::DOMINIOS_IGNORADOS as $dominio) {
            if ($host === $dominio || str_ends_with($host, '.'.$dominio)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Motores de busca configurados, já normalizados.
     *
     * A lista vem de `stream_direto_motores`. Vazia, cai no motor padrão (o
     * SearXNG interno). O Brave só entra se houver chave configurada — sem ela, o
     * motor devolveria 401 e só gastaria orçamento.
     *
     * @return array<int, string>
     */
    private function motores(): array
    {
        $motores = config('services.torrents.stream_direto_motores', []);

        if (! is_array($motores) || $motores === []) {
            $motores = self::MOTORES_PADRAO;
        }

        $motores = array_values(array_filter(
            array_map(static fn ($endereco): string => trim((string) $endereco), $motores),
            static fn (string $endereco): bool => $endereco !== ''
        ));

        /*
         * O Brave sem chave é um motor morto: a API responde 401 e o endereço só
         * ocuparia uma volta do laço. Ele é descartado aqui, não na hora da
         * requisição, para o log de motores refletir o que de fato será tentado.
         */
        $temChaveBrave = (string) config('services.torrents.stream_direto_brave_key', '') !== '';

        $motores = array_values(array_filter(
            $motores,
            fn (string $endereco): bool => $this->tipoDoMotor($endereco) !== 'brave' || $temChaveBrave
        ));

        return $motores === [] ? self::MOTORES_PADRAO : $motores;
    }
}
