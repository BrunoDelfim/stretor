<?php

namespace App\Services\Torrents;

/**
 * Extrai URLs de vídeo tocáveis do HTML de uma página de streaming.
 *
 * Uma página de streaming nunca entrega o vídeo de bandeja: o link mora no
 * `<video src>`, num `<source>` dentro dele, num player JavaScript (JW Player,
 * Plyr, Video.js, Flowplayer) ou numa variável de configuração solta no meio do
 * `<script>`. Este serviço varre o HTML por todos esses caminhos e devolve as
 * URLs que parecem vídeo.
 *
 * A extração é em camadas, da mais explícita para a mais escondida:
 *
 * 1. **Tags HTML5.** `<video src="...">` e `<source src="...">` — o caminho
 *    direto, quando o site não usa player de terceiros.
 * 2. **Configuração de player.** `file:`, `src:`, `source:`, `sources: [...]`,
 *    `hls:`, `dash:` — as chaves que os players JS usam para apontar o vídeo.
 *    O JW Player, por exemplo, recebe `{ file: "https://.../video.m3u8" }`.
 * 3. **Iframes de embed.** `<iframe src="...">` apontando para um player
 *    incorporado (YouTube, Dailymotion, Vimeo, OK.ru, Streamable...). O iframe
 *    não é o arquivo, mas é **prova de que a página tem player** — e é o que
 *    separa uma página de streaming de um fórum que só fala do título.
 * 4. **URLs soltas.** Qualquer `https://...mp4` ou `.m3u8` no corpo, inclusive
 *    dentro de strings JavaScript escapadas (`https:\/\/...`). É a rede de
 *    segurança para players que montam a URL por concatenação.
 *
 * O resultado é deduplicado e filtrado por extensão: só sai daqui o que o
 * media-service consegue abrir. Um link que não é vídeo (um `.jpg`, um `.css`)
 * é descartado em vez de oferecido ao player.
 *
 * ## A prova de mídia é o critério de aceitação
 *
 * [`temMidia()`] responde à pergunta que o [`ProvedorStreamDireto`] faz antes de
 * aceitar uma página: "aqui tem player?". A resposta não depende do domínio — um
 * site desconhecido que embuta um iframe de player ou um `.m3u8` passa; um
 * agregador famoso que devolva só texto não passa. É essa prova, e não uma lista
 * fixa de domínios, que decide o que vira fonte.
 */
class ExtratorVideo
{
    /**
     * Extensões que provam que a URL é um vídeo tocável.
     *
     * A lista é a mesma do provedor: um link que não casa aqui não é oferecido.
     * `.m3u8` e `.mpd` são playlists (HLS e DASH); `.mp4`, `.m4v`, `.webm` e
     * `.mkv` são arquivos progressivos.
     */
    private const EXTENSOES = [
        'mp4',
        'm4v',
        'webm',
        'mkv',
        'm3u8',
        'mpd',
    ];

    /**
     * Extensões que provam que a URL **não** é mídia tocável.
     *
     * A prova de mídia por iframe genérico e por atributo `data-*` aceita
     * qualquer URL absoluta que não seja de host ignorado — o que é necessário
     * para os players de domínio próprio. O efeito colateral é que imagens de
     * capa entram junto: o `data-src` de um `<img>` de lazy loading é uma URL
     * absoluta como qualquer outra, e o `image.tmdb.org` devolveu as capas da
     * série como se fossem fontes de vídeo.
     *
     * A lista corta isso na origem. Um `.jpg` nunca é player, então a URL é
     * recusada antes de virar fonte — mesmo que venha de um `data-src` ou de um
     * iframe de host desconhecido.
     *
     * @var array<int, string>
     */
    private const EXTENSOES_NAO_MIDIA = [
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp',
        'avif',
        'bmp',
        'svg',
        'ico',
        'css',
        'js',
        'json',
        'xml',
        'woff',
        'woff2',
        'ttf',
        'eot',
        'pdf',
        'zip',
        'rar',
    ];

    /**
     * Chaves de configuração de player que costumam carregar a URL do vídeo.
     *
     * A ordem não importa — todas são varridas. O `file` é do JW Player; o `src`
     * e o `source` são genéricos; o `hls` e o `dash` são de players que separam
     * os dois protocolos; o `sources` é uma lista (Video.js, Plyr).
     */
    private const CHAVES_DE_PLAYER = [
        'file',
        'src',
        'source',
        'sources',
        'hls',
        'dash',
        'url',
        'video',
        'videoUrl',
        'video_url',
        'playlist',
    ];

    /**
     * Atributos `data-*` que os players carregados por JavaScript usam para
     * guardar a URL do vídeo antes de montar o `<video>`.
     *
     * É o caso do `pobreflix.bike` e de boa parte dos sites de streaming PT-BR:
     * o HTML estático não traz `<video src>`, só um `<div data-video="...">` ou
     * um `<iframe data-src="...">` que o script da página transforma em player
     * depois que o navegador executa. Sem olhar esses atributos, a página é
     * descartada por "sem prova de mídia" mesmo tendo o episódio.
     *
     * @var array<int, string>
     */
    private const ATRIBUTOS_DE_DADOS = [
        'data-src',
        'data-video',
        'data-video-src',
        'data-video-url',
        'data-file',
        'data-url',
        'data-player',
        'data-embed',
        'data-embed-url',
        'data-hls',
        'data-dash',
        'data-mp4',
        'data-m3u8',
        'data-stream',
        'data-stream-url',
        'data-source',
        'data-sources',
        'data-lazy-src',
        'data-original',
    ];

    /**
     * Hosts que embutem iframe mas **não** são player: anúncio, métrica,
     * captcha e widget social.
     *
     * A prova de mídia por iframe genérico precisa desta lista para não aceitar
     * uma página de notícia que só tem um iframe de anúncio. O casamento é por
     * sufixo de host, como no resto do extrator.
     *
     * @var array<int, string>
     */
    private const HOSTS_DE_IFRAME_IGNORADOS = [
        'googlesyndication.com',
        'googletagmanager.com',
        'googletagservices.com',
        'google-analytics.com',
        'doubleclick.net',
        'adservice.google.com',
        'image.tmdb.org',
        'adsystem.com',
        'amazon-adsystem.com',
        'taboola.com',
        'outbrain.com',
        'criteo.com',
        'criteo.net',
        'pubmatic.com',
        'rubiconproject.com',
        'openx.net',
        'casalemedia.com',
        'sharethrough.com',
        'teads.tv',
        'smartadserver.com',
        'adform.net',
        'bing.com',
        'facebook.com',
        'fb.com',
        'twitter.com',
        'x.com',
        'linkedin.com',
        'pinterest.com',
        'disqus.com',
        'recaptcha.net',
        'hcaptcha.com',
        'cloudflare.com',
        'stripe.com',
        'paypal.com',
        'hotjar.com',
        'clarity.ms',
        'newrelic.com',
        'sentry.io',
        'intercom.io',
        'zendesk.com',
        'tawk.to',
        'crisp.chat',
        'whatsapp.com',
        't.me',
    ];

    /**
     * Hosts de player que aparecem embutidos por iframe.
     *
     * Um `<iframe>` não entrega o arquivo, mas entrega a **prova** de que a
     * página tem player. É o sinal mais confiável para separar uma página de
     * streaming de uma página *sobre* o título: fórum e enciclopédia não
     * embutem player, e um agregador — mesmo desconhecido — embute.
     *
     * A lista cobre os players incorporáveis mais comuns. O casamento é por
     * sufixo de host, então `www.youtube.com` e `youtube-nocookie.com` entram
     * sem precisar de duas entradas.
     *
     * @var array<int, string>
     */
    private const HOSTS_DE_EMBED = [
        'youtube.com',
        'youtube-nocookie.com',
        'youtu.be',
        'player.vimeo.com',
        'vimeo.com',
        'dailymotion.com',
        'dai.ly',
        'ok.ru',
        'vk.com',
        'vkvideo.ru',
        'archive.org',
        'odysee.com',
        'lbry.tv',
        'streamable.com',
        'sendvid.com',
        'doodstream.com',
        'dood.to',
        'streamtape.com',
        'streamtape.to',
        'mixdrop.co',
        'mixdrop.to',
        'vidoza.net',
        'voe.sx',
        'filemoon.sx',
        'upstream.to',
        'vidmoly.to',
        'mp4upload.com',
        'rutube.ru',
        'bilibili.com',
        'nicovideo.jp',
        'youku.com',
        'iqiyi.com',
        'rumble.com',
        'bitchute.com',
        'veoh.com',
        'metacafe.com',
        'facebook.com',
        'fb.watch',
        'redgifs.com',
        'jwplayer.com',
        'jwplatform.com',
        'brightcove.net',
        'kaltura.com',
        'wistia.com',
        'wistia.net',
        'vidyard.com',
        'loom.com',
        'sproutvideo.com',
        'vzaar.com',
        'twitch.tv',
        'kick.com',
    ];

    /**
     * Extrai todas as URLs de vídeo de um HTML.
     *
     * @return array<int, string>
     */
    public function extrair(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $urls = array_merge(
            $this->dasTagsHtml5($html),
            $this->dosAtributosDeDados($html),
            $this->dosScriptsDePlayer($html),
            $this->dasUrlsSoltas($html),
        );

        return $this->normalizar($urls);
    }

    /**
     * Extrai as URLs de **embed** — iframes de player e atributos `data-*`.
     *
     * O [`extrair()`] só devolve arquivos de vídeo (`.mp4`, `.m3u8`...). Mas
     * muitos sites de streaming PT-BR não expõem o arquivo: entregam um iframe
     * de player (`<iframe src="https://player.site/embed/123">`) ou guardam a
     * URL num atributo `data-*` para o JavaScript montar o player depois. Essas
     * URLs não têm extensão de vídeo, então o `extrair()` as descarta — e a
     * página fica sem fonte mesmo tendo o episódio.
     *
     * Este método devolve exatamente essas URLs, para o provedor montá-las como
     * fonte direta. O media-service sabe converter um embed em HLS, então uma
     * URL de embed é tão útil quanto um `.mp4` para o player.
     *
     * @return array<int, string>
     */
    public function extrairEmbeds(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $embeds = array_merge(
            $this->dosIframesDeEmbed($html),
            $this->dosIframesGenericos($html),
            $this->dosAtributosDeDados($html),
        );

        $limpos = [];

        foreach ($embeds as $embed) {
            $embed = $this->desescapar(trim((string) $embed));

            if (! preg_match('#^https?://#i', $embed)) {
                continue;
            }

            /*
             * A imagem de capa é o falso positivo clássico desta camada. O
             * `data-src` de um `<img>` de lazy loading é uma URL absoluta como
             * qualquer outra, e o `image.tmdb.org` devolveu as capas da série
             * como se fossem fontes de vídeo. Um `.jpg` nunca é player, então a
             * URL é recusada antes de virar fonte.
             */
            if ($this->pareceNaoMidia($embed)) {
                continue;
            }

            $host = strtolower((string) parse_url($embed, PHP_URL_HOST));

            if ($host === '' || $this->hostDeIframeIgnorado($host)) {
                continue;
            }

            $limpos[] = $embed;
        }

        return array_values(array_unique($limpos));
    }

    /**
     * Diz se o HTML tem prova de mídia — player, iframe de embed ou arquivo.
     *
     * É a pergunta que o [`ProvedorStreamDireto`] faz antes de aceitar uma
     * página: "aqui tem player?". A resposta **não** olha o domínio. Um site
     * desconhecido que embuta um iframe de player ou traga um `.m3u8` passa; um
     * agregador famoso que devolva só texto não passa. É essa prova que substitui
     * a lista fixa de domínios como critério de aceitação.
     *
     * A checagem é barata de propósito: primeiro procura um arquivo de vídeo
     * (o sinal mais forte), depois um iframe de player conhecido. Se nenhum dos
     * dois aparece, a página não tem mídia e o orçamento não deve ser gasto nela.
     */
    public function temMidia(string $html): bool
    {
        if (trim($html) === '') {
            return false;
        }

        if ($this->extrair($html) !== []) {
            return true;
        }

        if ($this->dosIframesDeEmbed($html) !== []) {
            return true;
        }

        /*
         * O iframe genérico cobre os sites de streaming PT-BR que não usam
         * nenhum dos players conhecidos: embutem o episódio num iframe de um
         * domínio próprio ou de um host fora da lista. Exigir um host conhecido
         * descartaria esses sites — foi o que aconteceu com o `pobreflix.bike`,
         * que tem o player num iframe próprio. A prova é mais fraca (qualquer
         * iframe que não seja de anúncio), então vem depois das provas fortes.
         */
        if ($this->temIframeGenerico($html)) {
            return true;
        }

        /*
         * Por fim, o embed guardado em atributo `data-*`. O player montado por
         * JavaScript costuma deixar a URL num `<div data-src="...">` ou
         * `<div data-video="...">` que não é iframe e não tem extensão de vídeo
         * — é uma URL de embed (`/embed/123`, `/player/abc`). Sem esta checagem,
         * a página seria descartada por "sem prova de mídia" mesmo com o player
         * a um atributo de distância.
         */
        return $this->temEmbedEmAtributo($html);
    }

    /**
     * Diz se algum atributo `data-*` guarda uma URL de embed de player.
     *
     * Complementa o `temIframeGenerico()`: aqui o embed não está num `<iframe>`,
     * mas num atributo de dados que o JavaScript lê para montar o player. A
     * checagem aceita qualquer URL absoluta que não seja de anúncio — a mesma
     * régua do iframe genérico.
     */
    private function temEmbedEmAtributo(string $html): bool
    {
        foreach ($this->dosAtributosDeDados($html) as $valor) {
            $valor = $this->desescapar(trim((string) $valor));

            if (! preg_match('#^https?://#i', $valor)) {
                continue;
            }

            /*
             * Sem esta recusa, uma página que só tem imagens de capa passaria
             * na prova de mídia: o `data-src` de um `<img>` é uma URL absoluta
             * e o host não está na lista de anúncios. A capa não é player.
             */
            if ($this->pareceNaoMidia($valor)) {
                continue;
            }

            $host = strtolower((string) parse_url($valor, PHP_URL_HOST));

            if ($host === '' || $this->hostDeIframeIgnorado($host)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Camada 1b: atributos `data-*` que guardam a URL do vídeo.
     *
     * É a camada que resgata os players montados por JavaScript. O HTML estático
     * de um site como o `pobreflix.bike` não traz `<video src>`: traz um
     * `<div data-video="https://.../episodio.mp4">` ou um
     * `<iframe data-src="https://player.../embed">` que o script da página
     * transforma em player depois. Sem olhar esses atributos, a página é
     * descartada por "sem prova de mídia" mesmo tendo o episódio.
     *
     * A varredura é por atributo nomeado, e não por qualquer `data-*`, para não
     * capturar lixo como `data-id` ou `data-tracking`. O valor só entra se
     * parecer vídeo (extensão conhecida) ou se for uma URL de embed — a
     * validação fica com o `normalizar()` e o `temIframeGenerico()`.
     *
     * @return array<int, string>
     */
    private function dosAtributosDeDados(string $html): array
    {
        $atributos = implode('|', array_map('preg_quote', self::ATRIBUTOS_DE_DADOS));

        if (! preg_match_all('#\b(?:'.$atributos.')\s*=\s*["\']([^"\']+)["\']#i', $html, $casamentos)) {
            return [];
        }

        return $casamentos[1];
    }

    /**
     * Diz se a página tem um iframe que não é de anúncio nem de widget.
     *
     * É a prova de mídia mais fraca do extrator, e por isso a última a ser
     * consultada. Um iframe de player desconhecido passa; um iframe de anúncio,
     * de métrica, de captcha ou de rede social não passa. Sem essa distinção,
     * uma página de notícia com um banner em iframe seria aceita como fonte.
     */
    private function temIframeGenerico(string $html): bool
    {
        if (! preg_match_all('#<iframe\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']#i', $html, $casamentos)) {
            return false;
        }

        foreach ($casamentos[1] as $src) {
            $src = $this->desescapar(trim((string) $src));

            if (! preg_match('#^https?://#i', $src)) {
                continue;
            }

            if ($this->pareceNaoMidia($src)) {
                continue;
            }

            $host = strtolower((string) parse_url($src, PHP_URL_HOST));

            if ($host === '' || $this->hostDeIframeIgnorado($host)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Diz se o host do iframe é de anúncio, métrica, captcha ou widget social.
     */
    private function hostDeIframeIgnorado(string $host): bool
    {
        foreach (self::HOSTS_DE_IFRAME_IGNORADOS as $ignorado) {
            if ($host === $ignorado || str_ends_with($host, '.'.$ignorado)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Diz se a URL aponta para algo que **nunca** é mídia tocável.
     *
     * A prova de mídia por iframe genérico e por atributo `data-*` aceita
     * qualquer URL absoluta que não seja de host ignorado — o que é necessário
     * para os players de domínio próprio. O efeito colateral é que imagens de
     * capa entram junto: o `data-src` de um `<img>` de lazy loading é uma URL
     * absoluta como qualquer outra, e o `image.tmdb.org` devolveu as capas da
     * série como se fossem fontes de vídeo.
     *
     * A checagem é pela extensão do caminho, sem a query string — o mesmo
     * cuidado do `pareceVideo()`, porque muitos CDNs penduram parâmetros depois
     * do `?`. Um `.jpg`, um `.css` ou um `.js` nunca é player.
     */
    private function pareceNaoMidia(string $url): bool
    {
        $caminho = strtolower((string) parse_url($url, PHP_URL_PATH));

        if ($caminho === '') {
            return false;
        }

        foreach (self::EXTENSOES_NAO_MIDIA as $extensao) {
            if (str_ends_with($caminho, '.'.$extensao)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Camada 1: `<video src>` e `<source src>`.
     *
     * @return array<int, string>
     */
    private function dasTagsHtml5(string $html): array
    {
        $urls = [];

        // <video src="..."> e <source src="...">, em qualquer ordem de atributos.
        if (preg_match_all('#<(?:video|source)\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']#i', $html, $casamentos)) {
            $urls = array_merge($urls, $casamentos[1]);
        }

        // <video><source src="..."></video> já é coberto acima; aqui pegamos o
        // atributo `data-src`, usado por players que carregam o vídeo sob demanda.
        if (preg_match_all('#<(?:video|source)\b[^>]*\bdata-src\s*=\s*["\']([^"\']+)["\']#i', $html, $casamentos)) {
            $urls = array_merge($urls, $casamentos[1]);
        }

        return $urls;
    }

    /**
     * Camada 2: configuração de player JavaScript.
     *
     * Varre as chaves conhecidas (`file:`, `src:`, `sources: [...]`...) e captura
     * o valor string que vem depois. Aceita aspas simples e duplas, e o valor
     * pode estar escapado (`https:\/\/...`), o que é comum em JSON embutido.
     *
     * @return array<int, string>
     */
    private function dosScriptsDePlayer(string $html): array
    {
        $urls = [];
        $chaves = implode('|', array_map('preg_quote', self::CHAVES_DE_PLAYER));

        // Chave seguida de string: file: "https://..." ou src: 'https://...'
        $padraoString = '#\b(?:'.$chaves.')\s*:\s*["\']([^"\']+)["\']#i';

        if (preg_match_all($padraoString, $html, $casamentos)) {
            $urls = array_merge($urls, $casamentos[1]);
        }

        /*
         * `sources: [{ src: "..." }, { file: "..." }]` — a lista do Video.js e do
         * Plyr. O padrão acima já pega cada `src:`/`file:` interno, então aqui só
         * garantimos que listas com quebra de linha também sejam cobertas.
         */
        if (preg_match_all('#\b(?:'.$chaves.')\s*:\s*\[\s*\{[^}]*\}?\s*\]#is', $html, $listas)) {
            foreach ($listas[0] as $lista) {
                if (preg_match_all('#["\']([^"\']+)["\']#', $lista, $internos)) {
                    $urls = array_merge($urls, $internos[1]);
                }
            }
        }

        return $urls;
    }

    /**
     * Camada 3: iframes de embed que apontam para um player conhecido.
     *
     * O iframe não entrega o arquivo, mas entrega a prova de que a página tem
     * player. É o sinal que separa uma página de streaming de uma discussão
     * *sobre* o título: fórum e enciclopédia não embutem player.
     *
     * O casamento é pelo host do `src`, por sufixo — `www.youtube.com` casa com
     * `youtube.com` sem precisar de entrada própria.
     *
     * @return array<int, string>
     */
    private function dosIframesDeEmbed(string $html): array
    {
        if (! preg_match_all('#<iframe\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']#i', $html, $casamentos)) {
            return [];
        }

        $embeds = [];

        foreach ($casamentos[1] as $src) {
            $src = $this->desescapar(trim((string) $src));

            if (! preg_match('#^https?://#i', $src)) {
                continue;
            }

            $host = strtolower((string) parse_url($src, PHP_URL_HOST));

            if ($host === '') {
                continue;
            }

            /*
             * O host de anúncio/widget vence a lista de embeds conhecidos. O
             * Facebook é o caso: ele está em `HOSTS_DE_EMBED` porque hospeda
             * vídeo, mas o mesmo domínio serve o plugin de "curtir" — que não é
             * player. Sem esta precedência, uma página de notícia com o botão
             * social passaria na prova de mídia.
             */
            if ($this->hostDeIframeIgnorado($host)) {
                continue;
            }

            foreach (self::HOSTS_DE_EMBED as $conhecido) {
                if ($host === $conhecido || str_ends_with($host, '.'.$conhecido)) {
                    $embeds[] = $src;

                    break;
                }
            }
        }

        return array_values(array_unique($embeds));
    }

    /**
     * Camada 3b: iframes de player que **não** estão na lista de hosts conhecidos.
     *
     * É o resgate dos sites de streaming PT-BR que usam player próprio. O
     * `pobreflix.bike`, por exemplo, embute o episódio num iframe de um domínio
     * que não é nenhum dos grandes — exigir um host conhecido o descartaria.
     * Aqui qualquer iframe que não seja de anúncio, métrica, captcha ou widget
     * social conta como player.
     *
     * @return array<int, string>
     */
    private function dosIframesGenericos(string $html): array
    {
        if (! preg_match_all('#<iframe\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']#i', $html, $casamentos)) {
            return [];
        }

        $embeds = [];

        foreach ($casamentos[1] as $src) {
            $src = $this->desescapar(trim((string) $src));

            if (! preg_match('#^https?://#i', $src)) {
                continue;
            }

            if ($this->pareceNaoMidia($src)) {
                continue;
            }

            $host = strtolower((string) parse_url($src, PHP_URL_HOST));

            if ($host === '' || $this->hostDeIframeIgnorado($host)) {
                continue;
            }

            $embeds[] = $src;
        }

        return array_values(array_unique($embeds));
    }

    /**
     * Camada 4: URLs soltas no corpo, inclusive escapadas.
     *
     * A regex aceita `https://` e `https:\/\/` (barra escapada em JSON/JS) e
     * captura até a extensão de vídeo, parando antes de aspas, espaço ou `)`.
     *
     * @return array<int, string>
     */
    private function dasUrlsSoltas(string $html): array
    {
        $extensoes = implode('|', self::EXTENSOES);

        $padrao = '#https?:\\\\?/\\\\?/[^\s"\'<>()]+?\.(?:'.$extensoes.')(?:\?[^\s"\'<>()]*)?#i';

        if (! preg_match_all($padrao, $html, $casamentos)) {
            return [];
        }

        return $casamentos[0];
    }

    /**
     * Limpa, filtra e deduplica as URLs capturadas.
     *
     * @param  array<int, string>  $urls
     * @return array<int, string>
     */
    private function normalizar(array $urls): array
    {
        $limpas = [];

        foreach ($urls as $url) {
            $url = $this->desescapar(trim((string) $url));

            if (! $this->pareceVideo($url)) {
                continue;
            }

            $limpas[] = $url;
        }

        return array_values(array_unique($limpas));
    }

    /**
     * Desfaz o escape de barras que aparece em JSON/JS embutido.
     *
     * `https:\/\/exemplo.test\/video.mp4` vira `https://exemplo.test/video.mp4`.
     * Sem isto, a URL capturada da camada 3 chegaria ao media-service inválida.
     */
    private function desescapar(string $url): string
    {
        return str_replace(['\\/', '\\u002F', '\\u002f'], '/', $url);
    }

    /**
     * Diz se a URL aponta para um vídeo tocável.
     *
     * A checagem é pelo caminho, sem a query string: muitos servidores penduram
     * token e expiração depois do `?`, e olhar a URL inteira faria o `.mp4`
     * escapar quando viesse antes do `?` mas com parâmetros depois.
     */
    private function pareceVideo(string $url): bool
    {
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return false;
        }

        $caminho = strtolower((string) parse_url($url, PHP_URL_PATH));

        foreach (self::EXTENSOES as $extensao) {
            if (str_ends_with($caminho, '.'.$extensao)) {
                return true;
            }
        }

        return false;
    }
}
