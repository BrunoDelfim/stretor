<?php

namespace Tests\Feature;

use App\Services\Torrents\CatalogoProvedores;
use App\Services\Torrents\OrcamentoBusca;
use App\Services\TorrentService;
use Mockery;
use Tests\TestCase;

/**
 * Trava o relatório de cobertura da busca de torrents.
 *
 * O censo responde à pergunta que o usuário faz quando a lista vem vazia: "os
 * provedores foram consultados?". Duas coisas já o fizeram mentir:
 *
 * 1. **A segunda fase apagava a primeira.** A busca pelo título original zera o
 *    censo no início, então um episódio em que o Torrentio respondeu 26 fontes
 *    aparecia com todos os provedores em `nao_consultado`.
 * 2. **A segunda fase nascia sem orçamento.** Ela não tinha tempo de perguntar
 *    nada e enchia o relatório de silêncio, o que parece a mesma coisa — e não é.
 *
 * O catálogo é um dublê; nada aqui toca em rede.
 */
class CoberturaDaBuscaTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * Catálogo dublê com o mínimo que `fontes()` consome.
     *
     * @param  array<int, array<string, mixed>>  $fontes
     */
    private function catalogo(array $fontes = []): CatalogoProvedores
    {
        $catalogo = Mockery::mock(CatalogoProvedores::class);

        $catalogo->shouldReceive('buscarDiretasDeAddons')->andReturn([]);
        $catalogo->shouldReceive('buscarFallbackDireto')->andReturn([]);
        $catalogo->shouldReceive('reconciliarCenso')->andReturnNull();
        $catalogo->shouldReceive('fecharOrcamento')->andReturnNull();
        $catalogo->shouldReceive('algumDisponivel')->andReturnTrue();
        $catalogo->shouldReceive('cobertura')->andReturn([]);
        $catalogo->shouldReceive('ptBrSuficiente')->andReturnFalse();

        return $catalogo;
    }

    /**
     * A primeira fase abre a contagem e a segunda **acumula** nela.
     *
     * O teste olha o sexto argumento (`reiniciarCenso`) de cada chamada: a
     * primeira fica com o padrão, a segunda precisa vir com `false`. Sem isso, o
     * relatório volta a mostrar só o que a última fase fez.
     */
    public function test_segunda_fase_acumula_o_censo_da_primeira(): void
    {
        $catalogo = $this->catalogo();

        $catalogo->shouldReceive('buscar')
            ->once()
            ->withArgs(fn (...$args): bool => ($args[5] ?? true) === true)
            ->andReturn([]);

        $catalogo->shouldReceive('buscar')
            ->once()
            ->withArgs(fn (...$args): bool => ($args[5] ?? true) === false)
            ->andReturn([]);

        $servico = new TorrentService($catalogo, new OrcamentoBusca);

        $servico->fontes(
            'Donas de Casa Desesperadas',
            2004,
            'tt0410975',
            'Desperate Housewives',
            1,
            1,
            '693',
        );

        // As expectativas `once()` acima já provam o par de chamadas; o
        // `Mockery::close()` no tearDown confirma que ambas aconteceram.
        $this->assertTrue(true);
    }

    /**
     * Sem orçamento, a segunda fase nem começa.
     *
     * Uma rodada que nasce sem tempo só produz `nao_consultado` em todo mundo e
     * faz o relatório parecer que ninguém foi perguntado. O prazo é vencido por
     * reflexão, e não com `sleep`: o relógio do canal é o que a busca consulta, e
     * esperar de verdade só deixaria o teste lento sem provar nada a mais.
     */
    public function test_segunda_fase_nao_comeca_com_orcamento_esgotado(): void
    {
        $catalogo = $this->catalogo();

        $catalogo->shouldReceive('buscar')
            ->once()
            ->withArgs(fn (...$args): bool => ($args[5] ?? true) === true)
            ->andReturn([]);

        $orcamento = new OrcamentoBusca;

        $prazos = new \ReflectionProperty($orcamento, 'prazos');
        $prazos->setAccessible(true);
        $prazos->setValue($orcamento, [OrcamentoBusca::CANAL_PADRAO => microtime(true) - 10]);

        $servico = new TorrentService($catalogo, $orcamento);

        $servico->fontes(
            'Donas de Casa Desesperadas',
            2004,
            'tt0410975',
            'Desperate Housewives',
            1,
            1,
            '693',
        );

        $this->assertTrue(true);
    }
}
