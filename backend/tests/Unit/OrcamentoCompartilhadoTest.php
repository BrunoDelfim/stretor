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
 * Trava os relógios por canal da busca.
 *
 * O orçamento era um relógio único, e isso matava o stream direto: a cascata de
 * torrents consumia os 45 s e o fallback — que só entra depois — nascia sem
 * tempo. O FlareSolverr respondia 200 com a página do episódio quando chamado à
 * mão, mas o provedor desistia antes de chamá-lo, porque `restante()` já devolvia
 * zero.
 *
 * A correção separou os canais: a cascata de torrents mantém o orçamento global
 * (45 s) e o stream direto tem o próprio (90 s), dimensionado para o custo real
 * do FlareSolverr — 10 a 15 s por página, e duas páginas no mínimo (série e
 * episódio). Estes testes provam que os dois relógios não se contaminam e que a
 * abertura continua idempotente dentro de cada canal.
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
     * os relógios, não as fontes que devolveria.
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
     * A abertura idempotente dentro do mesmo canal: o segundo pedido não reinicia.
     *
     * É o coração da correção original. Se `abrirSeFechado()` reiniciasse o prazo,
     * o mesmo canal voltaria a somar os seus orçamentos.
     */
    public function test_segunda_abertura_no_mesmo_canal_nao_reinicia_o_relogio(): void
    {
        $orcamento = new OrcamentoBusca();

        $this->assertTrue($orcamento->abrirSeFechado(12), 'A primeira abertura é de quem ancora o prazo.');
        $this->assertFalse($orcamento->abrirSeFechado(45), 'A segunda abertura não pode reancorar o prazo.');

        // O prazo continua sendo o de 12 s, e não o de 45 s da segunda chamada.
        $this->assertLessThanOrEqual(12, $orcamento->restante(), 'O relógio não pode ter sido esticado para 45 s.');
    }

    /**
     * Os canais têm relógios independentes.
     *
     * Abrir o canal `torrents` não abre o `stream_direto`, e vice-versa. É o que
     * permite ao stream direto ter um orçamento maior sem esticar o da cascata.
     */
    public function test_canais_tem_relogios_independentes(): void
    {
        $orcamento = new OrcamentoBusca();

        $orcamento->abrir(45, OrcamentoBusca::CANAL_PADRAO);

        $this->assertTrue($orcamento->esgotado(OrcamentoBusca::CANAL_PADRAO) === false);
        $this->assertNull(
            $orcamento->restante(OrcamentoBusca::CANAL_STREAM_DIRETO),
            'Abrir um canal não pode abrir o outro.'
        );

        $orcamento->abrir(90, OrcamentoBusca::CANAL_STREAM_DIRETO);

        $this->assertGreaterThan(
            45,
            $orcamento->restante(OrcamentoBusca::CANAL_STREAM_DIRETO),
            'O canal do stream direto tem o próprio prazo, maior que o global.'
        );
        $this->assertLessThanOrEqual(
            45,
            $orcamento->restante(OrcamentoBusca::CANAL_PADRAO),
            'O canal global não pode ter sido esticado pelo do stream direto.'
        );
    }

    /**
     * O caso central: o stream direto abre o próprio orçamento, não o global.
     *
     * Quando o roteador manda a série antiga para o stream direto, ele precisa de
     * tempo para pagar o FlareSolverr de cada página. Se ele usasse o orçamento
     * global de 45 s, a renderização da série mais a do episódio já o estourariam.
     */
    public function test_stream_direto_abre_o_proprio_orcamento(): void
    {
        $orcamento = new OrcamentoBusca();
        $catalogo = $this->catalogo($orcamento);

        $catalogo->buscarFallbackDireto(['Donas de Casa Desesperadas'], 2004, null, 1, 1);

        /*
         * A leitura é feita no canal do stream direto, e não no ativo: ao
         * terminar, o provedor devolve o canal ativo ao padrão, mas o relógio do
         * stream direto continua de pé até o `fecharOrcamento()` da busca inteira.
         *
         * O que importa não é o valor ser maior que o global, e sim o relógio ser
         * **próprio** e cobrir a descida. O custo real é de duas renderizações do
         * FlareSolverr (a listagem da série e a página do episódio), de 10 a 15 s
         * cada: o orçamento precisa sobrar pelo menos 30 s para as duas caberem.
         */
        $restante = $orcamento->restante(OrcamentoBusca::CANAL_STREAM_DIRETO);

        $this->assertNotNull(
            $restante,
            'O stream direto precisa deixar o próprio relógio de pé.'
        );
        $this->assertGreaterThanOrEqual(
            30,
            $restante,
            'O orçamento do stream direto precisa cobrir as duas páginas da descida.'
        );
    }

    /**
     * O stream direto não contamina o relógio da cascata.
     *
     * Depois de o stream direto rodar, o canal ativo volta para `torrents`. Sem
     * isso, a cascata que entrasse como fallback cruzado leria o prazo do stream
     * direto em vez do próprio.
     */
    public function test_stream_direto_devolve_o_canal_ativo_ao_padrao(): void
    {
        $orcamento = new OrcamentoBusca();
        $catalogo = $this->catalogo($orcamento);

        $catalogo->buscarFallbackDireto(['Donas de Casa Desesperadas'], 2004, null, 1, 1);

        $this->assertSame(
            OrcamentoBusca::CANAL_PADRAO,
            $orcamento->canalAtivo(),
            'Ao terminar, o stream direto precisa devolver o canal ativo ao padrão.'
        );
    }

    /**
     * A cascata de torrents usa o orçamento global, não o do stream direto.
     */
    public function test_cascata_usa_o_orcamento_global(): void
    {
        $orcamento = new OrcamentoBusca();
        $catalogo = $this->catalogo($orcamento);

        $catalogo->buscar(['Donas de Casa Desesperadas S01E01'], 2004, null, 1, 1);

        $this->assertLessThanOrEqual(
            45,
            $orcamento->restante(),
            'A cascata de torrents precisa do orçamento global de 45 s.'
        );
    }

    /**
     * O fechamento explícito libera os dois relógios para a próxima busca.
     *
     * Sem o `fecharOrcamento()`, uma busca encerrada deixaria os prazos de pé e a
     * próxima herdaria um relógio já vencido.
     */
    public function test_fechar_orcamento_libera_os_dois_relogios(): void
    {
        $orcamento = new OrcamentoBusca();
        $catalogo = $this->catalogo($orcamento);

        $catalogo->buscarFallbackDireto(['Donas de Casa Desesperadas'], 2004, null, 1, 1);
        $this->assertNotNull(
            $orcamento->restante(OrcamentoBusca::CANAL_STREAM_DIRETO),
            'Antes de fechar, o relógio do stream direto está de pé.'
        );

        $catalogo->fecharOrcamento();

        $this->assertFalse($orcamento->emCurso(), 'Depois de fechar, não há mais busca em curso.');
        $this->assertNull($orcamento->restante(), 'Sem busca em curso, não há prazo a respeitar.');
        $this->assertNull(
            $orcamento->restante(OrcamentoBusca::CANAL_STREAM_DIRETO),
            'O canal do stream direto também precisa ter sido fechado.'
        );
    }
}
