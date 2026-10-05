<?php

namespace App\Services\Torrents;

use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP dos provedores nativos, com socorro automático pelo FlareSolverr.
 *
 * O FlareSolverr sempre esteve no stack, mas só servia ao Prowlarr (degrau 2): o
 * backend o cadastrava como proxy dos indexadores e pronto. Os provedores nativos
 * do degrau 1 — BT4G, trackers PT-BR, APIBay, Knaben — falavam HTTP direto, e por
 * isso batiam de frente no Cloudflare. Foi assim que o BT4G passou a responder
 * `403` com o desafio do Cloudflare e os trackers brasileiros sumiram: o bloqueio
 * não é um erro do site, é uma barreira que o navegador do FlareSolverr atravessa
 * e o nosso `Http::get()` não.
 *
 * A ideia é a mesma que o Stremio usa por baixo dos panos: quando a porta da
 * frente fecha, entra-se pela porta dos fundos. Aqui, a porta dos fundos é o
 * FlareSolverr.
 *
 * O caminho é sempre o mesmo, em duas tentativas:
 *
 * 1. **Direto.** A requisição normal, rápida e sem custo. É o que resolve a
 *    maioria dos casos — APIBay e Knaben, por exemplo, nunca precisam de proxy.
 * 2. **Pelo FlareSolverr.** Só quando a direta falha de um jeito que cheira a
 *    bloqueio (status 403/429/503 ou corpo com a marca do desafio). O FlareSolverr
 *    abre um Chromium, resolve o desafio e devolve o HTML já liberado.
 *
 * O fallback é silencioso e opcional: sem `FLARESOLVERR_URL` configurada, o
 * cliente se comporta exatamente como o `Http` direto de antes. Nenhuma
 * configuração ausente derruba a busca — no pior caso, ela volta ao
 * comportamento antigo.
 */
class ClienteHttp
{
    /**
     * Orçamento compartilhado da busca em curso.
     *
     * O cliente HTTP não decide quando a busca acaba — quem decide é o catálogo.
     * Mas ele precisa saber quanto ainda resta para não começar um socorro pelo
     * FlareSolverr que já não cabe no prazo: uma espera de 70 s iniciada a 5 s do
     * fim é exatamente o que estoura o tempo do frontend. Com o orçamento em mãos,
     * o teto do proxy passa a ser o menor entre o configurado e o que sobra.
     */
    public function __construct(
        private readonly OrcamentoBusca $orcamento,
    ) {
    }

    /**
     * Quantas vezes a requisição direta é tentada antes do socorro.
     *
     * A queda de rede aqui é transitória com frequência — um blip de resolução,
     * uma conexão derrubada no handshake — e o `connectTimeout` de 3 s, que existe
     * para não pendurar a cascata, corta justamente o instante ruim. Medido no
     * container: a busca do agregador que responde em 0,6 s falhou por um blip e
     * foi entregue ao FlareSolverr, que devolveu erro 19 s depois e levou junto o
     * orçamento da busca inteira. Repetir a direta custa, no pior caso, mais um
     * `connectTimeout` — e evita o caminho caro que não conserta nada.
     */
    private const TENTATIVAS_DIRETAS = 2;

    /**
     * Custo real do socorro pelo FlareSolverr, acima do `maxTimeout` pedido.
     *
     * O `maxTimeout` cobre a espera pelo desafio, mas não o resto: abrir o
     * Chromium, navegar e devolver o DOM. Medido no container, uma chamada que
     * pedia 8 s levou **19,2 s** para responder. Chamar o socorro sem que o prazo
     * cubra esse total entrega o orçamento da busca a uma resposta que já chega
     * tarde — o resultado é descartado e as páginas seguintes ficam sem tempo.
     */
    private const CUSTO_DO_SOCORRO_SEGUNDOS = 20;

    /**
     * O socorro pelo FlareSolverr não está valendo nesta busca.
     *
     * O servidor responde `500` quando não vence o desafio dentro do `maxTimeout`,
     * e cada uma dessas respostas custa o custo real medido (`≈20 s`). Uma já é
     * prova de que o socorro não está entregando nesta rodada; insistir na página
     * seguinte gasta de novo o mesmo tempo para receber o mesmo erro — foi assim
     * que uma busca de 45 s terminou sem abrir a página certa.
     */
    private bool $socorroIndisponivel = false;

    /**
     * Status que denunciam bloqueio por Cloudflare, paywall ou rate limit.
     *
     * O 403 é o clássico do desafio; o 429 é o "calma lá" do rate limit; o 503
     * aparece quando o Cloudflare põe a página em modo de espera. O 402 (Payment
     * Required) entrou depois: os agregadores de vídeo que hospedam o conteúdo
     * raro respondem 402 quando o acesso é pago, e sem reconhecê-lo o provedor
     * lia a página como se fosse resultado legítimo — gastava o teto inteiro e a
     * descartava por "sem prova de mídia", sem nunca acionar o socorro. Nenhum
     * deles é resposta legítima de um site saudável, então valem a segunda
     * tentativa.
     */
    private const STATUS_DE_BLOQUEIO = [402, 403, 429, 503];

    /**
     * Marcas do desafio do Cloudflare no corpo da resposta.
     *
     * O status nem sempre denuncia: algumas versões do desafio respondem 200 com
     * a página "Just a moment..." no corpo. Sem esta checagem, o provedor leria
     * essa página como se fosse resultado de busca e devolveria zero fontes sem
     * entender por quê.
     */
    private const MARCAS_DO_DESAFIO = [
        'just a moment',
        'cf-chl',
        'cf_chl',
        'checking your browser',
        'attention required',
        'enable javascript and cookies',
    ];

    /**
     * Faz um GET, tentando direto e caindo para o FlareSolverr em caso de bloqueio.
     *
     * @param  array<string, mixed>  $consulta   Parâmetros de query string
     * @param  array<string, string>  $cabecalhos  Cabeçalhos extras (ex.: a chave da API do Brave)
     */
    public function get(
        string $url,
        array $consulta = [],
        ?string $userAgent = null,
        ?int $timeout = null,
        array $cabecalhos = [],
    ): ?Response {
        $timeout = $this->tempoDisponivel($timeout ?? (int) config('services.torrents.tempo_limite', 15));

        /*
         * Sem tempo restante não há requisição que caiba: devolvemos `null` para o
         * provedor seguir como se o site estivesse fora do ar. Começar uma tentativa
         * que já nasce fora do prazo só serviria para estourar o tempo do frontend.
         */
        if ($timeout <= 0) {
            return null;
        }

        $direta = $this->tentarDireto($url, $consulta, $userAgent, $timeout, $cabecalhos);

        if ($direta !== null && ! $this->pareceBloqueio($direta)) {
            return $direta;
        }

        /*
         * A direta falhou ou veio bloqueada. Se o FlareSolverr não estiver
         * configurado, devolvemos o que a direta trouxe — inclusive o bloqueio —
         * para o provedor decidir. Devolver `null` aqui apagaria a diferença
         * entre "site fora do ar" e "site bloqueado", que é justamente o que o
         * log precisa distinguir.
         */
        if (! $this->proxyDisponivel()) {
            return $direta;
        }

        $peloProxy = $this->tentarPeloProxy($url, $consulta, $timeout);

        if ($peloProxy !== null && ! $peloProxy->failed()) {
            Log::info('Provedor nativo liberado pelo FlareSolverr.', [
                'url' => $url,
                'status_direto' => $direta?->status(),
            ]);

            return $peloProxy;
        }

        return $direta;
    }

    /**
     * Faz um POST, com o mesmo socorro do GET.
     *
     * O Knaben é o caso de uso: a API dele é um POST JSON, e ele também pode
     * ficar atrás de bloqueio. O corpo é reenviado intacto ao FlareSolverr.
     *
     * Os cabeçalhos extras existem para os endpoints que se comportam de maneira
     * diferente conforme quem pede — o `getVideo` do FirePlayer devolve o JSON do
     * player para uma chamada de AJAX e a própria página do embed para o resto.
     * Sem o `X-Requested-With`, o resolvedor de embeds recebia HTML onde esperava
     * dados.
     *
     * @param  array<string, mixed>  $corpo
     * @param  array<string, string>  $cabecalhos  Cabeçalhos extras, mesclados por cima dos de navegador
     */
    public function post(string $url, array $corpo = [], ?string $userAgent = null, ?int $timeout = null, array $cabecalhos = []): ?Response
    {
        $timeout = $this->tempoDisponivel($timeout ?? (int) config('services.torrents.tempo_limite', 15));

        if ($timeout <= 0) {
            return null;
        }

        $direta = $this->tentarDiretoPost($url, $corpo, $userAgent, $timeout, $cabecalhos);

        if ($direta !== null && ! $this->pareceBloqueio($direta)) {
            return $direta;
        }

        if (! $this->proxyDisponivel()) {
            return $direta;
        }

        $peloProxy = $this->tentarPeloProxyPost($url, $corpo, $timeout);

        if ($peloProxy !== null && ! $peloProxy->failed()) {
            Log::info('Provedor nativo liberado pelo FlareSolverr (POST).', [
                'url' => $url,
                'status_direto' => $direta?->status(),
            ]);

            return $peloProxy;
        }

        return $direta;
    }

    /**
     * Faz um GET forçando a passagem pelo navegador do FlareSolverr.
     *
     * O `get()` comum só aciona o proxy quando fareja bloqueio. Isso resolve o
     * Cloudflare, mas não resolve o outro tipo de página que o stream direto
     * encontra: o site que responde 200 com um HTML estático **sem player**,
     * porque o player só é montado depois, por JavaScript — uma chamada AJAX ao
     * `player-resolve`, um `eval` que injeta o `<iframe>`, um `data-*` que o
     * script lê e transforma em vídeo. O `Http::get()` nunca executa esse script,
     * então a prova de mídia falha e a página legítima é descartada como se não
     * tivesse vídeo.
     *
     * Aqui a página é entregue ao Chromium do FlareSolverr, que roda o JavaScript
     * até o player aparecer e devolve o DOM já montado. É o mesmo caminho do
     * socorro contra bloqueio, só que sem esperar por um bloqueio para começar:
     * quem chama já sabe que a versão estática não bastou.
     *
     * Devolve `null` quando o proxy não está configurado ou não conseguiu
     * renderizar — o provedor então fica com o HTML estático que já tinha.
     */
    public function getRenderizado(string $url, ?int $timeout = null): ?Response
    {
        if (! $this->proxyDisponivel()) {
            return null;
        }

        $timeout = $this->tempoDisponivel($timeout ?? (int) config('services.torrents.tempo_limite', 15));

        if ($timeout <= 0) {
            return null;
        }

        $renderizada = $this->chamarFlareSolverr([
            'cmd' => 'request.get',
            'url' => $url,
            'maxTimeout' => $timeout * 1000,
        ]);

        if ($renderizada !== null && ! $renderizada->failed()) {
            Log::info('Stream direto: página renderizada pelo FlareSolverr.', ['url' => $url]);

            return $renderizada;
        }

        return null;
    }

    /**
     * Diz se o FlareSolverr está configurado e pode ser usado como socorro.
     */
    public function proxyDisponivel(): bool
    {
        return (bool) config('services.torrents.proxy_nativo', true)
            && $this->flaresolverrUrl() !== '';
    }

    /**
     * Encolhe o teto de uma requisição para o que ainda resta do orçamento.
     *
     * O `tempo_limite` de cada provedor é pensado para uma busca isolada, não para
     * a soma da cascata. Sem este corte, uma tentativa direta iniciada a 3 s do fim
     * ainda esperaria os 15 s cheios — e é essa espera que faz o axios abortar com
     * a resposta a caminho. Devolvemos o menor entre o teto pedido e o restante;
     * zero ou menos significa que não há mais tempo para nenhuma requisição.
     */
    private function tempoDisponivel(int $teto): int
    {
        $restante = $this->orcamento->restante();

        if ($restante === null) {
            return $teto;
        }

        return min($teto, $restante);
    }

    /**
     * Requisição direta, absorvendo a exceção de rede.
     *
     * Um provedor fora do ar não pode derrubar a cascata: a exceção vira `null` e
     * quem chamou decide o que fazer (normalmente, tentar o proxy).
     *
     * @param  array<string, mixed>  $consulta
     * @param  array<string, string>  $cabecalhos
     */
    private function tentarDireto(
        string $url,
        array $consulta,
        ?string $userAgent,
        int $timeout,
        array $cabecalhos = [],
    ): ?Response {
        for ($tentativa = 1; ; $tentativa += 1) {
            try {
                $requisicao = $this->requisicao($userAgent, $timeout, $cabecalhos);

                /*
                 * O `get()` do Laravel recebe a query num segundo argumento e o Guzzle
                 * a usa para **substituir** a query embutida na URL. Passar um array
                 * vazio, então, não é inócuo: ele apaga o `?q=...` do endereço e o site
                 * devolve a própria home — a busca volta vazia sem erro nenhum. Era o
                 * que acontecia com os trackers PT-BR e com a busca direta dos
                 * agregadores (`verpobreflix.net/search?q=...` chegava como `/search`).
                 * Quando não há parâmetros, a requisição sai com um argumento só e a
                 * query da URL fica intacta.
                 */
                return $consulta === []
                    ? $requisicao->get($url)
                    : $requisicao->get($url, $consulta);
            } catch (\Throwable $excecao) {
                /*
                 * A repetição vale só para o fracasso que **não chegou a ser
                 * resposta**: um servidor que devolveu 403 ou desafio não melhora
                 * na segunda vez, e insistir gastaria orçamento à toa. Também só
                 * repete se o prazo ainda cobrir a nova tentativa inteira.
                 */
                if ($tentativa >= self::TENTATIVAS_DIRETAS || ! $this->cabeRepetir($timeout)) {
                    Log::debug('Cliente HTTP: tentativa direta não completou.', [
                        'url' => $url,
                        'tentativas' => $tentativa,
                        'erro' => $excecao->getMessage(),
                    ]);

                    return null;
                }
            }
        }
    }

    /**
     * Diz se o orçamento ainda cobre uma nova tentativa direta inteira.
     *
     * Repetir ajuda quando a falha foi do caminho (blip de DNS, handshake
     * derrubado), mas não quando o prazo já não comporta outra conexão: aí a
     * segunda volta nasceria perdida e ainda atrasaria o socorro ou a próxima
     * página.
     */
    private function cabeRepetir(int $timeout): bool
    {
        $restante = $this->orcamento->restante();

        return $restante === null || $restante > $timeout;
    }

    /**
     * @param  array<string, mixed>  $corpo
     * @param  array<string, string>  $cabecalhos
     */
    private function tentarDiretoPost(string $url, array $corpo, ?string $userAgent, int $timeout, array $cabecalhos = []): ?Response
    {
        try {
            return $this->requisicao($userAgent, $timeout, $cabecalhos)->asJson()->post($url, $corpo);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Monta a requisição base, com o User-Agent de navegador que os trackers
     * exigem — sem ele, vários respondem 403 antes mesmo de olhar o conteúdo.
     *
     * @param  array<string, string>  $cabecalhos  Cabeçalhos extras, mesclados por cima dos de navegador
     */
    private function requisicao(?string $userAgent, int $timeout, array $cabecalhos = []): PendingRequest
    {
        /*
         * O `connectTimeout` corta a fase de conexão, que o `timeout()` não
         * cobre: com o host bloqueado, o TCP fica pendurado no handshake e o
         * Guzzle espera muito além do teto. Com o corte, um host morto falha
         * rápido e o socorro pelo FlareSolverr assume sem esperar o handshake
         * inteiro.
         */
        return Http::withUserAgent($userAgent ?? $this->navegador())
            ->withHeaders(array_merge($this->cabecalhosDeNavegador(), $cabecalhos))
            ->connectTimeout(max(1, min(3, $timeout)))
            ->timeout($timeout)
            ->withOptions(['allow_redirects' => ['max' => 5]]);
    }

    /**
     * Cabeçalhos que um navegador comum manda e um cliente HTTP cru não.
     *
     * O User-Agent sozinho não basta: filtros anti-bot cruzam o agente com os
     * cabeçalhos de aceitação. Um `Accept-Language` ausente, ou um `Accept`
     * genérico, denuncia o robô mesmo com o agente certo. O `pt-BR` também tem
     * efeito prático: os motores de busca devolvem resultados na variante
     * brasileira, que é o que o fallback procura.
     *
     * @return array<string, string>
     */
    private function cabecalhosDeNavegador(): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'pt-BR,pt;q=0.9,en;q=0.8',
            'Cache-Control' => 'no-cache',
            'Pragma' => 'no-cache',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => 'none',
            'Upgrade-Insecure-Requests' => '1',
        ];
    }

    /**
     * Reenvia o GET pelo FlareSolverr.
     *
     * O contrato é `POST {base}/v1` com `cmd: request.get` e a URL alvo. A
     * resposta vem em `solution.response`, já com o desafio resolvido. Quando o
     * alvo tem query string, ela vai embutida na própria URL — o FlareSolverr não
     * aceita parâmetros separados.
     *
     * @param  array<string, mixed>  $consulta
     */
    private function tentarPeloProxy(string $url, array $consulta, int $timeout): ?Response
    {
        $alvo = $consulta === [] ? $url : $url.'?'.http_build_query($consulta);

        return $this->chamarFlareSolverr([
            'cmd' => 'request.get',
            'url' => $alvo,
            'maxTimeout' => $timeout * 1000,
        ]);
    }

    /**
     * Reenvia o POST pelo FlareSolverr.
     *
     * O `request.post` aceita o corpo em `postData` (string). Mandamos o JSON
     * serializado, que é o que o Knaben espera.
     *
     * @param  array<string, mixed>  $corpo
     */
    private function tentarPeloProxyPost(string $url, array $corpo, int $timeout): ?Response
    {
        return $this->chamarFlareSolverr([
            'cmd' => 'request.post',
            'url' => $url,
            'postData' => json_encode($corpo, JSON_UNESCAPED_UNICODE),
            'maxTimeout' => $timeout * 1000,
        ]);
    }

    /**
     * Executa a chamada ao FlareSolverr e traduz a resposta para o contrato do
     * Laravel, para que o provedor não precise saber que houve proxy.
     *
     * @param  array<string, mixed>  $pedido
     */
    private function chamarFlareSolverr(array $pedido): ?Response
    {
        /*
         * Uma falha já encerra as tentativas de socorro desta busca: cada resposta
         * `500` custa o custo real medido (`≈20 s`) e devolve a mesma coisa. Insistir
         * é o que fazia duas páginas consumirem os 45 s do orçamento e a busca
         * terminar sem fonte.
         */
        if ($this->socorroIndisponivel) {
            return null;
        }

        $base = rtrim($this->flaresolverrUrl(), '/');

        /*
         * O FlareSolverr abre um navegador e resolve o desafio, o que leva bem
         * mais que uma requisição comum. O teto fica acima do `maxTimeout` que
         * enviamos a ele, para que o erro venha dele (com diagnóstico) e não de
         * um corte nosso.
         */
        $teto = (int) config('services.torrents.proxy_nativo_timeout', 70);

        /*
         * O socorro não pode durar mais do que resta da busca. Sem este corte, o
         * FlareSolverr começaria uma espera de até 70 s mesmo a poucos segundos do
         * fim do orçamento — e é essa espera que estoura o tempo do frontend. O
         * `maxTimeout` enviado a ele também encolhe, para que o navegador dele
         * desista junto com a gente, em vez de continuar trabalhando à toa.
         */
        $restante = $this->orcamento->restante();

        if ($restante !== null) {
            if ($restante <= 0) {
                return null;
            }

            /*
             * O socorro só vale a pena se o prazo cobrir o **custo real** dele, e
             * não apenas o `maxTimeout` que ele recebe: medido no container, uma
             * chamada que pedia 8 s levou 19,2 s para responder, porque o Chromium
             * ainda sobe depois da espera pelo desafio. Sem esta conta, o socorro
             * de uma página consumia o orçamento de todas as outras e a busca
             * terminava sem abrir a página certa.
             */
            $necessario = (int) ceil(((int) ($pedido['maxTimeout'] ?? 0)) / 1000) + self::CUSTO_DO_SOCORRO_SEGUNDOS;

            if ($restante < $necessario) {
                Log::debug('FlareSolverr: socorro não cabe no orçamento restante.', [
                    'url' => $pedido['url'] ?? '',
                    'restante' => $restante,
                    'necessario' => $necessario,
                ]);

                return null;
            }

            $teto = min($teto, $restante);
            $pedido['maxTimeout'] = min((int) ($pedido['maxTimeout'] ?? $teto * 1000), $teto * 1000);
        }

        try {
            $resposta = Http::timeout($teto)
                ->acceptJson()
                ->post($base.'/v1', $pedido);
        } catch (\Throwable $excecao) {
            $this->socorroIndisponivel = true;

            Log::info('FlareSolverr indisponível para o provedor nativo.', [
                'url' => $pedido['url'] ?? '',
                'motivo' => $excecao->getMessage(),
            ]);

            return null;
        }

        if ($resposta->failed()) {
            $this->socorroIndisponivel = true;

            return null;
        }

        $corpo = $resposta->json();

        if (! is_array($corpo) || ($corpo['status'] ?? '') !== 'ok') {
            Log::info('FlareSolverr não resolveu o desafio.', [
                'url' => $pedido['url'] ?? '',
                'mensagem' => $corpo['message'] ?? 'sem mensagem',
            ]);

            return null;
        }

        $html = (string) ($corpo['solution']['response'] ?? '');

        if ($html === '') {
            return null;
        }

        /*
         * Reconstruímos uma `Response` do Laravel a partir do HTML que o
         * FlareSolverr devolveu. Assim o provedor continua lendo `->body()` e
         * `->json()` como sempre, sem um caminho paralelo só para o proxy.
         *
         * A montagem é feita direto sobre a `Response` do PSR-7, e não pelo
         * `Http::response()`. O factory do Laravel pode estar com um handler
         * assíncrono (ou um `Http::fake()` residual de teste), e nesse caso ele
         * devolve uma `FulfilledPromise` em vez da `Response` — foi o que
         * derrubou páginas legítimas como o `assistaonline.tv` com o erro
         * "Return value must be of type ?Response, FulfilledPromise returned".
         * Construir a resposta na mão elimina essa dependência do estado global
         * do cliente HTTP.
         */
        return new Response(new Psr7Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $html));
    }

    /**
     * Diz se a resposta veio bloqueada, para quem chamou decidir o descarte.
     *
     * O provedor precisa dessa resposta antes de tentar extrair mídia: uma página
     * com 402/403 não tem player para achar, e varrê-la só gasta o orçamento. O
     * método é público porque a decisão de abandonar é do provedor, não do
     * cliente — o cliente só sabe ler o status.
     */
    public function bloqueada(Response $resposta): bool
    {
        return $this->pareceBloqueio($resposta);
    }

    /**
     * Diz se a resposta tem cara de bloqueio do Cloudflare.
     *
     * Olha o status e, quando ele não denuncia, o começo do corpo — o desafio
     * costuma vir com 200 e a página "Just a moment...".
     */
    private function pareceBloqueio(Response $resposta): bool
    {
        if (in_array($resposta->status(), self::STATUS_DE_BLOQUEIO, true)) {
            return true;
        }

        // Só vale a pena varrer o corpo quando a resposta não é um sucesso claro.
        if ($resposta->successful() && ! $this->temMarcaDeDesafio($resposta->body())) {
            return false;
        }

        return $this->temMarcaDeDesafio($resposta->body());
    }

    private function temMarcaDeDesafio(string $corpo): bool
    {
        // O desafio mora no começo do documento; varrer o HTML inteiro de um
        // resultado grande seria desperdício.
        $amostra = mb_strtolower(mb_substr($corpo, 0, 4000));

        foreach (self::MARCAS_DO_DESAFIO as $marca) {
            if (str_contains($amostra, $marca)) {
                return true;
            }
        }

        return false;
    }

    private function flaresolverrUrl(): string
    {
        return trim((string) config('services.prowlarr.flaresolverr_url', ''));
    }

    private function navegador(): string
    {
        return (string) config(
            'services.torrents.user_agent',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36'
        );
    }
}
