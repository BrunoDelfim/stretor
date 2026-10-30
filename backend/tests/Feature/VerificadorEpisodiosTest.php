<?php

namespace Tests\Feature;

use App\Enums\OrigemVerificacao;
use App\Models\DisponibilidadeEpisodio;
use App\Services\TmdbService;
use App\Services\Verificacao\VerificadorEpisodios;
use App\Services\Verificacao\VerificadorFontes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\UsaBancoDeTeste;
use Tests\TestCase;

/**
 * Trava a fila de verificação por episódio.
 *
 * O modal pede os selos de uma temporada e continua pedindo enquanto houver
 * episódio sem prova. A fila é do banco: o que já foi testado não volta, o que
 * venceu volta, e cada requisição leva só uma fatia — a temporada inteira não
 * cabe no tempo de uma resposta.
 */
class VerificadorEpisodiosTest extends TestCase
{
    use RefreshDatabase;
    use UsaBancoDeTeste;

    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function episodios(int ...$numeros): array
    {
        return array_map(fn (int $numero): array => [
            'numero' => $numero,
            'temporada' => 1,
            'titulo' => 'Episódio '.$numero,
        ], $numeros);
    }

    private function prova(int $episodio, int $horasAtras = 0, bool $temFonte = true): void
    {
        DisponibilidadeEpisodio::create([
            'tmdb_id' => 96677,
            'temporada' => 1,
            'episodio' => $episodio,
            'tem_fonte' => $temFonte,
            'inconclusivo' => false,
            'deve_retentar' => false,
            'origem' => OrigemVerificacao::PLAY->value,
            'verificado_em' => now()->subHours($horasAtras),
        ]);
    }

    private function tmdb(): TmdbService
    {
        $mock = Mockery::mock(TmdbService::class);
        $mock->shouldReceive('detalhesSerie')->andReturn([
            'id' => 96677,
            'titulo' => 'Lupin',
            'ano' => 2021,
            'temporadas' => [['numero' => 1]],
        ]);

        return $mock;
    }

    /**
     * A fila é só do que não tem prova dentro do prazo: prova recente — com ou
     * sem fonte — não volta, prova vencida volta, e episódio nunca testado entra.
     */
    public function test_pendentes_ignoram_prova_valida_e_trazem_a_vencida(): void
    {
        $this->prova(1);                  // recente, com fonte
        $this->prova(2, temFonte: false); // recente, sem fonte
        $this->prova(3, horasAtras: 8 * 24); // vencida

        $pendentes = (new VerificadorEpisodios($this->tmdb(), Mockery::mock(VerificadorFontes::class)))
            ->pendentes(96677, 1, $this->episodios(1, 2, 3, 4));

        $this->assertSame([3, 4], $pendentes);
    }

    /**
     * Cada chamada testa uma fatia e informa o que sobrou. O verificador é
     * chamado uma vez por episódio, na ordem da fila.
     */
    public function test_verificacao_testa_a_fatia_e_devolve_o_restante(): void
    {
        $testados = [];

        $verificador = Mockery::mock(VerificadorFontes::class);
        $verificador->shouldReceive('verificarEpisodio')
            ->twice()
            ->andReturnUsing(function (array $serie, int $temporada, int $episodio) use (&$testados) {
                $testados[] = [$serie['id'], $temporada, $episodio];

                return new DisponibilidadeEpisodio(['tem_fonte' => true]);
            });

        $resultado = (new VerificadorEpisodios($this->tmdb(), $verificador))
            ->verificar(96677, 1, $this->episodios(1, 2, 3), 2);

        $this->assertSame([1, 2], $resultado['testados']);
        $this->assertSame(1, $resultado['restantes']);
        $this->assertSame([[96677, 1, 1], [96677, 1, 2]], $testados);
    }

    /** Sem pendente, a fila não toca no TMDB nem nos provedores. */
    public function test_sem_pendente_nao_chama_provedor(): void
    {
        $this->prova(1);

        $tmdb = Mockery::mock(TmdbService::class);
        $tmdb->shouldNotReceive('detalhesSerie');

        $verificador = Mockery::mock(VerificadorFontes::class);
        $verificador->shouldNotReceive('verificarEpisodio');

        $resultado = (new VerificadorEpisodios($tmdb, $verificador))
            ->verificar(96677, 1, $this->episodios(1));

        $this->assertSame([], $resultado['testados']);
        $this->assertSame(0, $resultado['restantes']);
    }
}
