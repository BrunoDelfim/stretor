<?php

namespace Tests\Feature;

use App\Enums\IdiomaFonte;
use App\Services\TorrentService;
use App\Services\Torrents\BuscaLegendas;
use App\Services\Torrents\CatalogoProvedores;
use App\Services\Torrents\OrcamentoBusca;
use Mockery;
use Tests\TestCase;

/**
 * Trava a regra do fallback legendado.
 *
 * Quando nenhum provedor entrega áudio PT-BR, a busca deve oferecer as fontes de
 * idioma original (diretas e reserva dos torrents) com as legendas anexadas —
 * PT-BR e inglês, ou só inglês quando não houver PT-BR. Sem legenda utilizável, o
 * fallback não se aplica e o comportamento antigo (lista vazia) permanece.
 *
 * Como nos demais testes de montagem, o catálogo e o provedor de legendas são
 * substituídos por dublês: nada aqui toca rede.
 */
class LegendasFallbackTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /** Fonte de torrent já no contrato do frontend. */
    private function fonteTorrent(string $id, string $idioma, int $seeds = 10): array
    {
        return [
            'id' => $id,
            'tipo' => 'torrent',
            'titulo' => "Release {$id}",
            'qualidade' => '1080P',
            'idioma' => $idioma,
            'idioma_rotulo' => $idioma,
            'tamanho' => '1,0 GB',
            'seeds' => $seeds,
            'peers' => 2,
            'magnet' => 'magnet:?xt=urn:btih:'.str_pad($id, 40, 'a'),
            'stream' => '',
            'provedor' => 'yts',
            'provedor_rotulo' => 'YTS',
        ];
    }

    /** Fonte direta (MP4/HLS) já no contrato do frontend. */
    private function fonteDireta(string $id, string $idioma): array
    {
        return [
            'id' => $id,
            'tipo' => 'direto',
            'titulo' => "Release {$id}",
            'qualidade' => '1080P',
            'idioma' => $idioma,
            'idioma_rotulo' => $idioma,
            'tamanho' => null,
            'seeds' => 1,
            'peers' => 0,
            'magnet' => '',
            'stream' => "https://exemplo.test/{$id}.mp4",
            'provedor' => 'stream_direto',
            'provedor_rotulo' => 'Stream direto',
        ];
    }

    /** Par de legendas — PT-BR primeiro, inglês depois. */
    private function legendaPtEn(): array
    {
        return [
            ['srclang' => 'pt-BR', 'label' => 'Português (Brasil)', 'url' => 'https://vidapi.cloud/subs/x/Brazilian.por.srt', 'origem' => 'vidsrc'],
            ['srclang' => 'en', 'label' => 'English', 'url' => 'https://vidapi.cloud/subs/x/eng.eng.srt', 'origem' => 'vidsrc'],
        ];
    }

    /**
     * Monta o serviço com catálogo e provedor de legendas dublados.
     *
     * @param  array<int, array<string, mixed>>  $fontesCatalogo  o que a cascata devolve
     * @param  array<int, array<string, mixed>>  $diretas         o que o stream direto devolve
     * @param  array<int, array<string, mixed>>  $legendas        o que o provedor de legendas devolve
     */
    private function servico(array $fontesCatalogo, array $diretas, array $legendas): TorrentService
    {
        $catalogo = Mockery::mock(CatalogoProvedores::class);
        $catalogo->shouldReceive('buscar')->andReturn($fontesCatalogo);
        $catalogo->shouldReceive('buscarFallbackDireto')->andReturn($diretas);
        $catalogo->shouldReceive('ptBrSuficiente')->andReturnFalse();
        $catalogo->shouldReceive('temDublado')->andReturnFalse();
        $catalogo->shouldReceive('reconciliarCenso')->andReturnNull();
        $catalogo->shouldReceive('fecharOrcamento')->andReturnNull();

        $provedor = Mockery::mock(BuscaLegendas::class);
        $provedor->shouldReceive('buscar')->andReturn($legendas);

        return new TorrentService($catalogo, new OrcamentoBusca(), $provedor);
    }

    /** Sem PT-BR: a reserva original vira a resposta, com as legendas anexadas. */
    public function test_sem_pt_br_serve_o_original_com_legendas(): void
    {
        $servico = $this->servico(
            [$this->fonteTorrent('orig', IdiomaFonte::ORIGINAL->value)],
            [],
            $this->legendaPtEn()
        );

        $fontes = $servico->fontes('Some Movie', 2020, 'tt1234567');

        $this->assertCount(1, $fontes);
        $this->assertSame('orig', $fontes[0]['id']);
        $this->assertArrayHasKey('legendas', $fontes[0]);
        $this->assertCount(2, $fontes[0]['legendas']);
        $this->assertSame('pt-BR', $fontes[0]['legendas'][0]['srclang'], 'A PT-BR vem primeiro.');
        $this->assertSame('en', $fontes[0]['legendas'][1]['srclang']);
    }

    /** Sem legenda utilizável, o original não é servido: comportamento antigo. */
    public function test_sem_legenda_o_original_nao_e_servido(): void
    {
        $servico = $this->servico(
            [$this->fonteTorrent('orig', IdiomaFonte::ORIGINAL->value)],
            [],
            []
        );

        $this->assertSame([], $servico->fontes('Some Movie', 2020, 'tt1234567'));
    }

    /** Com PT-BR disponível, o fallback nem entra: a lista é só o dublado. */
    public function test_com_pt_br_o_fallback_nao_entra(): void
    {
        $servico = $this->servico(
            [$this->fonteTorrent('dublado', IdiomaFonte::DUBLADO->value, 30)],
            [],
            $this->legendaPtEn()
        );

        $fontes = $servico->fontes('Some Movie', 2020, 'tt1234567');

        $this->assertCount(1, $fontes);
        $this->assertSame('dublado', $fontes[0]['id']);
        $this->assertArrayNotHasKey('legendas', $fontes[0], 'Fonte PT-BR não leva legenda.');
    }

    /** A fonte direta original também entra no fallback. */
    public function test_fonte_direta_original_entra_no_fallback(): void
    {
        $servico = $this->servico(
            [],
            [$this->fonteDireta('direta1', IdiomaFonte::ORIGINAL->value)],
            $this->legendaPtEn()
        );

        $fontes = $servico->fontes('Some Movie', 2020, 'tt1234567');

        $this->assertCount(1, $fontes);
        $this->assertSame('direta1', $fontes[0]['id']);
        $this->assertArrayHasKey('legendas', $fontes[0]);
    }
}
