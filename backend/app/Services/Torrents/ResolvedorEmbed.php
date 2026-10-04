<?php

namespace App\Services\Torrents;

use Illuminate\Support\Facades\Log;

/**
 * Atravessa um player de embed até o arquivo de vídeo.
 *
 * O [`ProvedorStreamDireto`] recusa embeds por princípio, e a razão está no
 * contrato: a fonte direta precisa terminar em `.mp4`/`.m3u8`, porque é só isso
 * que o `prepararSessaoDireta()` do media-service aceita. Um iframe é uma
 * página, não um vídeo — oferecê-lo entregava ao usuário uma sessão que morria
 * na primeira checagem.
 *
 * O que faltava não era abrir mão do contrato, e sim **percorrer** o embed: a
 * página do player quase nunca é o fim da linha, é a primeira camada de uma
 * cadeia que termina no arquivo. Este serviço caminha essa cadeia nos
 * agregadores cuja receita é conhecida e, principalmente, **aberta**.
 *
 * ## A receita do `plenoflu.com`, do agregador ao arquivo
 *
 * O `verpobreflix.net` não hospeda vídeo: a página do episódio só embute um
 * iframe do `plenoflu.com`. Dali até o vídeo são quatro passos, todos sem
 * cadastro, sem cookie e sem renderizar JavaScript:
 *
 * 1. **A página do embed** declara o id interno do episódio em
 *    `DIRECT_EPISODE_ID`.
 * 2. **`action=getOptions`** devolve as opções de player (uma por provedor).
 * 3. **`action=getPlayer`** devolve o endereço do provedor, codificado em
 *    base64.
 * 4. **O player** monta o vídeo em JavaScript. Aqui mora o pulo do gato: o
 *    `vaiquecol.com` — o único dos quatro provedores que não está atrás de
 *    Turnstile nem de cifra dentro do navegador — chama
 *    `FirePlayer("<hash>", {...})` de dentro de um `<script>` **empacotado**, e
 *    o hash é a chave da consulta que revela a fonte. O `POST
 *    /player/index.php?data=<hash>&do=getVideo` responde com `securedLink`: um
 *    **master.m3u8 assinado**, tocável direto.
 *
 * O passo 4 não exige executar o JavaScript do player, só **lê-lo**. O trecho
 * vem no packer clássico (`eval(function(p,a,c,k,e,d))`), que nada mais é que um
 * dicionário em base62 — desempacotar em PHP é aritmética, não interpretação.
 * Quando o player declara `hls: true`, a URL sai em texto claro; quando declara
 * `hls: false`, ela sai cifrada no formato do CryptoJS e é decifrada aqui.
 *
 * ## Por que os outros três provedores ficaram de fora
 *
 * O `superflixapi.quest` e o `streambetter.shop` respondem com o Cloudflare
 * Turnstile: a página que chega é a de verificação, e vencê-la exige navegador
 * com IP de residência. O `vidsrc.sh` cifra a lista de fontes em ChaCha20 dentro
 * de um WebAssembly e decifra só no navegador do usuário. Tentar qualquer um dos
 * três gastaria orçamento para receber uma tela de bloqueio; o registro dessas
 * tentativas está em `docs/integracoes.md`.
 *
 * ## O que sai daqui
 *
 * Uma URL de vídeo e, quando o próprio player a denuncia, o idioma: a playlist
 * master do FirePlayer declara as faixas de áudio (`LANGUAGE="por"`), e uma
 * faixa em português é a diferença entre dublado e legendado para quem assiste.
 * A URL só é devolvida depois de a playlist abrir — o fallback não oferece link
 * que já nasceu morto.
 */
class ResolvedorEmbed
{
    use ConsultaComOrcamento;

    /**
     * Cadeias de embed que o projeto sabe percorrer, por host.
     *
     * A lista é de domínios, não de padrões de URL: só se segue um iframe cuja
     * cadeia tem receita conhecida. Seguir embed de host desconhecido seria
     * adivinhação, e para esse caso a prova de mídia genérica do
     * [`ExtratorVideo`] já faz o trabalho — quando a própria página entrega o
     * arquivo, não há cadeia nenhuma para percorrer.
     *
     * @var array<int, string>
     */
    private const EMBEDS = [
        'plenoflu.com',
    ];

    /**
     * O provedor do `plenoflu.com` que entrega o arquivo sem navegador.
     *
     * Dos quatro que a API lista, é o único sem Turnstile e sem cifra no
     * cliente. Os demais aparecem no log como descartados, para a próxima
     * investigação saber que foram vistos e por que foram recusados.
     */
    private const PLAYER_ABERTO = 'vaiquecol.com';

    /**
     * API do catálogo de embeds do `plenoflu.com`.
     *
     * O único requisito é um `Referer` qualquer (sem ele, o site devolve 403) e
     * os parâmetros na query string: o endpoint lê `$_GET`, não o corpo. Um POST
     * sem corpo basta, o que permite usar o [`ClienteHttp`] sem serializar
     * formulário.
     */
    private const API_PLENOFLU = 'https://plenoflu.com/api';

    /**
     * Teto de opções de player consultadas por episódio.
     *
     * Quatro é o tamanho que a lista costuma ter. O teto existe para o caso de o
     * catálogo crescer: cada opção custa uma requisição, e a primeira que
     * resolve já encerra o laço.
     */
    private const TETO_DE_PLAYERS = 4;

    public function __construct(
        private readonly OrcamentoBusca $orcamento,
        private readonly ClienteHttp $cliente,
    ) {
    }

    /**
     * O resolvedor está ligado.
     *
     * A chave permite desligar a travessia sem desligar o fallback inteiro: se um
     * agregador começar a responder coisa errada, o stream direto volta ao
     * comportamento anterior — só o que a página entrega em texto claro — com
     * uma variável de ambiente, sem mexer no código.
     */
    public function disponivel(): bool
    {
        return (bool) config('services.torrents.stream_direto_resolver_embeds', true);
    }

    /**
     * Tenta transformar um embed da página numa URL de vídeo tocável.
     *
     * O `$referer` é a página que embutiu o player — os agregadores conferem a
     * origem e devolvem 403 sem ela. O `$teto` é o tempo desta consulta, já
     * descontado do orçamento global por quem chama.
     *
     * Devolve `null` quando não há embed conhecido ou quando a cadeia não chega
     * ao arquivo. Nunca devolve uma URL que não tenha sido aberta e conferida.
     *
     * @return array{url: string, idioma: ?string}|null
     */
    public function resolver(string $html, string $referer, int $teto): ?array
    {
        /*
         * A travessia custa mais de uma requisição — a página do embed, a API, o
         * player e a playlist —, então ela segue o mesmo critério do laço de
         * páginas do provedor: só começa quando resta tempo proporcional ao teto.
         * Entrar num encadeamento a segundos do fim só produziria trabalho
         * perdido no cancelamento, que é justamente o defeito que o
         * `temTempoParaConsulta()` existe para evitar.
         */
        if (! $this->disponivel() || $teto <= 0 || ! $this->temTempoParaConsulta($teto)) {
            return null;
        }

        foreach ($this->embedsDoPlenoflu($html) as $embed) {
            $resolvido = $this->peloPlenoflu($embed, $referer, $teto);

            if ($resolvido !== null) {
                return $resolvido;
            }
        }

        return null;
    }

    /**
     * Iframes do `plenoflu.com` presentes na página.
     *
     * A página do episódio do agregador traz o player num `<iframe>` — é a única
     * pista do que está por baixo, já que o `verpobreflix.net` não guarda nada do
     * vídeo. O `www.` é ruído de configuração do site, não outro domínio.
     *
     * @return array<int, string>
     */
    private function embedsDoPlenoflu(string $html): array
    {
        if (! preg_match_all('#<iframe\b[^>]*\bsrc\s*=\s*["\']([^"\']+)#i', $html, $casamentos)) {
            return [];
        }

        $embeds = [];

        foreach ($casamentos[1] as $bruto) {
            $endereco = html_entity_decode(trim((string) $bruto), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (! preg_match('#^https?://#i', $endereco)) {
                continue;
            }

            $host = strtolower((string) parse_url($endereco, PHP_URL_HOST));
            $host = (string) preg_replace('/^www\./', '', $host);

            foreach (self::EMBEDS as $conhecido) {
                if ($host === $conhecido || str_ends_with($host, '.'.$conhecido)) {
                    $embeds[$endereco] = true;
                }
            }
        }

        return array_keys($embeds);
    }

    /**
     * Percorre a cadeia do `plenoflu.com` até um player entregar o arquivo.
     *
     * A ordem importa: a página do embed dá o id interno do episódio, e só com
     * ele a API responde. As opções são consultadas em sequência até a primeira
     * que resolver — a lista mistura provedores abertos e protegidos, e qual
     * deles responde muda com o tempo (estes domínios trocam de endereço e de
     * blindagem com frequência).
     *
     * @return array{url: string, idioma: ?string}|null
     */
    private function peloPlenoflu(string $embed, string $referer, int $teto): ?array
    {
        $html = $this->abrir($embed, $referer, $teto);

        if ($html === null) {
            return null;
        }

        $conteudo = $this->episodioDoPlenoflu($html);

        if ($conteudo === null) {
            Log::debug('Resolvedor de embed: página do plenoflu sem id de episódio.', ['embed' => $embed]);

            return null;
        }

        foreach ($this->opcoesDoPlenoflu($conteudo, $teto) as $opcao) {
            $player = $this->playerDoPlenoflu($opcao, $teto);

            if ($player === null) {
                continue;
            }

            $host = strtolower((string) parse_url($player, PHP_URL_HOST));

            /*
             * Só o player aberto é seguido. O `superflixapi.quest` e o
             * `streambetter.shop` estão atrás do Turnstile, e o `vidsrc.sh` cifra
             * a resposta em ChaCha20 dentro de um WebAssembly: abrir qualquer um
             * deles devolveria uma tela de verificação, não o vídeo. O log
             * registra o descarte com o host para a próxima investigação saber
             * que o provedor foi visto e recusado com motivo.
             */
            if ($host !== self::PLAYER_ABERTO) {
                Log::debug('Resolvedor de embed: player sem caminho aberto descartado.', [
                    'player' => $host,
                ]);

                continue;
            }

            $resolvido = $this->peloFirePlayer($player, $embed, $teto);

            if ($resolvido !== null) {
                return $resolvido;
            }
        }

        return null;
    }

    /**
     * Abre um endereço e devolve o corpo, ou `null` quando ele não vem.
     *
     * Serve tanto para as páginas da cadeia quanto para a playlist final — as
     * duas são um `GET` que precisa se identificar. O `Referer` não é enfeite:
     * sem ele o agregador responde 403 antes de olhar o que foi pedido. Foi
     * assim que a primeira sondagem da cadeia pareceu "bloqueada", quando só
     * faltava se apresentar.
     */
    private function abrir(string $url, string $referer, int $teto): ?string
    {
        if (! $this->temOrcamento()) {
            return null;
        }

        try {
            $resposta = $this->cliente->get($url, [], null, $teto, ['Referer' => $referer]);
        } catch (\Throwable $excecao) {
            Log::debug('Resolvedor de embed: página do embed não respondeu.', [
                'embed' => $url,
                'erro' => $excecao->getMessage(),
            ]);

            return null;
        }

        if ($resposta === null || $resposta->failed()) {
            return null;
        }

        return (string) $resposta->body();
    }

    /**
     * O id interno do episódio, que a própria página do embed publica.
     *
     * É a chave de tudo o que vem depois: sem ele a API do `plenoflu.com` não tem
     * o que responder. O valor mora numa variável JavaScript solta
     * (`DIRECT_EPISODE_ID = 77601`), que o HTML estático entrega de bandeja.
     */
    private function episodioDoPlenoflu(string $html): ?string
    {
        if (! preg_match('/DIRECT_EPISODE_ID\s*[:=]\s*[\'"]?(\d+)/', $html, $achado)) {
            return null;
        }

        return $achado[1];
    }

    /**
     * As opções de player que a API oferece para o episódio.
     *
     * Cada opção é um provedor diferente com o mesmo vídeo. Só os identificadores
     * interessam aqui — o `type` que a API também devolve descreve o áudio, mas
     * quem diz o idioma de verdade é a playlist do player, onde as faixas estão
     * nomeadas.
     *
     * @return array<int, string>
     */
    private function opcoesDoPlenoflu(string $conteudo, int $teto): array
    {
        $dados = $this->pelaApi('action=getOptions&contentid='.rawurlencode($conteudo), $teto);

        $opcoes = $dados['data']['options'] ?? null;

        if (! is_array($opcoes)) {
            return [];
        }

        $ids = [];

        foreach ($opcoes as $opcao) {
            if (! is_array($opcao)) {
                continue;
            }

            $id = (string) ($opcao['ID'] ?? $opcao['id'] ?? '');

            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        return array_slice(array_keys($ids), 0, self::TETO_DE_PLAYERS);
    }

    /**
     * O endereço do player de uma opção, que a API entrega em base64.
     *
     * A codificação não esconde nada — é a forma com que o site devolve o campo
     * —, então decodificar basta. O endereço é conferido antes de voltar: base64
     * corrompido não pode virar requisição.
     */
    private function playerDoPlenoflu(string $opcao, int $teto): ?string
    {
        $dados = $this->pelaApi('action=getPlayer&video_id='.rawurlencode($opcao), $teto);

        $codificado = (string) ($dados['data']['video_url'] ?? '');

        if ($codificado === '') {
            return null;
        }

        $endereco = base64_decode($codificado, true);

        if ($endereco === false || ! preg_match('#^https?://#i', $endereco)) {
            return null;
        }

        return $endereco;
    }

    /**
     * Consulta a API do `plenoflu.com` e devolve o JSON já decodificado.
     *
     * Os parâmetros vão na query string porque é de lá que o endpoint os lê — um
     * POST sem corpo basta, o que dispensa serializar formulário. O
     * `X-Requested-With` mantém a chamada com a cara de AJAX que o site espera, a
     * mesma do endpoint do player.
     *
     * @return array<string, mixed>
     */
    private function pelaApi(string $consulta, int $teto): array
    {
        if (! $this->temOrcamento()) {
            return [];
        }

        try {
            $resposta = $this->cliente->post(
                self::API_PLENOFLU.'?'.$consulta,
                [],
                null,
                $teto,
                [
                    'Referer' => 'https://plenoflu.com/',
                    'X-Requested-With' => 'XMLHttpRequest',
                ]
            );
        } catch (\Throwable $excecao) {
            Log::debug('Resolvedor de embed: API do plenoflu não respondeu.', [
                'consulta' => $consulta,
                'erro' => $excecao->getMessage(),
            ]);

            return [];
        }

        if ($resposta === null || $resposta->failed()) {
            return [];
        }

        $dados = json_decode((string) $resposta->body(), true);

        return is_array($dados) ? $dados : [];
    }

    /**
     * Segue o player do FirePlayer até a URL do vídeo.
     *
     * O player não guarda o vídeo no HTML: ele pergunta ao próprio servidor,
     * usando um hash que o `<script>` da página carrega. Como esse script vem
     * empacotado, o hash não está no HTML como texto — a página é aberta uma
     * segunda vez aqui só para desempacotá-lo (a primeira foi para descobrir que
     * era este player).
     *
     * A URL que sai daqui é **aberta antes de ser devolvida**: um link com
     * assinatura expirada ou de um provedor que já saiu do ar só apareceria como
     * erro para o usuário, depois de todo o custo da busca.
     *
     * @return array{url: string, idioma: ?string}|null
     */
    private function peloFirePlayer(string $embed, string $referer, int $teto): ?array
    {
        $html = $this->abrir($embed, $referer, $teto);

        if ($html === null) {
            return null;
        }

        $hash = $this->hashDoFirePlayer($html);

        if ($hash === null) {
            Log::debug('Resolvedor de embed: player sem hash do FirePlayer.', ['player' => $embed]);

            return null;
        }

        $url = $this->pelaFonte($embed, $hash, $teto);

        if ($url === null) {
            return null;
        }

        /*
         * Arquivo progressivo não se confere abrindo: o `GET` de um `.mp4`
         * baixaria o filme inteiro só para dizer "existe". A conferência fica na
         * extensão, e a lista é a mesma que o resto do fallback aceita — o
         * media-service decide como ler a entrada por ela.
         */
        if ($this->pareceProgressivo($url)) {
            return ['url' => $url, 'idioma' => null];
        }

        /*
         * Playlist, ao contrário, é barata de abrir e a abertura é a prova que
         * interessa: o `securedLink` vem assinado e com validade, e uma URL
         * vencida só apareceria como erro para quem fosse assistir.
         */
        $playlist = $this->abrir($url, $embed, $teto);

        if ($playlist === null || ! str_contains($playlist, '#EXTM3U')) {
            Log::debug('Resolvedor de embed: fonte resolvida não abriu como playlist.', ['video' => $url]);

            return null;
        }

        return [
            'url' => $url,
            'idioma' => $this->idiomaDaPlaylist($playlist),
        ];
    }

    /**
     * Diz se o endereço aponta para um arquivo de vídeo progressivo.
     *
     * Só `mp4` (e o seu primo `m4v`) entram: são os formatos que o resto do
     * fallback já aceita e que o media-service sabe ler. Um `.mkv` vindo de um
     * player de embed seria recusado adiante de qualquer forma, e recusá-lo aqui
     * evita oferecer ao usuário uma fonte que morre na hora de tocar.
     */
    private function pareceProgressivo(string $url): bool
    {
        $caminho = strtolower((string) parse_url($url, PHP_URL_PATH));

        return (bool) preg_match('/\.(mp4|m4v)$/', $caminho);
    }

    /**
     * Pede ao servidor do player a URL do vídeo do hash.
     *
     * O endereço da consulta é o mesmo do [`FirePlayer`] (`/player/index.php`
     * com `do=getVideo`), no host do embed. O `X-Requested-With` é obrigatório:
     * sem ele o endpoint responde com a **página** do player em vez do JSON —
     * comportamento que só apareceu no teste e que separa "tem o vídeo" de "veio
     * HTML onde se esperava dado".
     */
    private function pelaFonte(string $embed, string $hash, int $teto): ?string
    {
        if (! $this->temOrcamento()) {
            return null;
        }

        $host = (string) parse_url($embed, PHP_URL_HOST);
        $url = 'https://'.$host.'/player/index.php?data='.rawurlencode($hash).'&do=getVideo';

        try {
            $resposta = $this->cliente->post($url, [], null, $teto, [
                'Referer' => $embed,
                'X-Requested-With' => 'XMLHttpRequest',
            ]);
        } catch (\Throwable $excecao) {
            Log::debug('Resolvedor de embed: endpoint do player não respondeu.', [
                'player' => $embed,
                'erro' => $excecao->getMessage(),
            ]);

            return null;
        }

        if ($resposta === null || $resposta->failed()) {
            return null;
        }

        $dados = json_decode((string) $resposta->body(), true);

        if (! is_array($dados)) {
            return null;
        }

        return $this->enderecoDoVideo($dados);
    }

    /**
     * O hash que identifica a fonte na chamada do player.
     *
     * A chamada `FirePlayer("<hash>", {...})` mora dentro do `<script>`
     * empacotado, então o HTML cru não a mostra. A extração tenta primeiro o
     * texto desempacotado e depois o HTML direto — há instalações do FirePlayer
     * que montam o player sem empacotar nada, e desempacotar o que não está
     * empacotado devolveria `null` sem custo.
     */
    private function hashDoFirePlayer(string $html): ?string
    {
        foreach ([$this->desempacotar($html) ?? '', $html] as $texto) {
            if ($texto === '') {
                continue;
            }

            if (preg_match('/FirePlayer\(\s*["\']([A-Za-z0-9]{16,})["\']/', $texto, $achado)) {
                return $achado[1];
            }
        }

        return null;
    }

    /**
     * Desempacota o script gerado pelo packer clássico.
     *
     * O formato é
     * `eval(function(p,a,c,k,e,d){...}('<corpo>',62,<n>,'<dicionário>'.split('|'),0,{}))`.
     * No corpo, cada palavra virou um índice escrito em base62; o dicionário diz
     * o que cada índice significa. Desempacotar é montar esse mapa e trocar as
     * palavras de volta — nenhuma linha é executada, o `eval` fica só no nome.
     *
     * Devolve `null` quando a página não tem packer, o que é normal: nem todo
     * site ofusca o script do player.
     */
    private function desempacotar(string $html): ?string
    {
        $inicio = strpos($html, 'eval(function(p,a,c,k,e,d)');

        if ($inicio === false) {
            return null;
        }

        if (! preg_match("/}\('(.*)',(\d+),(\d+),'(.*)'\.split\('\|'\)/s", substr($html, $inicio), $pedacos)) {
            return null;
        }

        $corpo = $this->desescaparJs($pedacos[1]);
        $base = (int) $pedacos[2];
        $total = (int) $pedacos[3];
        $palavras = explode('|', $this->desescaparJs($pedacos[4]));

        /*
         * O mesmo gerador de índices do packer, em PHP: os índices não são
         * números, são base62 (`0`-`9`, `a`-`z`, `A`-`Z`), e é por eles que o
         * dicionário é consultado.
         */
        $indice = function (int $numero) use (&$indice, $base): string {
            $prefixo = $numero < $base ? '' : $indice(intdiv($numero, $base));
            $resto = $numero % $base;

            return $prefixo.($resto > 35 ? chr($resto + 29) : base_convert((string) $resto, 10, 36));
        };

        $dicionario = [];

        for ($i = 0; $i < $total; $i++) {
            $chave = $indice($i);
            $dicionario[$chave] = ($palavras[$i] ?? '') !== '' ? $palavras[$i] : $chave;
        }

        return (string) preg_replace_callback(
            '/\w+/',
            fn (array $achado): string => $dicionario[$achado[0]] ?? $achado[0],
            $corpo
        );
    }

    /**
     * Desfaz apenas os escapes que o JavaScript desfaria numa string simples.
     *
     * `\\` volta a ser `\`, e `\'`/`\"` viram aspa. O `\/` fica como está, que é
     * como as URLs aparecem no código do player. Um `stripcslashes()` do PHP não
     * serve aqui: ele converte `\b` em backspace e transformaria endereço válido
     * em lixo.
     */
    private function desescaparJs(string $valor): string
    {
        return (string) preg_replace_callback(
            '/\\\\(.)/',
            fn (array $achado): string => in_array($achado[1], ['\\', "'", '"'], true)
                ? $achado[1]
                : $achado[0],
            $valor
        );
    }

    /**
     * Extrai a URL do vídeo do JSON que o player devolve.
     *
     * O player responde de duas formas, e a diferença está em `hls`:
     *
     * - **`hls: true`** — o vídeo é uma playlist, e o `securedLink` já vem
     *   pronto, assinado e com expiração: é o caminho preferido, sem nada para
     *   decifrar. O `videoSource` aponta para a mesma playlist, mas servido como
     *   `.txt`, e o media-service decide como ler a entrada pela extensão — um
     *   `.txt` cai fora da lista que ele aceita.
     * - **`hls: false`** — o vídeo é um arquivo progressivo, e cada `file` de
     *   `videoSources` chega cifrado; quem decifra é o [`decifrar()`].
     */
    private function enderecoDoVideo(array $dados): ?string
    {
        $seguro = trim((string) ($dados['securedLink'] ?? ''));

        if (preg_match('#^https?://#i', $seguro)) {
            return $seguro;
        }

        foreach ($this->chavesPossiveis((string) ($dados['ck'] ?? '')) as $chave) {
            foreach ((array) ($dados['videoSources'] ?? []) as $fonte) {
                if (! is_array($fonte)) {
                    continue;
                }

                $decifrado = $this->decifrar((string) ($fonte['file'] ?? ''), $chave);

                if ($decifrado !== null) {
                    return $decifrado;
                }
            }
        }

        return null;
    }

    /**
     * As leituras possíveis da chave de decifração.
     *
     * O campo `ck` chega com os caracteres em escape hexadecimal
     * (`\x59\x54\x64...`), que é como o JavaScript os recebe depois de
     * interpretar a string. O player entrega esse texto ao CryptoJS sem mexer
     * nele, o que faz da leitura literal a fiel — mas a mesma sequência
     * interpretada vira uma chave em base64, então as duas são tentadas. Como o
     * resultado precisa decifrar uma URL, a leitura errada se descarta sozinha.
     *
     * @return array<int, string>
     */
    private function chavesPossiveis(string $ck): array
    {
        $literal = trim($ck);

        if ($literal === '') {
            return [];
        }

        return array_values(array_unique([$literal, $this->desofuscar($literal)]));
    }

    /**
     * Traduz os escapes hexadecimais do campo `ck`.
     *
     * `\x59\x54\x64` vira `YTd`. É a mesma conversão que o JavaScript faria ao
     * interpretar a string, feita à mão aqui porque do outro lado quem lê é o
     * PHP.
     */
    private function desofuscar(string $valor): string
    {
        return (string) preg_replace_callback(
            '/\\\\x([0-9A-Fa-f]{2})/',
            fn (array $achado): string => chr((int) hexdec($achado[1])),
            $valor
        );
    }

    /**
     * Decifra um campo do player no formato do CryptoJS.
     *
     * O site usa a biblioteca `cryptojs-aes-format`, cuja cifra é um **JSON**
     * (`{"ct":"<base64>","iv":"<hex>","s":"<hex>"}`) — o vetor e o sal viajam
     * separados do texto cifrado, e a chave é derivada da senha com o sal pelo
     * `EVP_BytesToKey` do OpenSSL, em MD5 e uma iteração. Reproduzir isso em PHP
     * é derivar os 32 bytes da chave e chamar o `openssl_decrypt()`.
     *
     * O texto claro é o valor já serializado (`JSON.stringify`), então a URL
     * volta entre aspas e precisa de uma segunda leitura. E é essa necessidade
     * que serve de conferência: a decifragem só é aceita quando o resultado é uma
     * URL. Sem essa prova, uma chave errada devolveria bytes aleatórios como se
     * fossem fonte.
     */
    private function decifrar(string $cifrado, string $chave): ?string
    {
        if ($cifrado === '' || $chave === '') {
            return null;
        }

        $dados = json_decode($cifrado, true);

        if (! is_array($dados) || ! isset($dados['ct'], $dados['iv'], $dados['s'])) {
            return null;
        }

        $sal = $this->hexadecimal((string) $dados['s']);
        $vetor = $this->hexadecimal((string) $dados['iv']);
        $texto = base64_decode((string) $dados['ct'], true);

        if ($sal === null || $vetor === null || $texto === false) {
            return null;
        }

        $comSal = $chave.$sal;
        $primeiro = md5($comSal, true);

        $claro = openssl_decrypt(
            $texto,
            'aes-256-cbc',
            substr($primeiro.md5($primeiro.$comSal, true), 0, 32),
            OPENSSL_RAW_DATA,
            $vetor
        );

        if ($claro === false) {
            return null;
        }

        $url = json_decode($claro, true);

        if (! is_string($url) || ! preg_match('#^https?://#i', $url)) {
            return null;
        }

        return $url;
    }

    /** Converte um trecho hexadecimal em bytes, ou `null` se não for hexa válido. */
    private function hexadecimal(string $valor): ?string
    {
        if ($valor === '' || strlen($valor) % 2 !== 0 || ! ctype_xdigit($valor)) {
            return null;
        }

        return (string) hex2bin($valor);
    }

    /**
     * O idioma que a playlist master denuncia.
     *
     * O FirePlayer publica o áudio como faixas nomeadas na própria playlist
     * (`#EXT-X-MEDIA:...LANGUAGE="por",NAME="Portuguese"`), e uma faixa em
     * português é o que separa dublado de legendado para quem assiste. Sem essa
     * leitura, a fonte sairia com idioma desconhecido e a diferença só
     * apareceria na hora de assistir.
     *
     * A checagem é pelo rótulo das faixas, não pelo título: o `verpobreflix.net`
     * monta a URL do episódio sem dizer o idioma, e o `type` que a API do
     * `plenoflu.com` devolve descreve o grupo de opções, não o áudio.
     */
    private function idiomaDaPlaylist(string $playlist): ?string
    {
        if (preg_match('/#EXT-X-MEDIA:[^\n]*LANGUAGE="(?:por|pt|pt-br)"/i', $playlist)) {
            return 'dublado';
        }

        return null;
    }
}
