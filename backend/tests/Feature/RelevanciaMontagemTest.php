<?php

namespace Tests\Feature;

use App\Enums\IdiomaFonte;
use App\Services\Torrents\CatalogoProvedores;
use App\Services\Torrents\OrcamentoBusca;
use App\Services\TorrentService;
use Mockery;
use Tests\TestCase;

/**
 * Trava o porteiro de relevância da montagem final.
 *
 * O bug que originou estes testes: com um título original não-latino (ex.: o
 * "兰香如故" de "Contra a Correnteza"), o termo de busca que chega aos provedores
 * por nome perde o título e vira só a numeração do episódio. O acervo mundial
 * então devolve uma página de "S01E01 dublado" de séries aleatórias — "Grimm",
 * "My Wife and Kids" —, que entravam na lista como se fossem o título pedido. O
 * porteiro recusa o release cujo nome não fala do título buscado.
 *
 * Como nos demais testes de montagem, o catálogo é um dublê e nada toca rede.
 */
class RelevanciaMontagemTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * Fonte de torrent já no contrato do frontend, com o nome controlado.
     *
     * @return array<string, mixed>
     */
    private function fonte(
        string $id,
        string $nome,
        string $idioma = IdiomaFonte::DUBLADO->value,
        int $seeds = 5,
    ): array {
        return [
            'id' => $id,
            'titulo' => $nome,
            'release' => $nome,
            'qualidade' => '1080P',
            'idioma' => $idioma,
            'idioma_rotulo' => $idioma,
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
        $catalogo->shouldReceive('buscarFallbackDireto')->andReturn([]);
        $catalogo->shouldReceive('reconciliarCenso')->andReturnNull();
        $catalogo->shouldReceive('fecharOrcamento')->andReturnNull();
        // A segunda fase fica desligada: a busca já está resolvida na primeira, e
        // o que estes testes olham é a montagem, não as fases de título.
        $catalogo->shouldReceive('ptBrSuficiente')->andReturnTrue();
        $catalogo->shouldReceive('temDublado')->andReturnTrue();

        return new TorrentService($catalogo, new OrcamentoBusca);
    }

    /**
     * O caso do bug: os releases que o APIBay devolveu para a busca genérica de
     * "S01E01 dublado" não têm nada a ver com o título e precisam sair.
     */
    public function test_release_sem_relacao_com_o_titulo_e_descartado(): void
    {
        $servico = $this->servicoComFontes([
            $this->fonte('grimm', 'Grimm S01E01 Dublado BR [ kickUploader ]'),
            $this->fonte('mywife', 'My Wife and Kids S01E01 Dublado'),
        ]);

        $fontes = $servico->fontes('Contra a Correnteza', 2026, 'tt0000000', '兰香如故', 1, 1);

        $this->assertSame([], $fontes, 'O release de outra série não pode entrar na lista.');
    }

    /** O release fiel ao título continua entrando: o porteiro não é um muro. */
    public function test_release_fiel_ao_titulo_entra(): void
    {
        $servico = $this->servicoComFontes([
            $this->fonte('ok', 'Contra a Correnteza S01E01 1080p Dublado'),
        ]);

        $fontes = $servico->fontes('Contra a Correnteza', 2026, 'tt0000000', '兰香如故', 1, 1);

        $this->assertCount(1, $fontes);
        $this->assertSame('ok', $fontes[0]['id']);
    }

    /**
     * Um release que casa só o título original — não o traduzido — é aceito. É o
     * caso do socorro da segunda fase: o acervo internacional publica pelo nome
     * original, e a montagem julga por título, não contra a união deles.
     */
    public function test_release_que_casa_so_o_titulo_original_e_aceito(): void
    {
        $servico = $this->servicoComFontes([
            $this->fonte('god', 'The.Godfather.1972.1080p.WEB-DL.Dublado'),
        ]);

        $fontes = $servico->fontes('O Poderoso Chefão', 1972, 'tt0068646', 'The Godfather');

        $this->assertCount(1, $fontes);
        $this->assertSame('god', $fontes[0]['id']);
    }

    /** Com a chave em zero, o porteiro desliga e o critério antigo volta. */
    public function test_chave_zero_desliga_o_porteiro(): void
    {
        config(['services.torrents.montagem_min_palavras_chave' => 0]);

        $servico = $this->servicoComFontes([
            $this->fonte('grimm', 'Grimm S01E01 Dublado BR [ kickUploader ]'),
        ]);

        $fontes = $servico->fontes('Contra a Correnteza', 2026, 'tt0000000', '兰香如故', 1, 1);

        $this->assertCount(1, $fontes, 'Com o porteiro desligado, o release volta a entrar.');
    }
}
