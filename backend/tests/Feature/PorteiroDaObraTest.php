<?php

namespace Tests\Feature;

use App\Enums\IdiomaFonte;
use App\Services\Torrents\CatalogoProvedores;
use App\Services\Torrents\OrcamentoBusca;
use App\Services\TorrentService;
use Mockery;
use Tests\TestCase;

/**
 * Trava o porteiro que separa "fala do título" de "é a obra pedida".
 *
 * O caso real: a busca de "Cara de Barro" (Clayface, 2026) no APIBay devolveu
 * release que citam o nome e não são o filme — "The Batman S01E13 The Clayface
 * of Tragedy" (episódio de desenho) e "Batman – One Bad Day - Clayface 1 (2023)
 * (Comic) PDF" (quadrinho). O porteiro de relevância aprovava todos: a palavra
 * "clayface" estava lá. Com seeds e magnet, qualquer um deles ia para a lista e
 * o player abria outra obra.
 *
 * A régua nova continua conservadora: só recusa o que o próprio nome prova que
 * é outra coisa — material de leitura, numeração de episódio num pedido de
 * filme, ou ano declarado que não é o do título.
 */
class PorteiroDaObraTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * Fonte de torrent já no contrato de saída, com o nome controlado.
     *
     * @return array<string, mixed>
     */
    private function fonte(string $id, string $nome, int $seeds = 20): array
    {
        return [
            'id' => $id,
            'titulo' => $nome,
            'release' => $nome,
            'qualidade' => '1080P',
            'idioma' => IdiomaFonte::DUBLADO->value,
            'idioma_rotulo' => IdiomaFonte::DUBLADO->value,
            'tamanho' => '1,0 GB',
            'seeds' => $seeds,
            'peers' => 0,
            'magnet' => 'magnet:?xt=urn:btih:'.str_pad($id, 40, 'a'),
            'provedor' => 'apibay',
            'provedor_rotulo' => 'APIBay',
            'pack' => false,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $fontes
     */
    private function servicoComFontes(array $fontes): TorrentService
    {
        $catalogo = Mockery::mock(CatalogoProvedores::class);
        $catalogo->shouldReceive('buscar')->andReturn($fontes);
        $catalogo->shouldReceive('buscarDiretasDeAddons')->andReturn([]);
        $catalogo->shouldReceive('buscarFallbackDireto')->andReturn([]);
        $catalogo->shouldReceive('reconciliarCenso')->andReturnNull();
        $catalogo->shouldReceive('fecharOrcamento')->andReturnNull();
        $catalogo->shouldReceive('ptBrSuficiente')->andReturnTrue();
        $catalogo->shouldReceive('temDublado')->andReturnTrue();

        return new TorrentService($catalogo, new OrcamentoBusca);
    }

    /** Episódio de série não responde por um pedido de filme. */
    public function test_episodio_de_serie_nao_responde_por_filme(): void
    {
        $servico = $this->servicoComFontes([
            $this->fonte('batman', 'The Batman S01E13 The Clayface of Tragedy 1080p Dublado'),
        ]);

        $fontes = $servico->fontes('Cara-de-Barro', 2026, 'tt34890576', 'Clayface');

        $this->assertSame([], $fontes, 'Um episódio de desenho não pode ser oferecido como o filme.');
    }

    /** Quadrinho e revista não tocam em player nenhum. */
    public function test_material_de_leitura_e_descartado(): void
    {
        $servico = $this->servicoComFontes([
            $this->fonte('comic', 'Batman - One Bad Day - Clayface 1 (2023) (Comic) PDF Dublado'),
        ]);

        $fontes = $servico->fontes('Cara-de-Barro', 2026, 'tt34890576', 'Clayface');

        $this->assertSame([], $fontes, 'PDF de quadrinho não é fonte de vídeo.');
    }

    /** Release de outro ano é outra obra, mesmo com o nome igual. */
    public function test_ano_divergente_e_descartado(): void
    {
        $servico = $this->servicoComFontes([
            $this->fonte('antigo', 'Clayface.2010.1080p.BluRay.Dublado'),
        ]);

        $fontes = $servico->fontes('Cara-de-Barro', 2026, 'tt34890576', 'Clayface');

        $this->assertSame([], $fontes, 'O release de 2010 não é o filme de 2026.');
    }

    /** O release do ano certo entra: o porteiro não é um muro. */
    public function test_ano_correto_entra(): void
    {
        $servico = $this->servicoComFontes([
            $this->fonte('certo', 'Clayface.2026.TS.1080p.Dublado'),
        ]);

        $fontes = $servico->fontes('Cara-de-Barro', 2026, 'tt34890576', 'Clayface');

        $this->assertCount(1, $fontes);
        $this->assertSame('certo', $fontes[0]['id']);
    }

    /** Nome sem ano não permite julgar, e a fonte segue. */
    public function test_nome_sem_ano_passa(): void
    {
        $servico = $this->servicoComFontes([
            $this->fonte('semano', 'Clayface TS 1080p Dublado'),
        ]);

        $fontes = $servico->fontes('Cara-de-Barro', 2026, 'tt34890576', 'Clayface');

        $this->assertCount(1, $fontes, 'Sem ano no nome, não há como provar que é outra obra.');
    }

    /**
     * Título que também é ano ("1917") não pode ser confundido com o ano do
     * release: o primeiro número do nome é o título, o segundo é o lançamento.
     */
    public function test_titulo_numerico_nao_e_lido_como_ano(): void
    {
        $servico = $this->servicoComFontes([
            $this->fonte('1917', '1917.2019.1080p.BluRay.Dublado'),
        ]);

        $fontes = $servico->fontes('1917', 2019, 'tt8579674', '1917');

        $this->assertCount(1, $fontes, 'O ano do release bate com o do título, e a fonte entra.');
    }

    /** A resolução não é ano: `1920x1080` não pode condenar o release. */
    public function test_resolucao_nao_e_lida_como_ano(): void
    {
        $servico = $this->servicoComFontes([
            $this->fonte('res', 'Clayface 1920x1080 Dublado'),
        ]);

        $fontes = $servico->fontes('Cara-de-Barro', 2026, 'tt34890576', 'Clayface');

        $this->assertCount(1, $fontes);
        $this->assertSame('res', $fontes[0]['id']);
    }

    /** Na busca de episódio, a numeração é resposta certa e não pode ser barrada. */
    public function test_episodio_continua_valendo_para_pedido_de_episodio(): void
    {
        $servico = $this->servicoComFontes([
            $this->fonte('ep', 'American Horror Story S01E02 1080p Dublado'),
        ]);

        $fontes = $servico->fontes('American Horror Story', 2024, 'tt1320771', null, 1, 2);

        $this->assertCount(1, $fontes);
        $this->assertSame('ep', $fontes[0]['id']);
    }

    /**
     * Pacote compactado que declara o vídeo dentro continua entrando: a
     * extensão de vídeo desempata o julgamento do material de leitura.
     */
    public function test_pacote_com_video_dentro_passa(): void
    {
        $servico = $this->servicoComFontes([
            $this->fonte('zip', 'Cara.de.Barro.2026.1080p.mkv.zip Dublado'),
        ]);

        $fontes = $servico->fontes('Cara-de-Barro', 2026, 'tt34890576', 'Clayface');

        $this->assertCount(1, $fontes);
        $this->assertSame('zip', $fontes[0]['id']);
    }

    /** Documentário marcado como "DOC" não é material de leitura. */
    public function test_documentario_nao_e_confundido_com_leitura(): void
    {
        $servico = $this->servicoComFontes([
            $this->fonte('doc', 'Cara.de.Barro.2026.DOC.1080p.Dublado'),
        ]);

        $fontes = $servico->fontes('Cara-de-Barro', 2026, 'tt34890576', 'Clayface');

        $this->assertCount(1, $fontes);
        $this->assertSame('doc', $fontes[0]['id']);
    }
}
