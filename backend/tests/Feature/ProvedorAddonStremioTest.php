<?php

namespace Tests\Feature;

use App\Enums\IdiomaFonte;
use App\Services\Torrents\OrcamentoBusca;
use App\Services\Torrents\ProvedorAddonStremio;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Trava a leitura do `url` dos addons Stremio como fonte direta.
 *
 * O protocolo Stremio tem dois tipos de stream: o torrent, com `infoHash`, e o
 * **arquivo pronto**, com `url`. Só o primeiro era lido — o segundo era
 * descartado antes de chegar ao player, embora seja exatamente o formato que o
 * contrato da fonte direta aceita (`.mp4`/`.m3u8`). É por ele que os addons
 * entregam vídeo sem passar por malha, e é o caminho que não depende de conta
 * paga nenhuma.
 *
 * A bateria também protege a fronteira: link sem extensão de vídeo — embed,
 * redirecionador, entrega de serviço pago — não vira fonte, e o link direto
 * morto é descartado pela sonda antes de entrar no cache.
 */
class ProvedorAddonStremioTest extends TestCase
{
    private const BASE = 'https://addon.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.torrents.stremio_addons' => [self::BASE],
            'services.torrents.tempo_limite' => 15,
            'services.torrents.stream_direto_sondar_url' => true,
        ]);
    }

    private function provedor(): ProvedorAddonStremio
    {
        return new ProvedorAddonStremio(new OrcamentoBusca);
    }

    /**
     * Um stream direto no formato dos addons: o arquivo vem em `url` e os
     * metadados no rótulo.
     *
     * @return array<string, mixed>
     */
    private function direto(string $url, string $release = 'Filme Dublado 1080p'): array
    {
        return [
            'name' => "AddonTeste\n1080p",
            'title' => $release."\n👤 0 💾 1.4 GB ⚙️ AddonTeste",
            'url' => $url,
        ];
    }

    /**
     * Dubla o addon e, opcionalmente, o CDN do arquivo.
     *
     * @param  array<int, array<string, mixed>>  $streams
     */
    private function dublar(array $streams, ?callable $cdn = null): void
    {
        Http::fake(function (Request $pedido) use ($streams, $cdn) {
            if (str_starts_with($pedido->url(), self::BASE)) {
                return Http::response(['streams' => $streams]);
            }

            if ($cdn !== null) {
                return $cdn($pedido);
            }

            return Http::response('', 200, ['Content-Type' => 'video/mp4']);
        });
    }

    public function test_url_de_mp4_vira_fonte_direta_com_idioma(): void
    {
        $this->dublar([$this->direto('https://cdn.test/filme-dublado.mp4')]);

        $fontes = $this->provedor()->buscar('Filme', 2024, 'tt1234567');

        $this->assertCount(1, $fontes);
        $this->assertSame('direto', $fontes[0]['tipo']);
        $this->assertSame('https://cdn.test/filme-dublado.mp4', $fontes[0]['stream']);
        $this->assertSame(IdiomaFonte::DUBLADO->value, $fontes[0]['idioma']);
        $this->assertSame('', $fontes[0]['magnet']);
        $this->assertGreaterThanOrEqual(1, $fontes[0]['seeds']);
    }

    /**
     * O rótulo do addon não pode transformar legenda em dublagem.
     *
     * Um addon que anuncia "PORTUGUESE.SUBtitles" no nome está dizendo o idioma
     * da **legenda**; o áudio é o original. Antes, a leitura do nome devolvia
     * `portuguese`, o campo vencia a tag e a fonte entrava na lista como dublada.
     */
    public function test_release_com_subtitles_nao_vira_dublado(): void
    {
        $this->dublar([
            $this->direto('https://cdn.test/filme.mp4', 'Movie.2024.PORTUGUESE.SUBtitles.1080p'),
        ]);

        $fontes = $this->provedor()->buscar('Movie', 2024, 'tt1234567');

        $this->assertCount(1, $fontes);
        $this->assertSame(IdiomaFonte::LEGENDADO->value, $fontes[0]['idioma']);
    }

    /**
     * A extensão é lida do caminho: o token na query não pode esconder o `.m3u8`.
     */
    public function test_extensao_lida_pelo_caminho_preserva_a_query(): void
    {
        $url = 'https://cdn.test/serie/ep1.m3u8?token=abc123';

        $this->dublar([$this->direto($url, 'Serie Dublado 720p')], fn () => Http::response(
            "#EXTM3U\n#EXT-X-VERSION:3",
            200,
            ['Content-Type' => 'application/vnd.apple.mpegurl']
        ));

        $fontes = $this->provedor()->buscar('Serie', 2024, 'tt1234567');

        $this->assertCount(1, $fontes);
        $this->assertSame($url, $fontes[0]['stream']);
    }

    /**
     * Link de entrega paga e proxy não terminam em vídeo: ficam fora.
     */
    public function test_url_sem_extensao_de_video_e_recusada(): void
    {
        $this->dublar([
            $this->direto('https://real-debrid.com/d/ABC123'),
            $this->direto('https://addon.test/resolve/ABC123'),
        ]);

        $this->assertSame([], $this->provedor()->buscar('Filme', 2024, 'tt1234567'));
    }

    public function test_url_de_pagina_de_embed_e_recusada(): void
    {
        $this->dublar([$this->direto('https://site.test/filme/12345')]);

        $this->assertSame([], $this->provedor()->buscar('Filme', 2024, 'tt1234567'));
    }

    /**
     * Regressão: o stream com `infoHash` continua entrando como torrent.
     */
    public function test_infohash_continua_virando_torrent(): void
    {
        $this->dublar([[
            'name' => "AddonTeste\n1080p",
            'title' => "Filme Dublado 1080p\n👤 12 💾 1.4 GB",
            'infoHash' => str_repeat('a', 40),
        ]]);

        $fontes = $this->provedor()->buscar('Filme', 2024, 'tt1234567');

        $this->assertCount(1, $fontes);
        $this->assertSame('torrent', $fontes[0]['tipo']);
        $this->assertStringStartsWith('magnet:?xt=urn:btih:', $fontes[0]['magnet']);
        $this->assertSame('', $fontes[0]['stream']);
    }

    /**
     * O link direto morto não chega ao player: a sonda lê o corpo e o descarta.
     */
    public function test_sonda_descarta_link_com_conta_expirada(): void
    {
        $this->dublar(
            [$this->direto('https://cdn.test/filme-dublado.mp4')],
            fn (Request $pedido) => $pedido->method() === 'HEAD'
                ? Http::response('', 403)
                : Http::response('Conta expirada.', 403, ['Content-Type' => 'text/html; charset=UTF-8'])
        );

        $this->assertSame([], $this->provedor()->buscar('Filme', 2024, 'tt1234567'));
    }

    /**
     * Desligada a sonda, o comportamento volta ao antigo: o addon manda.
     */
    public function test_sonda_desligada_mantem_o_link(): void
    {
        config()->set('services.torrents.stream_direto_sondar_url', false);

        $this->dublar(
            [$this->direto('https://cdn.test/filme-dublado.mp4')],
            fn () => Http::response('Conta expirada.', 403, ['Content-Type' => 'text/html'])
        );

        $this->assertCount(1, $this->provedor()->buscar('Filme', 2024, 'tt1234567'));
    }

    /**
     * A numeração continua sendo conferida: episódio errado não entra.
     */
    public function test_serie_descarta_direta_de_outro_episodio(): void
    {
        $this->dublar([$this->direto('https://cdn.test/serie-s01e02.mp4', 'Serie S01E02 Dublado 720p')]);

        $this->assertSame([], $this->provedor()->buscar('Serie', 2024, 'tt1234567', 1, 1));
    }
}
