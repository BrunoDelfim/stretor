<?php

namespace Tests\Feature;

use App\Enums\IdiomaFonte;
use App\Services\TorrentService;
use App\Services\Torrents\CatalogoProvedores;
use Mockery;
use Tests\TestCase;

/**
 * Trava a inversão de fluxo por idade da série e o fallback cruzado.
 *
 * A estratégia existe para não gastar o orçamento dos torrents numa série que os
 * indexadores já não têm. O que importa testar aqui é a **ordem** das chamadas:
 * série antiga precisa tocar o stream direto antes da cascata, e só cair nos
 * torrents se o scraper voltar vazio. Série recente faz o inverso. Sem estes
 * testes, uma regressão que trocasse a ordem passaria despercebida — o resultado
 * final poderia ser o mesmo, mas o timeout voltaria.
 *
 * O catálogo é substituído por um dublê e nada aqui toca em rede ou banco.
 */
class RoteamentoPorIdadeTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function fonteTorrent(string $id): array
    {
        return [
            'id' => $id,
            'tipo' => 'torrent',
            'titulo' => "Release {$id}",
            'qualidade' => '1080P',
            'idioma' => IdiomaFonte::DUBLADO->value,
            'idioma_rotulo' => 'Dublado',
            'tamanho' => '1,0 GB',
            'seeds' => 10,
            'peers' => 2,
            'magnet' => 'magnet:?xt=urn:btih:'.str_pad($id, 40, 'a'),
            'stream' => '',
            'provedor' => 'apibay',
            'provedor_rotulo' => 'APIBay',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fonteDireta(string $id): array
    {
        return [
            'id' => $id,
            'tipo' => 'direto',
            'titulo' => "Release {$id}",
            'qualidade' => '1080P',
            'idioma' => IdiomaFonte::DUBLADO->value,
            'idioma_rotulo' => 'Dublado',
            'tamanho' => null,
            'seeds' => 1,
            'peers' => 0,
            'magnet' => '',
            'stream' => "https://exemplo.test/{$id}.mp4",
            'provedor' => 'stream_direto',
            'provedor_rotulo' => 'Stream direto',
        ];
    }

    /**
     * Dublê do catálogo que registra a ordem das chamadas.
     *
     * `$ordem` é preenchido por referência com 'torrents' ou 'stream_direto' a
     * cada chamada, para o teste afirmar qual canal foi tocado primeiro.
     *
     * @param  array<int, array<string, mixed>>  $fontes
     * @param  array<int, array<string, mixed>>  $diretas
     * @param  array<int, string>  $ordem
     */
    private function servico(array $fontes, array $diretas, array &$ordem): TorrentService
    {
        $catalogo = Mockery::mock(CatalogoProvedores::class);
        $catalogo->shouldReceive('buscar')->andReturnUsing(function () use ($fontes, &$ordem): array {
            $ordem[] = 'torrents';

            return $fontes;
        });
        $catalogo->shouldReceive('buscarFallbackDireto')->andReturnUsing(function () use ($diretas, &$ordem): array {
            $ordem[] = 'stream_direto';

            return $diretas;
        });
        $catalogo->shouldReceive('reconciliarCenso')->andReturnNull();
        // A segunda fase (título original) consulta a suficiência PT-BR antes de
        // rodar; sem esta expectativa o dublê estoura ao ser perguntado.
        $catalogo->shouldReceive('ptBrSuficiente')->andReturnTrue();
        $catalogo->shouldReceive('temDublado')->andReturnUsing(
            fn (array $lista): bool => collect($lista)->contains(
                fn (array $f): bool => in_array($f['idioma'], [IdiomaFonte::DUBLADO->value, IdiomaFonte::DUAL_AUDIO->value], true)
            )
        );

        return new TorrentService($catalogo);
    }

    public function test_serie_antiga_toca_o_stream_direto_antes_dos_torrents(): void
    {
        config()->set('services.torrents.busca_por_idade_habilitada', true);
        config()->set('services.torrents.busca_idade_limite_anos', 2);

        $ordem = [];
        // O stream direto responde: a cascata de torrents nem deve ser tocada.
        $servico = $this->servico([$this->fonteTorrent('t1')], [$this->fonteDireta('d1')], $ordem);

        $fontes = $servico->fontes('Donas de Casa Desesperadas', 2004, null, 'Desperate Housewives', 1, 1);

        $this->assertSame(['stream_direto'], $ordem);
        $this->assertCount(1, $fontes);
        $this->assertSame('direto', $fontes[0]['tipo']);
    }

    public function test_serie_antiga_cai_nos_torrents_se_o_stream_direto_falhar(): void
    {
        config()->set('services.torrents.busca_por_idade_habilitada', true);
        config()->set('services.torrents.busca_idade_limite_anos', 2);

        $ordem = [];
        // O stream direto volta vazio: o fallback cruzado aciona os torrents.
        $servico = $this->servico([$this->fonteTorrent('t1')], [], $ordem);

        $fontes = $servico->fontes('Donas de Casa Desesperadas', 2004, null, 'Desperate Housewives', 1, 1);

        $this->assertSame(['stream_direto', 'torrents'], $ordem);
        $this->assertCount(1, $fontes);
        $this->assertSame('torrent', $fontes[0]['tipo']);
    }

    public function test_serie_recente_toca_os_torrents_antes_do_stream_direto(): void
    {
        config()->set('services.torrents.busca_por_idade_habilitada', true);
        config()->set('services.torrents.busca_idade_limite_anos', 2);

        $ordem = [];
        $ano = (int) date('Y');
        // Os torrents respondem: o stream direto nem deve ser tocado.
        $servico = $this->servico([$this->fonteTorrent('t1')], [$this->fonteDireta('d1')], $ordem);

        $fontes = $servico->fontes('Série Nova', $ano, null, null, 1, 1);

        $this->assertSame(['torrents'], $ordem);
        $this->assertCount(1, $fontes);
        $this->assertSame('torrent', $fontes[0]['tipo']);
    }

    public function test_serie_recente_cai_no_stream_direto_se_os_torrents_falharem(): void
    {
        config()->set('services.torrents.busca_por_idade_habilitada', true);
        config()->set('services.torrents.busca_idade_limite_anos', 2);

        $ordem = [];
        $ano = (int) date('Y');
        // Os torrents voltam vazios: o fallback cruzado aciona o stream direto.
        $servico = $this->servico([], [$this->fonteDireta('d1')], $ordem);

        $fontes = $servico->fontes('Série Nova', $ano, null, null, 1, 1);

        $this->assertSame(['torrents', 'stream_direto'], $ordem);
        $this->assertCount(1, $fontes);
        $this->assertSame('direto', $fontes[0]['tipo']);
    }

    public function test_estrategia_desligada_mantem_os_torrents_primeiro(): void
    {
        config()->set('services.torrents.busca_por_idade_habilitada', false);
        config()->set('services.torrents.busca_idade_limite_anos', 2);

        $ordem = [];
        // Mesmo com a série de 2004, desligada a estratégia o fluxo é o antigo.
        $servico = $this->servico([$this->fonteTorrent('t1')], [$this->fonteDireta('d1')], $ordem);

        $fontes = $servico->fontes('Donas de Casa Desesperadas', 2004, null, 'Desperate Housewives', 1, 1);

        $this->assertSame(['torrents'], $ordem);
        $this->assertSame('torrent', $fontes[0]['tipo']);
    }
}
