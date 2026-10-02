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
 * respeitam, e que o teto próprio do stream direto encolhe o prazo em vez de
 * reiniciá-lo.
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
     * stream direto (12 s) e a cascata (45 s) voltariam a somar.
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
     * O teto próprio encolhe o prazo, nunca o estica.
     *
     * Quando o global (45 s) já está de pé e o stream direto entra como fallback
     * cruzado, o teto de 12 s precisa valer como **limite**. Sem isto, o
     * `restante()` devolveria os 45 s do global e o socorro viraria uma segunda
     * busca inteira.
     */
    public function test_limitar_encolhe_o_prazo_global(): void
    {
        $orcamento = new OrcamentoBusca();
        $orcamento->abrir(45);

        $orcamento->limitar(12);

        $this->assertLessThanOrEqual(12, $orcamento->restante(), 'O teto de 12 s precisa encolher o prazo global.');
    }

    /**
     * Um teto maior que o restante é ignorado.
     *
     * O `limitar()` só diminui: se o prazo global tem 12 s e alguém pede 45 s, o
     * relógio continua com os 12 s. Esticar seria reabrir o orçamento por outra
     * porta.
     */
    public function test_limitar_nunca_estica_o_prazo(): void
    {
        $orcamento = new OrcamentoBusca();
        $orcamento->abrir(12);

        $orcamento->limitar(45);

        $this->assertLessThanOrEqual(12, $orcamento->restante(), 'Um teto maior não pode esticar o prazo.');
    }

    /**
     * O caso central: o stream direto não reabre o relógio da cascata.
     *
     * O roteador manda a série antiga para o stream direto primeiro. Ele abre o
     * orçamento (12 s) e o deixa de pé. Quando a cascata de torrents assume como
     * fallback cruzado, ela encontra o relógio já aberto e **não** o reinicia: o
     * prazo continua ancorado nos 12 s do stream direto, e não nos 45 s da
     * cascata. É o que impede a soma que estourava o frontend.
     */
    public function test_stream_direto_nao_reabre_o_relogio_da_cascata(): void
    {
        $orcamento = new OrcamentoBusca();
        $catalogo = $this->catalogo($orcamento);

        // O roteador manda a série antiga para o stream direto primeiro.
        $catalogo->buscarFallbackDireto(['Donas de Casa Desesperadas'], 2004, null, 1, 1);

        $this->assertTrue($orcamento->emCurso(), 'O stream direto precisa deixar o relógio de pé para a cascata.');
        $this->assertLessThanOrEqual(12, $orcamento->restante(), 'O relógio do stream direto é de 12 s.');

        // O fallback cruzado aciona a cascata, que não pode reancorar o prazo.
        $catalogo->buscar(['Donas de Casa Desesperadas S01E01'], 2004, null, 1, 1);

        $this->assertLessThanOrEqual(
            12,
            $orcamento->restante(),
            'A cascata não pode reabrir o relógio: o prazo continua ancorado no stream direto.'
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
