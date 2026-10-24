<?php

namespace Tests\Feature;

use App\Services\Torrents\CatalogoProvedores;
use App\Services\Torrents\OrcamentoBusca;
use App\Services\TorrentService;
use Mockery;
use Tests\TestCase;

/**
 * Trava a ordem da busca: **arquivo pronto primeiro, torrent por último**.
 *
 * O addon Stremio abre a fila, mas só com o que ele entrega como `url` (`.mp4`
 * ou `.m3u8`) — o mesmo contrato da fonte direta. O scraper web (Pobreflix e
 * agregadores) vem em seguida, e a cascata de torrents fica por último: malha
 * depende de seeders e o usuário espera menos por um link que já está pronto.
 *
 * Promover o Torrentio para a frente do scraper foi um erro: ele entrega
 * *torrent*, e o efeito era o Pobreflix nunca ser consultado quando havia um
 * magnet. Estes testes impedem a volta desse atalho.
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
     * Uma fonte direta, como o addon ou o scraper devolve.
     *
     * @return array<string, mixed>
     */
    private function fonteDireta(
        string $idioma = 'pt-BR',
        string $provedor = 'stream_direto',
        string $id = 'direta-scraper',
        string $url = 'https://exemplo.test/filme.mp4',
    ): array {
        return [
            'id' => $id,
            'tipo' => 'direto',
            'titulo' => 'Filme Raro Dublado',
            'qualidade' => '1080P',
            'idioma' => $idioma,
            'idioma_rotulo' => $idioma,
            'tamanho' => null,
            'seeds' => 1,
            'peers' => 0,
            'magnet' => '',
            'stream' => $url,
            'provedor' => $provedor,
            'provedor_rotulo' => $provedor === 'addon_stremio' ? 'Addon Stremio' : 'Stream direto',
        ];
    }

    private function servico(CatalogoProvedores $catalogo): TorrentService
    {
        return new TorrentService($catalogo, new OrcamentoBusca);
    }

    public function test_addon_com_link_direto_pt_br_dispensa_scraper_e_cascata(): void
    {
        $catalogo = Mockery::mock(CatalogoProvedores::class);
        $catalogo->shouldReceive('buscarDiretasDeAddons')->once()->andReturn([
            $this->fonteDireta('pt-BR', 'addon_stremio', 'direta-addon', 'https://cdn.test/addon.mp4'),
        ]);
        $catalogo->shouldReceive('buscarFallbackDireto')->never();
        $catalogo->shouldReceive('buscar')->never();
        // O censo do addon é a prova da resposta; limpar apagaria a linha.
        $catalogo->shouldReceive('dispensarCascata')->never();
        $catalogo->shouldReceive('reconciliarCenso')->andReturnNull();
        $catalogo->shouldReceive('fecharOrcamento')->andReturnNull();
        $catalogo->shouldReceive('temDublado')->andReturn(true);

        $fontes = $this->servico($catalogo)->fontes('Filme Raro', 2024, 'tt1234567');

        $this->assertCount(1, $fontes);
        $this->assertSame('addon_stremio', $fontes[0]['provedor']);
        $this->assertSame('https://cdn.test/addon.mp4', $fontes[0]['stream']);
    }

    /**
     * Link direto de idioma não provado não encerra a busca.
     *
     * O corte de idioma é o mesmo da cascata: sem PT-BR provado, a fonte não
     * responde pela busca — e o scraper precisa ter a chance que tinha antes.
     */
    public function test_addon_sem_pt_br_deixa_o_scraper_rodar(): void
    {
        $catalogo = Mockery::mock(CatalogoProvedores::class);
        $catalogo->shouldReceive('buscarDiretasDeAddons')->once()->andReturn([
            $this->fonteDireta('original', 'addon_stremio', 'direta-addon', 'https://cdn.test/addon.mp4'),
        ]);
        $catalogo->shouldReceive('buscarFallbackDireto')->once()->andReturn([$this->fonteDireta()]);
        $catalogo->shouldReceive('buscar')->never();
        $catalogo->shouldReceive('dispensarCascata')->once()->andReturnNull();
        $catalogo->shouldReceive('reconciliarCenso')->andReturnNull();
        $catalogo->shouldReceive('fecharOrcamento')->andReturnNull();
        $catalogo->shouldReceive('temDublado')->andReturn(true);

        $fontes = $this->servico($catalogo)->fontes('Filme Raro', 2024, 'tt1234567');

        $this->assertCount(1, $fontes);
        $this->assertSame('stream_direto', $fontes[0]['provedor']);
    }

    /**
     * Sem arquivo pronto no addon, o scraper vem antes da cascata de torrents.
     */
    public function test_sem_link_direto_o_scraper_vem_antes_da_cascata(): void
    {
        $catalogo = Mockery::mock(CatalogoProvedores::class);
        $catalogo->shouldReceive('buscarDiretasDeAddons')->once()->andReturn([]);
        $catalogo->shouldReceive('buscarFallbackDireto')->once()->andReturn([]);
        $catalogo->shouldReceive('buscar')->once()->andReturn([]);
        $catalogo->shouldReceive('dispensarCascata')->never();
        $catalogo->shouldReceive('reconciliarCenso')->andReturnNull();
        $catalogo->shouldReceive('fecharOrcamento')->andReturnNull();
        $catalogo->shouldReceive('temDublado')->andReturn(true);

        $this->assertSame([], $this->servico($catalogo)->fontes('Filme Raro', 2024, 'tt1234567'));
    }

    public function test_chave_desligada_comeca_no_scraper(): void
    {
        config()->set('services.torrents.addons_diretos_primeiro', false);

        $catalogo = Mockery::mock(CatalogoProvedores::class);
        $catalogo->shouldReceive('buscarDiretasDeAddons')->never();
        $catalogo->shouldReceive('buscarFallbackDireto')->once()->andReturn([]);
        $catalogo->shouldReceive('buscar')->once()->andReturn([]);
        $catalogo->shouldReceive('reconciliarCenso')->andReturnNull();
        $catalogo->shouldReceive('fecharOrcamento')->andReturnNull();
        $catalogo->shouldReceive('temDublado')->andReturn(true);

        $this->assertSame([], $this->servico($catalogo)->fontes('Filme Raro', 2024, 'tt1234567'));
    }
}
