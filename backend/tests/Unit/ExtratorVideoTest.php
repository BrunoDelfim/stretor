<?php

namespace Tests\Unit;

use App\Services\Torrents\ExtratorVideo;
use PHPUnit\Framework\TestCase;

/**
 * Trava a extração de vídeo do scraper de stream direto.
 *
 * O extrator é a peça que transforma o HTML de uma página de streaming numa URL
 * tocável. Ele lida com três formatos que os sites usam na prática — a tag
 * HTML5, a configuração de player JS e a URL solta no corpo — e o risco é
 * silencioso: uma regex frouxa demais oferece ao player um link que não é vídeo;
 * uma restrita demais deixa passar o `.m3u8` que estava ali.
 *
 * Nada aqui toca em rede: o extrator é puro, recebe HTML e devolve URLs.
 */
class ExtratorVideoTest extends TestCase
{
    private function extrator(): ExtratorVideo
    {
        return new ExtratorVideo();
    }

    /**
     * O caminho mais direto: a tag `<video src>`.
     */
    public function test_extrai_video_src_da_tag_html5(): void
    {
        $html = '<html><body><video src="https://cdn.test/filme.mp4" controls></video></body></html>';

        $urls = $this->extrator()->extrair($html);

        $this->assertContains('https://cdn.test/filme.mp4', $urls);
    }

    /**
     * O `<source>` dentro do `<video>` é o caminho de quem declara vários
     * formatos; o extrator precisa pegar todos.
     */
    public function test_extrai_source_dentro_do_video(): void
    {
        $html = <<<'HTML'
        <video controls>
            <source src="https://cdn.test/filme.webm" type="video/webm">
            <source src="https://cdn.test/filme.mp4" type="video/mp4">
        </video>
        HTML;

        $urls = $this->extrator()->extrair($html);

        $this->assertContains('https://cdn.test/filme.webm', $urls);
        $this->assertContains('https://cdn.test/filme.mp4', $urls);
    }

    /**
     * O JW Player recebe o vídeo numa configuração JS: `file: "..."`. É o
     * formato mais comum nos sites que não usam a tag HTML5.
     */
    public function test_extrai_file_do_jw_player(): void
    {
        $html = <<<'HTML'
        <script>
            jwplayer("player").setup({
                file: "https://cdn.test/serie.m3u8",
                image: "https://cdn.test/capa.jpg"
            });
        </script>
        HTML;

        $urls = $this->extrator()->extrair($html);

        $this->assertContains('https://cdn.test/serie.m3u8', $urls);
        $this->assertNotContains('https://cdn.test/capa.jpg', $urls, 'A imagem de capa não é vídeo.');
    }

    /**
     * O Video.js e o Plyr usam `sources: [{ src: "..." }]`. O extrator precisa
     * varrer a lista e pegar cada `src`.
     */
    public function test_extrai_sources_do_video_js(): void
    {
        $html = <<<'HTML'
        <script>
            player.src({
                sources: [
                    { src: "https://cdn.test/ep1.m3u8", type: "application/x-mpegURL" },
                    { src: "https://cdn.test/ep1.mp4", type: "video/mp4" }
                ]
            });
        </script>
        HTML;

        $urls = $this->extrator()->extrair($html);

        $this->assertContains('https://cdn.test/ep1.m3u8', $urls);
        $this->assertContains('https://cdn.test/ep1.mp4', $urls);
    }

    /**
     * A URL solta no corpo, inclusive com barras escapadas (`https:\/\/`), que é
     * como o JSON embutido costuma trazê-la.
     */
    public function test_extrai_url_escapada_no_corpo(): void
    {
        $html = '<script>var video = "https:\/\/cdn.test\/escondido.mp4";</script>';

        $urls = $this->extrator()->extrair($html);

        $this->assertContains('https://cdn.test/escondido.mp4', $urls);
    }

    /**
     * A URL com query string depois da extensão precisa ser preservada inteira:
     * o token de acesso mora ali, e cortá-lo faria o media-service tomar 403.
     */
    public function test_preserva_query_string_do_token(): void
    {
        $html = '<video src="https://cdn.test/filme.mp4?token=abc123&exp=999"></video>';

        $urls = $this->extrator()->extrair($html);

        $this->assertContains('https://cdn.test/filme.mp4?token=abc123&exp=999', $urls);
    }

    /**
     * O controle: um link que não é vídeo não pode sair do extrator. Sem este
     * corte, a página ofereceria ao player um `.jpg` ou um `.css` e a sessão
     * falharia no proxy.
     */
    public function test_descarta_links_que_nao_sao_video(): void
    {
        $html = <<<'HTML'
        <img src="https://cdn.test/capa.jpg">
        <link rel="stylesheet" href="https://cdn.test/estilo.css">
        <script src="https://cdn.test/app.js"></script>
        HTML;

        $urls = $this->extrator()->extrair($html);

        $this->assertSame([], $urls, 'Nenhum link que não seja vídeo pode sair do extrator.');
    }

    /**
     * A mesma URL em dois lugares (tag e script) vira uma só: o extrator
     * deduplica para não inflar a lista de fontes com o mesmo link.
     */
    public function test_deduplica_a_mesma_url(): void
    {
        $html = <<<'HTML'
        <video src="https://cdn.test/filme.mp4"></video>
        <script>player.setup({ file: "https://cdn.test/filme.mp4" });</script>
        HTML;

        $urls = $this->extrator()->extrair($html);

        $this->assertCount(1, $urls);
        $this->assertSame('https://cdn.test/filme.mp4', $urls[0]);
    }

    /**
     * HTML vazio devolve lista vazia, sem erro.
     */
    public function test_html_vazio_devolve_lista_vazia(): void
    {
        $this->assertSame([], $this->extrator()->extrair(''));
        $this->assertSame([], $this->extrator()->extrair('   '));
    }

    /**
     * O player montado por JavaScript guarda a URL num atributo `data-*`. É o
     * caso do `pobreflix.bike`: o HTML estático não traz `<video src>`, só um
     * `<div data-video="...">` que o script transforma em player depois.
     */
    public function test_extrai_video_de_atributo_data(): void
    {
        $html = '<div class="player" data-video="https://cdn.test/episodio.mp4"></div>';

        $urls = $this->extrator()->extrair($html);

        $this->assertContains('https://cdn.test/episodio.mp4', $urls);
    }

    /**
     * O `data-src` é o atributo de lazy-load mais comum: o player só carrega o
     * vídeo quando o usuário interage, e a URL fica guardada até lá.
     */
    public function test_extrai_video_de_data_src(): void
    {
        $html = '<video data-src="https://cdn.test/lazy.mp4"></video>';

        $urls = $this->extrator()->extrair($html);

        $this->assertContains('https://cdn.test/lazy.mp4', $urls);
    }

    /**
     * O `data-*` com valor escapado (`https:\/\/`) precisa ser desescapado, como
     * no resto do extrator.
     */
    public function test_desescapar_atributo_data(): void
    {
        $html = '<div data-file="https:\/\/cdn.test\/escapado.mp4"></div>';

        $urls = $this->extrator()->extrair($html);

        $this->assertContains('https://cdn.test/escapado.mp4', $urls);
    }

    /**
     * O controle: um `data-*` que não é de vídeo (um `data-id`, um
     * `data-tracking`) não pode virar fonte.
     */
    public function test_atributo_data_que_nao_e_video_e_ignorado(): void
    {
        $html = '<div data-id="123" data-tracking="abc" data-nome="filme"></div>';

        $urls = $this->extrator()->extrair($html);

        $this->assertSame([], $urls);
    }

    /**
     * O iframe de player conhecido é prova de mídia mesmo sem arquivo de vídeo.
     */
    public function test_iframe_de_player_conhecido_e_prova_de_midia(): void
    {
        $html = '<iframe src="https://player.vimeo.com/video/123"></iframe>';

        $this->assertTrue($this->extrator()->temMidia($html));
    }

    /**
     * O iframe de player **desconhecido** também é prova de mídia. É o resgate
     * dos sites de streaming PT-BR que usam player próprio — exigir um host
     * conhecido descartaria o `pobreflix.bike`.
     */
    public function test_iframe_de_player_desconhecido_e_prova_de_midia(): void
    {
        $html = '<iframe src="https://player.pobreflix.bike/embed/american-horror-story-1x01"></iframe>';

        $this->assertTrue($this->extrator()->temMidia($html));
    }

    /**
     * O controle: um iframe de anúncio não é prova de mídia. Sem esta distinção,
     * uma página de notícia com banner seria aceita como fonte.
     */
    public function test_iframe_de_anuncio_nao_e_prova_de_midia(): void
    {
        $html = '<iframe src="https://googleads.g.doubleclick.net/pagead/ads"></iframe>';

        $this->assertFalse($this->extrator()->temMidia($html));
    }

    /**
     * O iframe de rede social também não é prova de mídia.
     */
    public function test_iframe_de_rede_social_nao_e_prova_de_midia(): void
    {
        $html = '<iframe src="https://www.facebook.com/plugins/like.php"></iframe>';

        $this->assertFalse($this->extrator()->temMidia($html));
    }

    /**
     * O embed guardado em atributo `data-*` (sem extensão de vídeo) é prova de
     * mídia: é uma URL de player que o JavaScript vai montar.
     */
    public function test_embed_em_atributo_data_e_prova_de_midia(): void
    {
        $html = '<div data-src="https://player.site/embed/123"></div>';

        $this->assertTrue($this->extrator()->temMidia($html));
    }

    /**
     * O `extrairEmbeds()` devolve as URLs de embed — iframes e atributos — que o
     * `extrair()` descarta por não terem extensão de vídeo.
     */
    public function test_extrair_embeds_devolve_iframes_e_atributos(): void
    {
        $html = <<<'HTML'
        <iframe src="https://player.site/embed/123"></iframe>
        <div data-src="https://outro.player/embed/abc"></div>
        HTML;

        $embeds = $this->extrator()->extrairEmbeds($html);

        $this->assertContains('https://player.site/embed/123', $embeds);
        $this->assertContains('https://outro.player/embed/abc', $embeds);
    }

    /**
     * O `extrairEmbeds()` não devolve iframe de anúncio nem de rede social.
     */
    public function test_extrair_embeds_ignora_anuncios(): void
    {
        $html = <<<'HTML'
        <iframe src="https://googleads.g.doubleclick.net/pagead/ads"></iframe>
        <iframe src="https://www.facebook.com/plugins/like.php"></iframe>
        HTML;

        $this->assertSame([], $this->extrator()->extrairEmbeds($html));
    }

    /**
     * HTML vazio devolve lista vazia também no `extrairEmbeds()`.
     */
    public function test_extrair_embeds_com_html_vazio(): void
    {
        $this->assertSame([], $this->extrator()->extrairEmbeds(''));
        $this->assertSame([], $this->extrator()->extrairEmbeds('   '));
    }

    /**
     * A capa do TMDB num `data-src` não pode virar fonte de vídeo.
     *
     * Foi o bug real: o `data-src` de um `<img>` de lazy loading é uma URL
     * absoluta como qualquer outra, e as capas da série entraram como se fossem
     * fontes diretas.
     */
    public function test_extrair_embeds_ignora_imagem_de_capa(): void
    {
        $html = <<<'HTML'
        <img data-src="https://image.tmdb.org/t/p/w185/tJnhBV516yrekR3hTDUw15UMbxS.jpg">
        <img data-original="https://exemplo.test/capa.png">
        HTML;

        $this->assertSame([], $this->extrator()->extrairEmbeds($html));
    }

    /**
     * Uma página que só tem imagens não tem prova de mídia.
     */
    public function test_pagina_so_com_imagens_nao_tem_midia(): void
    {
        $html = <<<'HTML'
        <img data-src="https://image.tmdb.org/t/p/w185/tJnhBV516yrekR3hTDUw15UMbxS.jpg">
        <img data-src="https://image.tmdb.org/t/p/w185/m9okq20ca2Md38i2ekJeKs3vTwa.jpg">
        HTML;

        $this->assertFalse($this->extrator()->temMidia($html));
    }

    /**
     * O iframe de imagem não é player, mesmo vindo de host desconhecido.
     */
    public function test_iframe_de_imagem_nao_e_prova_de_midia(): void
    {
        $html = '<iframe src="https://cdn.exemplo.test/banner.jpg"></iframe>';

        $this->assertFalse($this->extrator()->temMidia($html));
    }

    /**
     * A imagem com query string continua sendo recusada.
     *
     * Muitos CDNs penduram parâmetros depois do `?`; a checagem é pela extensão
     * do caminho, então o `.jpg` antes do `?` ainda barra a URL.
     */
    public function test_imagem_com_query_string_e_recusada(): void
    {
        $html = '<div data-src="https://cdn.exemplo.test/capa.jpg?w=185&q=80"></div>';

        $this->assertSame([], $this->extrator()->extrairEmbeds($html));
    }

    /**
     * O player de verdade continua passando depois da nova regra.
     *
     * A recusa de imagem não pode derrubar o embed legítimo: um iframe de player
     * sem extensão de arquivo segue sendo prova de mídia.
     */
    public function test_player_sem_extensao_continua_valendo(): void
    {
        $html = '<iframe src="https://player.desconhecido.test/embed/abc123"></iframe>';

        $this->assertTrue($this->extrator()->temMidia($html));
        $this->assertContains('https://player.desconhecido.test/embed/abc123', $this->extrator()->extrairEmbeds($html));
    }
}
