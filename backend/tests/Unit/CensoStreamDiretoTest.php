<?php

namespace Tests\Unit;

use App\Services\Torrents\CatalogoProvedores;
use App\Services\Torrents\InspecaoPack;
use App\Services\Torrents\OrcamentoBusca;
use App\Services\Torrents\ProvedorAddonStremio;
use App\Services\Torrents\ProvedorApibay;
use App\Services\Torrents\ProvedorBt4g;
use App\Services\Torrents\ProvedorKnaben;
use App\Services\Torrents\ProvedorStreamDireto;
use App\Services\Torrents\ProvedorTorrentio;
use App\Services\Torrents\ProvedorTorznab;
use App\Services\Torrents\ProvedorTrackersBr;
use App\Services\Torrents\ProvedorYts;
use Mockery;
use Tests\TestCase;

/**
 * Trava a sobrevivência do censo do stream direto no fallback cruzado.
 *
 * O stream direto não passa pela cascata: é acionado à parte, antes ou depois
 * dela, e o seu censo é preenchido à mão em `buscarFallbackDireto()`. Quando o
 * roteador manda a série antiga para o stream direto primeiro e ele volta vazio,
 * o fallback cruzado chama `buscar()` — que zera o censo. Sem a preservação, o
 * relatório diria `nao_consultado` de um provedor que rodou, que é justamente a
 * mentira que o censo existe para evitar.
 *
 * O catálogo é montado com dublês e nada aqui toca em rede ou banco.
 */
class CensoStreamDiretoTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * Dublê de um provedor do registro, só com o que o censo lê.
     */
    private function duble(string $classe, string $id): mixed
    {
        $duble = Mockery::mock($classe);
        $duble->shouldReceive('identificador')->andReturn($id);
        $duble->shouldReceive('rotulo')->andReturn($id);
        $duble->shouldReceive('disponivel')->andReturn(true);

        return $duble;
    }

    /**
     * Monta o catálogo com todos os provedores substituídos por dublês.
     *
     * O stream direto é um dublê que responde vazio: o que interessa aqui é o
     * registro da consulta no censo, não as fontes que ele devolveria.
     */
    private function catalogo(): CatalogoProvedores
    {
        $streamDireto = Mockery::mock(ProvedorStreamDireto::class);
        $streamDireto->shouldReceive('identificador')->andReturn('stream_direto');
        $streamDireto->shouldReceive('rotulo')->andReturn('Stream direto');
        $streamDireto->shouldReceive('disponivel')->andReturn(true);
        $streamDireto->shouldReceive('buscarComTitulos')->andReturn([]);

        return new CatalogoProvedores(
            $this->duble(ProvedorTrackersBr::class, 'trackers-br'),
            $this->duble(ProvedorApibay::class, 'apibay'),
            $this->duble(ProvedorKnaben::class, 'knaben'),
            $this->duble(ProvedorTorrentio::class, 'torrentio'),
            $this->duble(ProvedorAddonStremio::class, 'addon-stremio'),
            $this->duble(ProvedorBt4g::class, 'bt4g'),
            $this->duble(ProvedorTorznab::class, 'torznab'),
            $this->duble(ProvedorYts::class, 'yts'),
            Mockery::mock(InspecaoPack::class),
            new OrcamentoBusca(),
            $streamDireto,
        );
    }

    /**
     * Extrai a situação de um provedor do relatório de cobertura.
     */
    private function situacao(CatalogoProvedores $catalogo, string $id): ?string
    {
        foreach ($catalogo->cobertura() as $linha) {
            if ($linha['provedor'] === $id) {
                return $linha['situacao'];
            }
        }

        return null;
    }

    /**
     * O caso central: o stream direto roda primeiro, volta vazio, e a cascata de
     * torrents assume. O censo do stream direto precisa continuar registrando a
     * consulta — `sem_resultado`, e não `nao_consultado`.
     */
    public function test_censo_do_stream_direto_sobrevive_a_cascata_de_torrents(): void
    {
        $catalogo = $this->catalogo();

        // O roteador manda a série antiga para o stream direto primeiro.
        $catalogo->buscarFallbackDireto(['Donas de Casa Desesperadas'], 2004, null, 1, 1);

        $this->assertSame(
            'sem_resultado',
            $this->situacao($catalogo, 'stream_direto'),
            'O stream direto rodou e voltou vazio: o censo deve dizer sem_resultado.'
        );

        // O fallback cruzado aciona a cascata, que zera o censo no início.
        $catalogo->buscar(['Donas de Casa Desesperadas S01E01'], 2004, null, 1, 1);

        $this->assertSame(
            'sem_resultado',
            $this->situacao($catalogo, 'stream_direto'),
            'A cascata de torrents não pode apagar o registro do stream direto já consultado.'
        );
    }

    /**
     * O controle: sem o stream direto ter rodado, a cascata o deixa em
     * `nao_consultado`. A preservação não pode inventar uma consulta que não houve.
     */
    public function test_stream_direto_nao_consultado_continua_nao_consultado(): void
    {
        $catalogo = $this->catalogo();

        $catalogo->buscar(['Donas de Casa Desesperadas S01E01'], 2004, null, 1, 1);

        $this->assertSame(
            'nao_consultado',
            $this->situacao($catalogo, 'stream_direto'),
            'Sem consulta ao stream direto, o censo deve continuar dizendo nao_consultado.'
        );
    }
}
