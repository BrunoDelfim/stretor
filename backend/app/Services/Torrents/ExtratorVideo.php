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
            $this->dosScriptsDePlayer($html),
            $this->dasUrlsSoltas($html),
        );

        return $this->normalizar($urls);
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

        return $this->dosIframesDeEmbed($html) !== [];
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
