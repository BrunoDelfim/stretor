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
 * Trava o relógio único da busca.
 *
 * O orçamento é um singleton compartilhado, mas cada canal chamava `abrir()` com
 * o seu próprio valor — e cada chamada reiniciava o prazo. O stream direto abria
 * 12 s, fechava, e a cascata de torrents abria 45 s do zero: os dois orçamentos
 * somavam e a busca inteira podia passar de 57 s, estourando o limite do
 * frontend. Estes testes provam que o primeiro canal ancora o prazo e os demais o
 * respeitam, e que o stream direto — quando é o único canal — trabalha com o
 * orçamento inteiro.
 */
class OrcamentoCompartilhadoTest extends TestCase
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
     * O stream direto responde vazio: o que interessa aqui é o efeito dele sobre
     * o relógio compartilhado, não as fontes que devolveria.
     */
    private function catalogo(OrcamentoBusca $orcamento): CatalogoProvedores
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
            $orcamento,
            $streamDireto,
        );
    }

    /**
     * A abertura idempotente: o segundo canal não reinicia o relógio.
     *
     * É o coração da correção. Se `abrirSeFechado()` reiniciasse o prazo, o
     * stream direto e a cascata voltariam a somar os seus orçamentos.
     */
    public function test_segunda_abertura_nao_reinicia_o_relogio(): void
    {
        $orcamento = new OrcamentoBusca();

        $this->assertTrue($orcamento->abrirSeFechado(12), 'A primeira abertura é de quem ancora o prazo.');
        $this->assertFalse($orcamento->abrirSeFechado(45), 'A segunda abertura não pode reancorar o prazo.');

        // O prazo continua sendo o de 12 s, e não o de 45 s da segunda chamada.
        $this->assertLessThanOrEqual(12, $orcamento->restante(), 'O relógio não pode ter sido esticado para 45 s.');
    }

    /**
     * O caso central: o stream direto abre o orçamento global, não um teto menor.
     *
     * Quando o roteador manda a série antiga para o stream direto, ele é o único
     * canal e precisa do tempo inteiro para varrer os termos e páginas até achar a
     * fonte. Se ele abrisse um teto próprio de 12 s, o relógio global ficaria
     * travado nesse valor — e a cascata de torrents, que pode entrar como fallback
     * cruzado no sentido oposto, morreria sem tempo.
     */
    public function test_stream_direto_abre_o_orcamento_global(): void
    {
        $orcamento = new OrcamentoBusca();
        $catalogo = $this->catalogo($orcamento);

        $catalogo->buscarFallbackDireto(['Donas de Casa Desesperadas'], 2004, null, 1, 1);

        $this->assertTrue($orcamento->emCurso(), 'O stream direto precisa deixar o relógio de pé.');
        $this->assertGreaterThan(
            12,
            $orcamento->restante(),
            'O stream direto precisa do orçamento global, não de um teto de 12 s.'
        );
    }

    /**
     * O stream direto não reabre o relógio da cascata.
     *
     * Quando a cascata de torrents roda primeiro e cai no fallback cruzado, o
     * stream direto encontra o relógio já aberto e **não** o reinicia: o prazo
     * continua ancorado no global da cascata. É o que impede a soma que estourava
     * o frontend.
     */
    public function test_stream_direto_nao_reabre_o_relogio_da_cascata(): void
    {
        $orcamento = new OrcamentoBusca();
        $catalogo = $this->catalogo($orcamento);

        // A cascata roda primeiro e ancora o relógio global.
        $catalogo->buscar(['Donas de Casa Desesperadas S01E01'], 2004, null, 1, 1);
        $restanteAposCascata = $orcamento->restante();

        // O fallback cruzado aciona o stream direto, que não pode reancorar o prazo.
        $catalogo->buscarFallbackDireto(['Donas de Casa Desesperadas'], 2004, null, 1, 1);

        $this->assertLessThanOrEqual(
            $restanteAposCascata,
            $orcamento->restante(),
            'O stream direto não pode reabrir o relógio: o prazo continua ancorado na cascata.'
        );
    }

    /**
     * O fechamento explícito libera o relógio para a próxima busca.
     *
     * Sem o `fecharOrcamento()`, uma busca encerrada deixaria o prazo de pé e a
     * próxima herdaria um relógio já vencido.
     */
    public function test_fechar_orcamento_libera_o_relogio(): void
    {
        $orcamento = new OrcamentoBusca();
        $catalogo = $this->catalogo($orcamento);

        $catalogo->buscarFallbackDireto(['Donas de Casa Desesperadas'], 2004, null, 1, 1);
        $this->assertTrue($orcamento->emCurso());

        $catalogo->fecharOrcamento();

        $this->assertFalse($orcamento->emCurso(), 'Depois de fechar, não há mais busca em curso.');
        $this->assertNull($orcamento->restante(), 'Sem busca em curso, não há prazo global a respeitar.');
    }
}
