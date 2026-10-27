<?php

namespace Tests\Unit;

use App\Services\Torrents\ResolvedorPlayerflix;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O catálogo por id é a segunda fonte do passo zero: em vez de descobrir a
 * página do título, ele entrega o id do TMDB e recebe a lista de players, já
 * etiquetados por idioma. Cada teste aqui trava um pedaço dessa cadeia — a
 * consulta montada para filme e para episódio, a recusa do que não é português,
 * a recusa do embed sem receita e a leitura da página do watchplay — sem tocar
 * na rede.
 *
 * O que a bateria protege, antes de tudo, é o **silêncio**: um id inválido, um
 * catálogo sem o título, um player que não entrega mídia ou um idioma diferente
 * de PT-BR viram `null`, e a busca segue para o motor e os agregadores como se o
 * passo zero não existisse.
 */
class ResolvedorPlayerflixTest extends TestCase
{
    private const HOST = 'https://playerflix.test';

    private const EMBED = 'https://v1.watchplay.shop/tvshow/93405/1/1';

    private const URL_HLS = 'https://cdn.test/st/_s3_/t02/93405/s1e1/playlist.m3u8?md5=abc&expires=1';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.torrents.stream_direto_playerflix_host', self::HOST);
        config()->set('services.torrents.stream_direto_resolver_playerflix', true);
        config()->set('services.torrents.stream_direto_sondar_url', true);
    }

    private function resolvedor(): ResolvedorPlayerflix
    {
        return app(ResolvedorPlayerflix::class);
    }

    /**
     * Resposta do catálogo, no formato que o playerflix devolve.
     *
     * @param  array<int, array<string, mixed>>  $opcoes
     * @return array<string, mixed>
     */
    private function catalogo(array $opcoes): array
    {
        return ['status' => true, 'data' => ['options' => $opcoes]];
    }

    /**
     * Página do watchplay: o player inteiro em HTML, com a URL já assinada.
     *
     * A URL entra escapada como JSON (`https:\/\/...`), que é como ela chega de
     * verdade — a leitura do provedor precisa desescapar antes de devolver.
     */
    private function paginaDoWatchplay(string $url, ?string $audio = 'Dublado'): string
    {
        $declaracao = $audio === null ? '' : 'window.MyPlayerAudio="'.$audio.'";';

        return '<html><script>'.$declaracao
            .'createMyPlayer({container:"#tv-player",id:"tvchannel-1",url:"'
            .str_replace('/', '\/', $url)
            .'",type:\'m3u8\',skin:"netflix"});</script></html>';
    }

    /**
     * @param  array<string, mixed>  $opcao
     * @return array<string, mixed>
     */
    private function opcao(string $embed, string $idioma): array
    {
        return ['embed' => $embed, 'lang' => $idioma, 'label' => 'Premium', 'id' => 'watchplay'];
    }

    public function test_episodio_consulta_type_tv_e_devolve_hls_dublado(): void
    {
        Http::fake([
            self::HOST.'/inc/Ajax.php*' => Http::response($this->catalogo([
                $this->opcao(self::EMBED, 'pt-br'),
            ])),
            'https://v1.watchplay.shop/*' => Http::response($this->paginaDoWatchplay(self::URL_HLS)),
        ]);

        $resolvido = $this->resolvedor()->resolver('93405', 1, 1, 10);

        $this->assertNotNull($resolvido);
        $this->assertSame(self::URL_HLS, $resolvido['url']);
        $this->assertSame('Dublado', $resolvido['idioma']);

        Http::assertSent(function ($requisicao): bool {
            if (! str_contains($requisicao->url(), '/inc/Ajax.php')) {
                return false;
            }

            parse_str((string) parse_url($requisicao->url(), PHP_URL_QUERY), $consulta);

            return $consulta['type'] === 'tv'
                && $consulta['id'] === '93405'
                && $consulta['season'] === '1'
                && $consulta['episode'] === '1'
                && $requisicao->hasHeader('X-Requested-With');
        });
    }

    public function test_filme_consulta_type_movie_sem_temporada(): void
    {
        Http::fake([
            self::HOST.'/inc/Ajax.php*' => Http::response($this->catalogo([
                $this->opcao('https://v1.watchplay.shop/movie/969681', 'pt-br'),
            ])),
            'https://v1.watchplay.shop/*' => Http::response($this->paginaDoWatchplay(self::URL_HLS)),
        ]);

        $this->assertNotNull($this->resolvedor()->resolver('969681', null, null, 10));

        Http::assertSent(function ($requisicao): bool {
            if (! str_contains($requisicao->url(), '/inc/Ajax.php')) {
                return false;
            }

            parse_str((string) parse_url($requisicao->url(), PHP_URL_QUERY), $consulta);

            return $consulta['type'] === 'movie'
                && $consulta['id'] === '969681'
                && ! array_key_exists('season', $consulta)
                && ! array_key_exists('episode', $consulta);
        });
    }

    /**
     * O catálogo lista primeiro quem paga mais, não quem funciona. Como só o
     * watchplay tem receita, ele precisa ser tentado antes — e a opção em
     * inglês, nem isso.
     */
    public function test_opcao_em_ingles_e_descartada_e_a_conhecida_vem_primeiro(): void
    {
        Http::fake([
            self::HOST.'/inc/Ajax.php*' => Http::response($this->catalogo([
                $this->opcao('https://superflixapi.fun/filme/969681', 'en-us'),
                $this->opcao('https://superflixapi.fun/filme/969681', 'pt-br'),
                $this->opcao('https://v1.watchplay.shop/movie/969681', 'pt-br'),
            ])),
            'https://v1.watchplay.shop/*' => Http::response($this->paginaDoWatchplay(self::URL_HLS)),
        ]);

        $resolvido = $this->resolvedor()->resolver('969681', null, null, 10);

        $this->assertNotNull($resolvido);
        $this->assertSame(self::URL_HLS, $resolvido['url']);

        Http::assertNotSent(fn ($requisicao): bool => str_contains($requisicao->url(), 'superflixapi'));
    }

    /**
     * Embed em português sem receita não é fonte: o passo zero prefere devolver
     * `null` a entregar uma página ao player.
     */
    public function test_embed_sem_receita_conhecida_nao_vira_fonte(): void
    {
        Http::fake([
            self::HOST.'/inc/Ajax.php*' => Http::response($this->catalogo([
                $this->opcao('https://superflixapi.fun/serie/93405/1/1', 'pt-br'),
                $this->opcao('https://embedplayer2.xyz/video/b6cccd4408cd010bd0c88bbeec748e79', 'pt-br'),
            ])),
        ]);

        $this->assertNull($this->resolvedor()->resolver('93405', 1, 1, 10));

        Http::assertNotSent(fn ($requisicao): bool => str_contains($requisicao->url(), 'watchplay'));
    }

    public function test_player_sem_url_de_midia_devolve_null(): void
    {
        Http::fake([
            self::HOST.'/inc/Ajax.php*' => Http::response($this->catalogo([
                $this->opcao(self::EMBED, 'pt-br'),
            ])),
            'https://v1.watchplay.shop/*' => Http::response('<html><body>Conteúdo indisponível</body></html>'),
        ]);

        $this->assertNull($this->resolvedor()->resolver('93405', 1, 1, 10));
    }

    public function test_formato_nao_suportado_e_descartado(): void
    {
        Http::fake([
            self::HOST.'/inc/Ajax.php*' => Http::response($this->catalogo([
                $this->opcao(self::EMBED, 'pt-br'),
            ])),
            'https://v1.watchplay.shop/*' => Http::response(
                $this->paginaDoWatchplay('https://cdn.test/video/manifest.mpd')
            ),
        ]);

        $this->assertNull($this->resolvedor()->resolver('93405', 1, 1, 10));
    }

    public function test_sem_audio_declarado_vale_o_idioma_do_acervo(): void
    {
        Http::fake([
            self::HOST.'/inc/Ajax.php*' => Http::response($this->catalogo([
                $this->opcao(self::EMBED, 'pt-br'),
            ])),
            'https://v1.watchplay.shop/*' => Http::response($this->paginaDoWatchplay(self::URL_HLS, null)),
        ]);

        $resolvido = $this->resolvedor()->resolver('93405', 1, 1, 10);

        $this->assertNotNull($resolvido);
        $this->assertSame('pt-BR', $resolvido['idioma']);
    }

    public function test_catalogo_sem_o_titulo_devolve_null(): void
    {
        Http::fake([
            self::HOST.'/inc/Ajax.php*' => Http::response(['status' => false]),
        ]);

        $this->assertNull($this->resolvedor()->resolver('693', 1, 1, 10));
    }

    public function test_id_nao_numerico_devolve_null_sem_consultar(): void
    {
        Http::fake();

        $this->assertNull($this->resolvedor()->resolver('tt0410975', null, null, 10));

        Http::assertNothingSent();
    }

    public function test_resolvedor_desligado_devolve_null_sem_consultar(): void
    {
        config()->set('services.torrents.stream_direto_resolver_playerflix', false);

        Http::fake();

        $this->assertNull($this->resolvedor()->resolver('969681', null, null, 10));

        Http::assertNothingSent();
    }
}
