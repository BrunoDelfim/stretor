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
}
