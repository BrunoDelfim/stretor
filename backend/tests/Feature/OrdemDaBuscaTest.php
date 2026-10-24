<?php

namespace Tests\Feature;

use App\Services\Torrents\CatalogoProvedores;
use App\Services\Torrents\OrcamentoBusca;
use App\Services\TorrentService;
use Mockery;
use Tests\TestCase;

/**
 * Trava a ordem da busca: identificadores primeiro, scraper web depois.
 *
 * O Torrentio e os addons Stremio respondem pelo `imdb_id` em segundos, sem
 * raspar página; o stream direto paga duas páginas por episódio. Quando a
 * consulta barata já traz fonte PT-BR provada, abrir o canal direto e a cascata
 * só atrasaria o vídeo. Quando ela não traz — ou traz só idioma não provado —,
 * a ordem antiga continua valendo.
 *
 * O catálogo é substituído por um dublê; nada aqui toca em rede ou banco.
 */
class OrdemDaBuscaTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * Uma fonte de torrent PT-BR, como o Torrentio devolve.
     *
     * @return array<string, mixed>
     */
    private function fonteIdentificador(string $idioma = 'pt-BR'): array
    {
        return [
            'id' => str_repeat('b', 40),
            'tipo' => 'torrent',
            'titulo' => 'Filme Raro Dublado 1080p',
            'qualidade' => '1080P',
            'idioma' => $idioma,
            'idioma_rotulo' => $idioma,
            'tamanho' => '1,4 GB',
            'seeds' => 12,
            'peers' => 1,
            'magnet' => 'magnet:?xt=urn:btih:'.str_repeat('b', 40),
            'stream' => '',
            'provedor' => 'torrentio',
            'provedor_rotulo' => 'Torrentio',
        ];
    }

    /**
     * Uma fonte direta PT-BR, como o scraper devolve.
     *
     * @return array<string, mixed>
     */
    private function fonteDireta(): array
    {
        return [
            'id' => 'direta-1',
            'tipo' => 'direto',
            'titulo' => 'Filme Raro Dublado',
            'qualidade' => '1080P',
            'idioma' => 'pt-BR',
            'idioma_rotulo' => 'Dublado',
            'tamanho' => null,
            'seeds' => 1,
            'peers' => 0,
            'magnet' => '',
            'stream' => 'https://exemplo.test/filme.mp4',
            'provedor' => 'stream_direto',
            'provedor_rotulo' => 'Stream direto',
        ];
    }

    private function servico(CatalogoProvedores $catalogo): TorrentService
    {
        return new TorrentService($catalogo, new OrcamentoBusca);
    }

    public function test_identificadores_com_pt_br_dispensam_scraper_e_cascata(): void
    {
        $catalogo = Mockery::mock(CatalogoProvedores::class);
        $catalogo->shouldReceive('buscarPorIdentificador')->once()->andReturn([$this->fonteIdentificador()]);
        $catalogo->shouldReceive('buscarFallbackDireto')->never();
        $catalogo->shouldReceive('buscar')->never();
        $catalogo->shouldReceive('reconciliarCenso')->andReturnNull();
        $catalogo->shouldReceive('fecharOrcamento')->andReturnNull();
        $catalogo->shouldReceive('temDublado')->andReturn(true);

        $fontes = $this->servico($catalogo)->fontes('Filme Raro', 2024, 'tt1234567');

        $this->assertCount(1, $fontes);
        $this->assertSame('torrentio', $fontes[0]['provedor']);
    }

    /**
     * Identificador de idioma não provado não encerra a busca.
     *
     * O corte de idioma é o mesmo da cascata: sem PT-BR provado, a fonte não
     * responde pela busca — e o scraper precisa ter a chance que tinha antes.
     */
    public function test_identificador_sem_pt_br_deixa_o_scraper_rodar(): void
    {
        $catalogo = Mockery::mock(CatalogoProvedores::class);
        $catalogo->shouldReceive('buscarPorIdentificador')->once()->andReturn([$this->fonteIdentificador('original')]);
        $catalogo->shouldReceive('buscarFallbackDireto')->once()->andReturn([$this->fonteDireta()]);
        $catalogo->shouldReceive('buscar')->never();
        $catalogo->shouldReceive('dispensarCascata')->once()->andReturnNull();
        $catalogo->shouldReceive('reconciliarCenso')->andReturnNull();
        $catalogo->shouldReceive('fecharOrcamento')->andReturnNull();
        $catalogo->shouldReceive('temDublado')->andReturn(true);

        $fontes = $this->servico($catalogo)->fontes('Filme Raro', 2024, 'tt1234567');

        $this->assertCount(1, $fontes);
        $this->assertSame('https://exemplo.test/filme.mp4', $fontes[0]['stream']);
    }

    public function test_identificadores_vazios_mantem_a_ordem_antiga(): void
    {
        $catalogo = Mockery::mock(CatalogoProvedores::class);
        $catalogo->shouldReceive('buscarPorIdentificador')->once()->andReturn([]);
        $catalogo->shouldReceive('buscarFallbackDireto')->once()->andReturn([]);
        $catalogo->shouldReceive('buscar')->once()->andReturn([]);
        $catalogo->shouldReceive('dispensarCascata')->never();
        $catalogo->shouldReceive('reconciliarCenso')->andReturnNull();
        $catalogo->shouldReceive('fecharOrcamento')->andReturnNull();
        $catalogo->shouldReceive('temDublado')->andReturn(true);

        $fontes = $this->servico($catalogo)->fontes('Filme Raro', 2024, 'tt1234567');

        $this->assertSame([], $fontes);
    }

    public function test_chave_desligada_nao_consulta_identificadores(): void
    {
        config()->set('services.torrents.identificadores_primeiro', false);

        $catalogo = Mockery::mock(CatalogoProvedores::class);
        $catalogo->shouldReceive('buscarPorIdentificador')->never();
        $catalogo->shouldReceive('buscarFallbackDireto')->once()->andReturn([]);
        $catalogo->shouldReceive('buscar')->once()->andReturn([]);
        $catalogo->shouldReceive('reconciliarCenso')->andReturnNull();
        $catalogo->shouldReceive('fecharOrcamento')->andReturnNull();
        $catalogo->shouldReceive('temDublado')->andReturn(true);

        $this->assertSame([], $this->servico($catalogo)->fontes('Filme Raro', 2024, 'tt1234567'));
    }
}
