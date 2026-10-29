<?php

namespace Tests\Unit;

use App\Support\PlataformasPagas;
use Tests\TestCase;

/**
 * Trava a barreira das plataformas de assinatura.
 *
 * O caso que motivou a classe: a ficha de "Cara-de-Barro" no MUBI foi visitada
 * pela busca web, o extrator achou os `splash-videos` do site e a fonte entrou
 * na lista (ou o orçamento foi gasto à toa) sem que houvesse filme para tocar.
 * O conteúdo é protegido por DRM: não existe contorno do lado do servidor, e a
 * única defesa real é não oferecer a fonte.
 */
class PlataformasPagasTest extends TestCase
{
    public function test_plataformas_de_assinatura_sao_bloqueadas(): void
    {
        $urls = [
            'ficha do MUBI' => 'https://mubi.com/pt/us/films/clayface',
            'splash do MUBI' => 'https://assets.mubicdn.net/splash-videos/42/1790975541_video_h264_desktop.mp4',
            'portal da Netflix' => 'https://www.netflix.com/br/title/81040344',
            'CDN da Netflix' => 'https://ipv4-c001-gru001.1.rackcdn.nflxvideo.net/range/0-1000?o=1',
            'Prime Video' => 'https://www.primevideo.com/detail/0ABC',
            'CDN do Prime Video' => 'https://d1abc.aiv-cdn.net/video/x.m3u8',
            'Disney+' => 'https://www.disneyplus.com/pt-br/series/x',
            'Max' => 'https://www.max.com/br/pt/shows/x',
            'Globoplay' => 'https://globoplay.globo.com/v/1234567/',
            'Crunchyroll' => 'https://www.crunchyroll.com/pt-br/series/x',
            'Apple TV' => 'https://tv.apple.com/br/movie/x',
            'Pluto TV' => 'https://pluto.tv/br/on-demand/movies/x',
        ];

        foreach ($urls as $caso => $url) {
            $this->assertTrue(PlataformasPagas::bloqueiaUrl($url), "Deveria bloquear ({$caso}): {$url}");
        }
    }

    public function test_agregadores_nao_sao_confundidos_com_plataforma(): void
    {
        $urls = [
            'domínio parecido' => 'https://naomubi.com/filme',
            'outro TLD' => 'https://mubi.tv/filme',
            'agregador do acervo' => 'https://www.pobreflixtv.democrat/filme-x',
            'CDN do agregador' => 'https://cdnst73.tokyvideo.com/videos/684/684511/mp4/abc.mp4',
            'URL relativa' => '/filme/x.mp4',
            'URL vazia' => '',
        ];

        foreach ($urls as $caso => $url) {
            $this->assertFalse(PlataformasPagas::bloqueiaUrl($url), "Não deveria bloquear ({$caso}): {$url}");
        }
    }

    /** Host vazio não pode virar bloqueio: sem host não há como decidir. */
    public function test_host_vazio_nao_bloqueia(): void
    {
        $this->assertFalse(PlataformasPagas::bloqueiaHost(''));
        $this->assertFalse(PlataformasPagas::bloqueiaHost('   '));
    }
}
