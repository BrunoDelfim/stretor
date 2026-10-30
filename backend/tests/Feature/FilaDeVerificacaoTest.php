<?php

namespace Tests\Feature;

use App\Enums\OrigemVerificacao;
use App\Jobs\VerificarTemporada;
use App\Models\DisponibilidadeEpisodio;
use App\Services\TmdbService;
use App\Services\Verificacao\VerificadorEpisodios;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Concerns\UsaBancoDeTeste;
use Tests\TestCase;

/**
 * Trava a verificação em segundo plano.
 *
 * O modal não pode pagar a conta de uma temporada inteira: o `POST` enfileira e
 * volta na hora, e o worker grava cada selo conforme mede. Estes testes cobrem os
 * dois lados — o job que percorre a fila até o fim e a rota que enfileira sem
 * esperar —, além do plano B síncrono para quando o worker não está no ar.
 */
class FilaDeVerificacaoTest extends TestCase
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

    private function tmdb(int ...$numeros): TmdbService
    {
        $mock = Mockery::mock(TmdbService::class);

        $mock->shouldReceive('temporada')
            ->andReturn(['temporada' => 1, 'episodios' => $this->episodios(...$numeros)]);

        return $mock;
    }

    private function prova(int $episodio, int $diasAtras = 0): void
    {
        DisponibilidadeEpisodio::create([
            'tmdb_id' => 295778,
            'temporada' => 1,
            'episodio' => $episodio,
            'tem_fonte' => true,
            'inconclusivo' => false,
            'deve_retentar' => false,
            'origem' => OrigemVerificacao::PLAY->value,
            'verificado_em' => now()->subDays($diasAtras),
        ]);
    }

    public function test_job_percorre_a_fila_ate_o_fim(): void
    {
        $verificador = Mockery::mock(VerificadorEpisodios::class);

        $verificador->shouldReceive('verificar')
            ->once()
            ->withArgs(fn ($serie, $temporada, $lista, $limite) => $serie === 295778
                && $temporada === 1
                && $limite === 3)
            ->andReturn(['testados' => [1, 2], 'restantes' => 1]);

        $verificador->shouldReceive('verificar')
            ->once()
            ->andReturn(['testados' => [3], 'restantes' => 0]);

        (new VerificarTemporada(295778, 1))->handle($this->tmdb(1, 2, 3), $verificador);

        $this->assertTrue(true, 'O job terminou sem laço infinito.');
    }

    /**
     * Provedor fora do ar: nada foi testado. O job para em vez de insistir na
     * mesma requisição — o que faltou volta na próxima abertura da temporada.
     */
    public function test_job_para_quando_nada_e_testado(): void
    {
        $verificador = Mockery::mock(VerificadorEpisodios::class);

        $verificador->shouldReceive('verificar')
            ->once()
            ->andReturn(['testados' => [], 'restantes' => 2]);

        (new VerificarTemporada(295778, 1))->handle($this->tmdb(1, 2), $verificador);

        $this->assertTrue(true);
    }

    /**
     * Temporada sem episódio (TMDB devolveu vazio) não deve nem mexer no banco.
     */
    public function test_temporada_vazia_nao_verifica_nada(): void
    {
        $verificador = Mockery::mock(VerificadorEpisodios::class);
        $verificador->shouldNotReceive('verificar');

        (new VerificarTemporada(295778, 1))->handle($this->tmdb(), $verificador);

        $this->assertTrue(true);
    }

    public function test_post_enfileira_e_responde_na_hora(): void
    {
        Queue::fake();

        $this->mock(TmdbService::class, fn ($mock) => $mock->shouldReceive('temporada')
            ->andReturn(['temporada' => 1, 'episodios' => $this->episodios(1, 2)]));

        $this->postJson('/api/v1/movies/295778/temporada/1/verificar')
            ->assertOk()
            ->assertJsonPath('data.na_fila', true)
            ->assertJsonPath('meta.pendentes', 2);

        Queue::assertPushed(
            VerificarTemporada::class,
            fn (VerificarTemporada $job) => $job->serieId === 295778 && $job->temporada === 1,
        );
    }

    public function test_sem_pendente_nao_enfileira(): void
    {
        Queue::fake();

        $this->mock(TmdbService::class, fn ($mock) => $mock->shouldReceive('temporada')
            ->andReturn(['temporada' => 1, 'episodios' => $this->episodios(1, 2)]));

        $this->prova(1);
        $this->prova(2);

        $this->postJson('/api/v1/movies/295778/temporada/1/verificar')
            ->assertOk()
            ->assertJsonPath('data.na_fila', false)
            ->assertJsonPath('meta.pendentes', 0);

        Queue::assertNothingPushed();
    }

    /**
     * A abertura só trabalha com o que nunca foi verificado: prova vencida não
     * faz o modal voltar a testar o episódio — isso é da varredura diária.
     */
    public function test_abertura_nao_reverifica_prova_vencida(): void
    {
        Queue::fake();

        $this->mock(TmdbService::class, fn ($mock) => $mock->shouldReceive('temporada')
            ->andReturn(['temporada' => 1, 'episodios' => $this->episodios(1, 2)]));

        $this->prova(1, diasAtras: 30);

        $this->postJson('/api/v1/movies/295778/temporada/1/verificar')
            ->assertOk()
            ->assertJsonPath('data.na_fila', true)
            ->assertJsonPath('meta.pendentes', 1);
    }

    /**
     * Plano B: worker fora do ar. O caminho síncrono testa a fatia na hora e
     * devolve os selos, em vez de deixar a tela parada.
     */
    public function test_sincrono_verifica_a_fatia_sem_enfileirar(): void
    {
        Queue::fake();

        $this->mock(TmdbService::class, fn ($mock) => $mock->shouldReceive('temporada')
            ->andReturn(['temporada' => 1, 'episodios' => $this->episodios(1, 2, 3)]));

        $this->mock(VerificadorEpisodios::class, function ($mock) {
            $mock->shouldReceive('pendentes')->andReturn([1, 2, 3]);
            $mock->shouldReceive('verificar')->once()->andReturn(['testados' => [1, 2], 'restantes' => 1]);
        });

        $this->postJson('/api/v1/movies/295778/temporada/1/verificar?sincrono=1')
            ->assertOk()
            ->assertJsonPath('data.na_fila', false)
            ->assertJsonPath('data.testados', [1, 2])
            ->assertJsonPath('meta.pendentes', 1);

        Queue::assertNothingPushed();
    }
}
