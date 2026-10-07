<?php

namespace Tests\Unit;

use App\Services\TorrentService;
use App\Services\Torrents\CatalogoProvedores;
use App\Services\Torrents\OrcamentoBusca;
use Mockery;
use Tests\TestCase;

/**
 * Trava a leitura de "a busca não teve como perguntar a todos".
 *
 * É a marca que separa uma lista vazia de um veredito real. Sem ela, o título
 * era gravado como "sem fonte" e travava 24 h mesmo quando o vazio veio de um
 * provedor fora do ar, de um orçamento curto ou da ausência de provedor. Aqui
 * o catálogo é um dublê: só a cobertura e a presença de provedor interessam, e
 * nada toca em rede.
 */
class BuscaInconclusivaTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * Monta o serviço com um catálogo que responde à cobertura e à disponibilidade.
     *
     * @param  array<int, array<string, mixed>>  $cobertura  O que [`cobertura()`] devolve.
     */
    private function servico(bool $algumDisponivel, array $cobertura): TorrentService
    {
        $catalogo = Mockery::mock(CatalogoProvedores::class);
        $catalogo->shouldReceive('algumDisponivel')->andReturn($algumDisponivel);
        $catalogo->shouldReceive('cobertura')->andReturn($cobertura);

        return new TorrentService($catalogo, new OrcamentoBusca());
    }

    /** Sem provedor utilizável não havia a quem perguntar: não é veredito. */
    public function test_nenhum_provedor_utilizavel_e_inconclusivo(): void
    {
        $this->assertTrue($this->servico(false, [])->buscaInconclusiva());
    }

    /**
     * Provedor disponível que a cascata não chegou a consultar: o orçamento a
     * cortou no meio. "Não achamos" esconderia um "não perguntamos".
     */
    public function test_provedor_disponivel_nao_consultado_e_inconclusivo(): void
    {
        $servico = $this->servico(true, [
            ['provedor' => 'apibay', 'situacao' => 'sem_resultado'],
            ['provedor' => 'knaben', 'situacao' => 'nao_consultado'],
        ]);

        $this->assertTrue($servico->buscaInconclusiva());
    }

    /**
     * Provedor que quebrou ao ser perguntado: o acervo dele não chegou a ser
     * visto. É o caso do provedor momentaneamente fora do ar.
     */
    public function test_provedor_que_quebrou_e_inconclusivo(): void
    {
        $servico = $this->servico(true, [
            ['provedor' => 'apibay', 'situacao' => 'erro'],
            ['provedor' => 'yts', 'situacao' => 'sem_resultado'],
        ]);

        $this->assertTrue($servico->buscaInconclusiva());
    }

    /**
     * Todos os provedores responderam e nenhum tinha o título: aí sim o vazio é
     * veredito e o guard não dispara.
     */
    public function test_todos_consultados_sem_falha_e_conclusivo(): void
    {
        $servico = $this->servico(true, [
            ['provedor' => 'apibay', 'situacao' => 'sem_resultado'],
            ['provedor' => 'knaben', 'situacao' => 'barrado_no_filtro'],
            ['provedor' => 'yts', 'situacao' => 'descartado_na_montagem'],
        ]);

        $this->assertFalse($servico->buscaInconclusiva());
    }
}
