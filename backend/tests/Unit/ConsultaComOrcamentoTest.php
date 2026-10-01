<?php

namespace Tests\Unit;

use App\Services\Torrents\ConsultaComOrcamento;
use App\Services\Torrents\OrcamentoBusca;
use Tests\TestCase;

/**
 * A margem de orçamento é o que impede o fallback de começar uma requisição que
 * não caberia no prazo. Sem ela, a última consulta era iniciada a 1 s do fim,
 * esperava o teto cheio e voltava depois de o prazo vencer — o orçamento inteiro
 * gasto sem nada entregue. Estes testes fixam a leitura da margem.
 */
class ConsultaComOrcamentoTest extends TestCase
{
    /**
     * Monta um objeto anónimo com a trait e o orçamento injetado.
     */
    private function comOrcamento(OrcamentoBusca $orcamento): object
    {
        return new class($orcamento)
        {
            use ConsultaComOrcamento;

            public function __construct(protected OrcamentoBusca $orcamento)
            {
            }

            public function consulta(int $teto, float $fracaoMinima = 0.5): bool
            {
                return $this->temTempoParaConsulta($teto, $fracaoMinima);
            }

            public function teto(int $teto): int
            {
                return $this->tempoDeConsulta($teto);
            }
        };
    }

    public function test_sem_busca_em_curso_o_teto_proprio_vale(): void
    {
        $objeto = $this->comOrcamento(new OrcamentoBusca());

        // Sem prazo global, não há margem a respeitar: o teto do provedor manda.
        $this->assertTrue($objeto->consulta(10));
        $this->assertSame(10, $objeto->teto(10));
    }

    public function test_orcamento_folgado_comporta_a_consulta(): void
    {
        $orcamento = new OrcamentoBusca();
        $orcamento->abrir(30);

        $objeto = $this->comOrcamento($orcamento);

        $this->assertTrue($objeto->consulta(10));
    }

    public function test_orcamento_justo_no_limite_comporta_a_consulta(): void
    {
        $orcamento = new OrcamentoBusca();
        // 12 s restantes cobrem com folga o piso de 5 s (metade do teto de 10 s).
        $orcamento->abrir(12);

        $objeto = $this->comOrcamento($orcamento);

        $this->assertTrue($objeto->consulta(10));
    }

    public function test_orcamento_apertado_ainda_comporta_a_consulta(): void
    {
        $orcamento = new OrcamentoBusca();
        // 11 s restantes ainda cobrem o piso de 5 s: a consulta vale a pena.
        $orcamento->abrir(11);

        $objeto = $this->comOrcamento($orcamento);

        $this->assertTrue($objeto->consulta(10));
    }

    public function test_orcamento_abaixo_do_piso_nao_comporta_a_consulta(): void
    {
        $orcamento = new OrcamentoBusca();
        // 4 s restantes ficam abaixo do piso de 5 s: não vale abrir a conexão.
        $orcamento->abrir(4);

        $objeto = $this->comOrcamento($orcamento);

        $this->assertFalse($objeto->consulta(10));
    }

    public function test_orcamento_esgotado_nao_comporta_a_consulta(): void
    {
        $orcamento = new OrcamentoBusca();
        $orcamento->abrir(1);

        // Consome o orçamento para o prazo vencer.
        sleep(2);

        $objeto = $this->comOrcamento($orcamento);

        $this->assertFalse($objeto->consulta(10));
        $this->assertSame(0, $objeto->teto(10));
    }

    public function test_fracao_configuravel_permite_consulta_mais_justa(): void
    {
        $orcamento = new OrcamentoBusca();
        $orcamento->abrir(4);

        $objeto = $this->comOrcamento($orcamento);

        // Com a fração padrão (metade), 4 s não cobrem o piso de 5 s.
        $this->assertFalse($objeto->consulta(10, 0.5));

        // Com uma fração menor (um quinto), o piso cai para 2 s e a consulta cabe.
        $this->assertTrue($objeto->consulta(10, 0.2));
    }

    /**
     * O caso que travou o fallback do stream direto.
     *
     * O orçamento do fallback é de 12 s e o teto de cada consulta é de 10 s.
     * Exigir o teto cheio mais uma margem fixa fazia a conta fechar apenas no
     * instante zero: assim que a primeira consulta consumisse 1 s, o restante
     * caía para 11 s e nenhum termo seguinte era tentado — o laço parava depois
     * do primeiro, mesmo com orçamento de sobra. O piso proporcional mantém o
     * laço vivo enquanto houver tempo para valer a conexão.
     */
    public function test_orcamento_do_fallback_nao_para_apos_a_primeira_consulta(): void
    {
        $orcamento = new OrcamentoBusca();
        $orcamento->abrir(12);

        $objeto = $this->comOrcamento($orcamento);

        // Instante zero: 12 s cobrem o piso de 5 s.
        $this->assertTrue($objeto->consulta(10));

        // Após a primeira consulta, restam 11 s — ainda cabe o próximo termo.
        sleep(1);

        $this->assertTrue($objeto->consulta(10));
    }

    /**
     * O piso é proporcional ao teto, mas nunca menor que 1 s.
     *
     * Uma consulta de 2 s exige 1 s restante; uma de 10 s exige 5 s. O piso de
     * 1 s impede que uma fração pequena vire zero e libere uma consulta sem
     * tempo nenhum.
     */
    public function test_piso_proporcional_nunca_zera(): void
    {
        $orcamento = new OrcamentoBusca();
        $orcamento->abrir(1);

        $objeto = $this->comOrcamento($orcamento);

        // Teto de 2 s com fração de 0,1: o piso seria 0,2 s, mas o mínimo é 1 s.
        $this->assertTrue($objeto->consulta(2, 0.1));

        // Teto de 10 s com fração de 0,1: o piso é 1 s, e 1 s resta.
        $this->assertTrue($objeto->consulta(10, 0.1));
    }
}
