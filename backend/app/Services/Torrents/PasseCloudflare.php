<?php

namespace App\Services\Torrents;

use Illuminate\Support\Facades\Cache;

/**
 * O passe do Cloudflare para os hosts com **desafio embutido**.
 *
 * O `superflixapi.quest` não usa o interstício clássico do Cloudflare — aquele
 * `Just a moment...`, que o FlareSolverr reconhece e resolve sozinho. A página
 * do episódio chega com `x-cloudflare-captcha: required` e um formulário que
 * **publica de volta na própria URL** carregando `cf_embed_challenge`,
 * `cf_embed_hash`, `cf_client_mobile` e `cf-turnstile-response`: é o desafio
 * embutido do Cloudflare, resolvido por um widget Turnstile montado dentro da
 * página do site.
 *
 * Nenhum servidor vence esse widget. O FlareSolverr sequer o **reconhece** — o
 * log dele registra `Challenge not detected!` e devolve a casca do desafio em
 * segundos —, e o widget costuma exigir um clique humano. Quem vence é o
 * navegador do usuário, e o prêmio é um passe com prazo: o próprio host anuncia
 * `x-cloudflare-captcha-ttl-minutes: 45` na resposta.
 *
 * O detalhe que faz isso valer para o backend é o **IP**: navegador e backend
 * saem pela mesma conexão, e o passe vale para ela. Este serviço é a ponte entre
 * os dois — entrega os cabeçalhos e o token com que a requisição do backend se
 * apresenta como a aba já liberada. Sem passe, a página do episódio é uma tela
 * de verificação, e o [`ClienteHttp`] sabe distinguir uma coisa da outra para
 * não gastar o socorro do FlareSolverr numa porta que ele não abre.
 *
 * ## De onde vêm o `cf_clearance` e o `cfv`
 *
 * Os dois nascem do mesmo POST, e qual deles o site aceita de volta depende da
 * configuração dele:
 *
 * - **`cf_clearance`** é o cookie que o Cloudflare emite ao vencer o widget. No
 *   navegador ele fica em *DevTools → Application → Cookies → superflixapi.quest*.
 * - **`cfv`** é o token que a própria página do episódio passa a carregar na
 *   query depois de vencido o desafio — o endereço na barra deixa de ser
 *   `/serie/693/4/17` e passa a trazer o parâmetro. Copiar a URL é a forma mais
 *   simples de obtê-lo.
 *
 * Os dois são aceitos aqui, de propósito: enviar um passe que o site ignora não
 * custa nada, e enviar só um quando o site espera o outro custa a busca inteira.
 *
 * ## O que colar, na prática
 *
 * O caminho curto é o **"copiar como cURL"** do DevTools, no pedido que carregou a
 * página liberada (`Network → a requisição do episódio → Copy as cURL`): ele traz
 * todos os cookies da sessão com os nomes, e é assim que eles são reenviados —
 * nada é recortado. Colar só o valor do `cf_clearance` funciona quando o host
 * aceita só ele, e o [`pareceTokenSolto()`] avisa quando o que foi colado nem
 * cookie é.
 *
 * ## O outro portão, que não é este
 *
 * `Acesso Restrito · Visualização Externa` é uma tela **diferente** desta, e não
 * tem relação com o passe: é o anti-hotlink do site, servido quando a requisição
 * chega com `Referer` de outro domínio — é o que uma aba aberta a partir de uma
 * página nossa vê. O backend não manda `Referer` (`Sec-Fetch-Site: none`), então
 * nunca esbarra nesse portão; a tela que ele encontra é a da verificação, com
 * `x-cloudflare-captcha: required` e o formulário `cf_embed_*`.
 */
class PasseCloudflare
{
    /**
     * Marcas do desafio embutido no corpo da página.
     *
     * São os nomes dos campos que o formulário do desafio carrega. Servem tanto
     * para reconhecer a tela de verificação (`cf_embed_challenge`) quanto para
     * não confundi-la com o interstício clássico, que tem outras marcas e é
     * resolvido pelo FlareSolverr sem intervenção.
     *
     * @var array<int, string>
     */
    private const MARCAS_DO_DESAFIO = [
        'cf_embed_challenge',
        'cf_embed_hash',
        'cf-turnstile-response',
        'cf_client_mobile',
    ];

    /**
     * Chave do passe no cache — um só, porque o portão é do host e não nosso.
     */
    private const CHAVE_DO_CACHE = 'passe_cloudflare';

    /**
     * Quanto tempo o passe vale, em segundos.
     *
     * É o prazo **do host**, lido da resposta dele
     * (`x-cloudflare-captcha-ttl-minutes: 45`) — não uma escolha nossa.
     */
    private const PRAZO_SEGUNDOS = 45 * 60;

    /**
     * Os hosts com portão embutido, em minúsculas.
     *
     * @return array<int, string>
     */
    public function hosts(): array
    {
        $hosts = (array) config('services.torrents.passe_cloudflare_hosts', []);

        return array_values(array_filter(array_map(
            fn (mixed $host): string => strtolower(trim((string) $host)),
            $hosts
        )));
    }

    /**
     * Diz se este endereço pertence a um host com portão embutido.
     *
     * O casamento aceita subdomínio (`www.superflixapi.quest`), porque esses
     * hosts trocam de prefixo com frequência, mas nunca um domínio diferente que
     * apenas termine com o mesmo texto.
     */
    public function exige(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        foreach ($this->hosts() as $conhecido) {
            if ($host === $conhecido || str_ends_with($host, '.'.$conhecido)) {
                return true;
            }
        }

        return false;
    }

    /**
     * O cookie que o navegador ganhou ao vencer o widget.
     *
     * A leitura é do cache primeiro, e do `.env` depois: o cache é o caminho de
     * quem acabou de colar o passe pela interface, o `.env` é o permanente — e um
     * passe colado agora vale mais que um configurado ontem, que já venceu.
     */
    public function clearance(): string
    {
        return trim((string) ($this->guardado()['clearance'] ?? ''))
            ?: trim((string) config('services.torrents.passe_cloudflare_clearance', ''));
    }

    /**
     * O token que a página liberada passa a carregar na query (`?cfv=`).
     */
    public function token(): string
    {
        return trim((string) ($this->guardado()['token'] ?? ''))
            ?: trim((string) config('services.torrents.passe_cloudflare_token', ''));
    }

    /**
     * O agente de navegador que venceu o desafio.
     *
     * O passe vale para o par IP+agente: enviar um agente diferente do que
     * resolveu o widget faz o host recusar o cookie. Quem cola o passe copia o
     * agente da mesma sessão — é por isso que ele é configurável.
     */
    public function agente(): string
    {
        return trim((string) ($this->guardado()['agente'] ?? ''))
            ?: trim((string) config('services.torrents.passe_cloudflare_agente', ''));
    }

    /**
     * O passe colado pelo usuário, guardado no cache.
     *
     * O `.env` é o caminho permanente, mas ele custa **recriar o container** para
     * o valor chegar ao processo — e o passe vence em 45 minutos. No cache, colar
     * de novo é imediato e o prazo se encarrega de esquecê-lo.
     *
     * @return array{clearance?: string, token?: string, agente?: string, expira_em?: int}
     */
    private function guardado(): array
    {
        $dados = Cache::get(self::CHAVE_DO_CACHE);

        return is_array($dados) ? $dados : [];
    }

    /**
     * Guarda o passe colado, com o prazo que o host anuncia.
     */
    public function registrar(string $clearance, string $token, string $agente = ''): void
    {
        Cache::put(self::CHAVE_DO_CACHE, array_filter([
            'clearance' => trim($clearance),
            'token' => trim($token),
            'agente' => trim($agente),
            'expira_em' => time() + self::PRAZO_SEGUNDOS,
        ]), self::PRAZO_SEGUNDOS);
    }

    /**
     * Esquece o passe guardado — o caminho de "colar outro".
     */
    public function esquecer(): void
    {
        Cache::forget(self::CHAVE_DO_CACHE);
    }

    /**
     * Quantos segundos o passe guardado ainda vale (`null` = não há nenhum).
     */
    public function expiraEm(): ?int
    {
        $dados = $this->guardado();

        if ($dados === [] || ! isset($dados['expira_em'])) {
            return null;
        }

        return max(0, (int) $dados['expira_em'] - time());
    }

    /**
     * O prazo do passe, que é do host e não nosso.
     *
     * O valor vem da própria resposta dele: `x-cloudflare-captcha-ttl-minutes: 45`.
     * Guardar por mais tempo renderia uma tela de verificação a mais no meio do
     * caminho; por menos, jogaria fora um passe que ainda vale.
     */
    public function prazo(): int
    {
        return self::PRAZO_SEGUNDOS;
    }

    /**
     * Diz se há algum passe em mãos.
     *
     * Serve ao log: sem passe nenhum, a tela de verificação era o esperado; com
     * passe em mãos, ela significa que ele venceu.
     */
    public function disponivel(): bool
    {
        return $this->clearance() !== '' || $this->token() !== '';
    }

    /**
     * Diz se o **ambiente** traz um passe — o caminho permanente, via `.env`.
     *
     * É diferente do [`disponivel()`], que soma o cache: aqui a resposta separa
     * "colado agora, na bancada" de "configurado no ambiente", e é essa diferença
     * que o estado da interface mostra.
     */
    public function configurado(): bool
    {
        return trim((string) config('services.torrents.passe_cloudflare_clearance', '')) !== ''
            || trim((string) config('services.torrents.passe_cloudflare_token', '')) !== '';
    }

    /**
     * Os cabeçalhos que fazem a requisição passar pelo portão.
     *
     * Devolve lista vazia quando não há passe: quem chama não precisa distinguir
     * "sem passe" de "passe vazio".
     *
     * O cookie colado passa pelo [`normalizarCookie()`], que aceita o valor solto,
     * a linha inteira e o bloco do "copiar como cURL" — os detalhes estão lá. O
     * agente viaja no mesmo pacote porque o passe vale para o par IP+agente: sem
     * ele, o host recusa um `cf_clearance` conquistado por outra aba.
     *
     * @return array<string, string>
     */
    public function cabecalhosDeAcesso(): array
    {
        $cabecalhos = [];

        if ($this->clearance() !== '') {
            $cabecalhos['Cookie'] = $this->normalizarCookie($this->clearance());
        }

        if ($this->agente() !== '') {
            $cabecalhos['User-Agent'] = $this->agente();
        }

        return $cabecalhos;
    }

    /**
     * Reduz o que foi colado ao cabeçalho `Cookie` que o host espera.
     *
     * O mesmo passe se copia de várias maneiras, e todas chegam aqui:
     *
     * - só o **valor** do cookie (`Application → Cookies`, duas vezes na célula);
     * - a **linha de cookie inteira**, com os irmãos
     *   (`cf_clearance=...; __cf_bm=...; cfv=...`);
     * - o bloco do **"copiar como cURL"**, com a linha `cookie:` embutida num
     *   comando e outros cabeçalhos em volta.
     *
     * Nada é recortado, e isso é decisão, não descuido: o host que fecha a página
     * com o widget pode estar esperando de volta **qualquer** um dos cookies da
     * sessão, inclusive um que venha *antes* do `cf_clearance` na linha (o
     * `__cf_bm` do próprio Cloudflare, a sessão do site). Uma versão anterior
     * recortava a partir de `cf_clearance=` e jogava os anteriores fora — o
     * descarte passava em silêncio, porque o que sobrava ainda *parecia* um
     * cabeçalho válido.
     *
     * Sem `=`, o que veio é um valor solto, e um valor solto não é cookie: ganha o
     * nome que a bancada pede. Quando o host devolve outra coisa nesse campo — o
     * grant `cfv`, o `cf_embed_hash` do desafio —, quem avisa é o
     * [`pareceTokenSolto()`], antes de o backend perder 45 minutos de passe.
     */
    private function normalizarCookie(string $colado): string
    {
        $doComando = $this->cookieDoComando($colado);

        $texto = $this->semRotulo($doComando ?? $colado);

        return str_contains($texto, '=') ? $texto : 'cf_clearance='.$texto;
    }

    /**
     * Extrai a linha de cookie de um comando do "copiar como cURL".
     *
     * O DevTools entrega o pedido inteiro, e o cookie chega em uma de três formas:
     * `-H 'cookie: ...'`, `--header 'Cookie: ...'` ou `-b/--cookie '...'`. O recorte
     * para na aspa que fechou o argumento — cookie não carrega aspas —, e `null`
     * significa que o que foi colado não é um comando, e sim o valor ou a linha.
     */
    private function cookieDoComando(string $colado): ?string
    {
        if (preg_match('#(?:-H|--header)\s+(["\'])\s*cookie\s*:\s*(.*?)\1#is', $colado, $achado) === 1) {
            return trim($achado[2]);
        }

        if (preg_match('#(?:-b|--cookie)\s+(["\'])(.*?)\1#is', $colado, $achado) === 1) {
            return trim($achado[2]);
        }

        return null;
    }

    /**
     * Tira o rótulo `Cookie:` de quem colou a linha do cabeçalho, e não o valor.
     *
     * Sem isso, o cabeçalho montado viraria `Cookie: Cookie: cf_clearance=...` — um
     * nome de cookie inválido —, e o host devolveria a tela de verificação sem dizer
     * por quê.
     */
    private function semRotulo(string $colado): string
    {
        if (preg_match('#^\s*cookie\s*:\s*(.+)$#is', $colado, $achado) === 1) {
            return trim($achado[1]);
        }

        return trim($colado);
    }

    /**
     * Diz se o que foi colado **não parece** o valor de um `cf_clearance`.
     *
     * O `cf_clearance` tem forma reconhecível: um trecho aleatório, o carimbo de
     * tempo, a versão do desafio e a assinatura, separados por hífen. O que não
     * casa com isso e também não traz nome de cookie é um token de outra natureza —
     * e o campo dele é o do `cfv`, não o do cookie. Medido no `superflixapi.quest`:
     * colar ali o token do widget devolve a mesma tela de verificação, igual a não
     * colar nada, e o silêncio custa uma rodada inteira de investigação.
     *
     * É um aviso, não uma recusa: formatos do Cloudflare mudam sem aviso, e um
     * palpite nosso não deve barrar um passe que talvez funcione.
     */
    public function pareceTokenSolto(string $colado): bool
    {
        $texto = trim($colado, " \t\"'");

        if ($texto === '' || str_contains($texto, '=')) {
            return false;
        }

        return preg_match('#^[A-Za-z0-9_.~-]+-\d{9,}-\d+\.\d+\.\d+\.\d+-[A-Za-z0-9_.-]+$#', $texto) !== 1;
    }

    /**
     * Quando o passe foi conquistado, lido do próprio valor do `cf_clearance`.
     *
     * O valor carrega o carimbo do **nascimento**, e não o do fim da validade. A
     * medição fecha a dúvida: um `cf_clearance` conquistado às 15:21:58 pelo
     * FlareSolverr (num site de teste com o mesmo Cloudflare) veio com o carimbo
     * `1791213718` — o próprio segundo da conquista —, enquanto o vencimento do
     * cookie, lido do navegador pelo CDP, era `1822749718`, exatamente um ano
     * depois. Carimbo e validade são coisas distintas, e é o carimbo que diz há
     * quanto tempo o passe existe.
     *
     * É o que a colagem não responde sozinha: um passe conquistado há dois minutos
     * e um esquecido no campo desde ontem chegam aqui com a mesma cara, e o host
     * recusa os dois do mesmo jeito — devolvendo a tela de verificação, sem dizer
     * qual dos dois é. `null` quando não há carimbo para ler: valor que não é
     * `cf_clearance`, ou passe que veio só com o token `cfv`.
     */
    public function carimboDoValor(string $colado): ?int
    {
        $valor = $this->valorDoClearance($colado);

        if (preg_match('#^[A-Za-z0-9_.~]+-(\d{9,})-\d+\.\d+\.\d+\.\d+-#', $valor, $achado) !== 1) {
            return null;
        }

        $carimbo = (int) $achado[1];

        return $carimbo > 0 ? $carimbo : null;
    }

    /**
     * Os nomes dos cookies de uma colagem, na ordem em que vieram.
     *
     * É a resposta para "o que exatamente está em mãos?" quando a conferência
     * recebe a tela de verificação: o host não diz que recusou o passe, e um
     * passe que traz `__cf_bm` e não traz `cf_clearance` é indistinguível, pela
     * resposta, de um passe vencido ou de nenhum passe.
     *
     * Isso acontece na prática porque o host marca o `cf_clearance` como
     * `HttpOnly`: ele aparece em *Application → Cookies* e no cabeçalho `cookie:`
     * do pedido, mas **não** em `document.cookie` — quem copia os cookies pelo
     * console cola a lista sem ele, e o campo fica com a cara de preenchido.
     *
     * Devolve só os **nomes**, nunca os valores: o valor já está guardado onde
     * deve estar, e o que se quer mostrar é a lista.
     *
     * @return array<int, string>
     */
    public function nomesDeCookies(string $colado): array
    {
        $texto = $this->semRotulo($this->cookieDoComando($colado) ?? $colado);

        // Valor solto, sem `=`: é a célula copiada do DevTools, que traz só o
        // valor — o nome do cookie fica implícito no campo em que foi colado.
        if (! str_contains($texto, '=')) {
            return [];
        }

        $nomes = [];

        foreach (explode(';', $texto) as $pedaco) {
            $nome = trim(explode('=', $pedaco, 2)[0], " \t\"'");

            if (preg_match('#^[A-Za-z0-9_.-]+$#', $nome) === 1) {
                $nomes[] = $nome;
            }
        }

        return array_values(array_unique($nomes));
    }

    /**
     * Recorta o valor do `cf_clearance` de onde ele estiver no que foi colado.
     *
     * Aceita as mesmas quatro formas do [`normalizarCookie()`] — é a mesma
     * colagem: o valor solto, a linha com os irmãos, o rótulo `Cookie:` e o bloco
     * do "copiar como cURL". Sem nome de cookie na linha, o texto inteiro vale
     * como valor, que é o caso de quem copiou só a célula do DevTools.
     */
    private function valorDoClearance(string $colado): string
    {
        $texto = $this->semRotulo($this->cookieDoComando($colado) ?? $colado);

        if (preg_match('#(?:^|;\s*)cf_clearance=([^;]*)#i', $texto, $achado) === 1) {
            return trim($achado[1], " \t\"'");
        }

        return str_contains($texto, '=') ? '' : trim($texto, " \t\"'");
    }

    /**
     * Acrescenta o token à consulta de um `GET`.
     *
     * O parâmetro vai junto dos demais porque é assim que a página liberada o
     * carrega na própria barra de endereços — não há endpoint de troca.
     *
     * @param  array<string, mixed>  $consulta
     * @return array<string, mixed>
     */
    public function consultaComToken(array $consulta): array
    {
        if ($this->token() !== '') {
            $consulta['cfv'] = $this->token();
        }

        return $consulta;
    }

    /**
     * Acrescenta o token ao endereço de um `POST`.
     *
     * O `post()` do [`ClienteHttp`] recebe a URL pronta em vez de uma consulta,
     * então a emenda é feita na mão, respeitando uma query que já exista.
     */
    public function urlComToken(string $url): string
    {
        if ($this->token() === '' || str_contains($url, 'cfv=')) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'cfv='.rawurlencode($this->token());
    }

    /**
     * Diz se o corpo é a tela de verificação do desafio embutido.
     *
     * A leitura é do começo do documento: o formulário do desafio é o primeiro
     * conteúdo da página, e varrer um HTML de centenas de KB seria desperdício —
     * medido no host, a tela de verificação do superflix tem **620 KB** e os
     * campos do formulário estão nas primeiras linhas.
     */
    public function paginaDeDesafio(string $corpo): bool
    {
        if ($corpo === '') {
            return false;
        }

        /*
         * A varredura é do corpo inteiro, e não de uma amostra do começo como no
         * interstício clássico. Medido no host, a tela de verificação do superflix
         * tem 18 KB e **abre com o CSS do widget**: os campos do formulário
         * (`cf_embed_challenge`) só aparecem bem depois dos primeiros kilobytes, e
         * uma janela curta lia a tela de verificação como se fosse a página do
         * episódio. São quatro marcas curtas num documento pequeno — o custo de
         * varrer tudo é irrelevante.
         */
        foreach (self::MARCAS_DO_DESAFIO as $marca) {
            if (stripos($corpo, $marca) !== false) {
                return true;
            }
        }

        return false;
    }
}
