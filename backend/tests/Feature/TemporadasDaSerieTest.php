<?php

namespace Tests\Feature;

use App\Services\TmdbService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Trava a lista de temporadas que alimenta o seletor do modal.
 *
 * A confusão que originou este teste não era de truncamento: "Dexter:
 * Ressurreição" (TMDB 259909) tem mesmo duas temporadas, e "Lupin" (96677) tem
 * três — a Parte 4 é a **temporada 3**, porque o TMDB junta as Partes 1 e 2 na
 * primeira. Se o backend algum dia cortar a lista ou perder o nome da temporada,
 * o modal volta a esconder temporada que existe e a dizer "T3" para quem espera
 * "Parte 4".
 */
class TemporadasDaSerieTest extends TestCase
{
    /**
     * Monta a resposta de `/tv/{id}` com a lista de temporadas informada.
     *
     * @param  array<int, array{numero: int, nome: string, eps: int, ano: string|null}>  $temporadas
     * @return array<string, mixed>
     */
    private function ficha(string $titulo, array $temporadas, int $total): array
    {
        return [
            'id' => 1,
            'name' => $titulo,
            'original_name' => $titulo,
            'overview' => 'Sinopse.',
            'first_air_date' => '2021-01-08',
            'vote_average' => 7.8,
            'number_of_seasons' => $total,
            'number_of_episodes' => 25,
            'episode_run_time' => [45],
            'genres' => [],
            'external_ids' => ['imdb_id' => 'tt1234567'],
            'seasons' => array_map(fn (array $temporada): array => [
                'season_number' => $temporada['numero'],
                'name' => $temporada['nome'],
                'episode_count' => $temporada['eps'],
                'air_date' => $temporada['ano'],
                'poster_path' => null,
            ], $temporadas),
        ];
    }

    private function service(): TmdbService
    {
        config([
            'services.tmdb.key' => 'chave-de-teste',
            'services.tmdb.base_url' => 'https://api.themoviedb.org/3',
            'services.tmdb.language' => 'pt-BR',
            'services.tmdb.cache_ttl' => 0,
        ]);

        return new TmdbService;
    }

    /**
     * As oito temporadas do Dexter clássico saem inteiras e na ordem.
     */
    public function test_serie_longa_devolve_todas_as_temporadas(): void
    {
        $temporadas = array_map(
            fn (int $numero): array => [
                'numero' => $numero,
                'nome' => "Temporada {$numero}",
                'eps' => 12,
                'ano' => (string) (2005 + $numero).'-01-01',
            ],
            range(1, 8)
        );

        Http::fake([
            '*/tv/1405*' => Http::response($this->ficha('Dexter', $temporadas, 8)),
        ]);

        $serie = $this->service()->detalhesSerie(1405);

        $this->assertSame(8, $serie['numero_temporadas']);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8], array_column($serie['temporadas'], 'numero'));
        $this->assertSame(12, $serie['temporadas'][7]['qtd_episodios']);
        $this->assertSame(2013, $serie['temporadas'][7]['ano']);
    }

    /**
     * O nome próprio é o que diz ao usuário que a temporada 3 do TMDB é a
     * "Parte 4" que ele viu sair. O Especiais (temporada 0) continua fora.
     */
    public function test_nome_da_temporada_e_preservado_e_especiais_ficam_fora(): void
    {
        Http::fake([
            '*/tv/96677*' => Http::response($this->ficha('Lupin', [
                ['numero' => 0, 'nome' => 'Especiais', 'eps' => 2, 'ano' => '2021-01-08'],
                ['numero' => 1, 'nome' => 'Parte 1 & 2', 'eps' => 10, 'ano' => '2021-01-08'],
                ['numero' => 2, 'nome' => 'Parte 3', 'eps' => 7, 'ano' => '2023-10-05'],
                ['numero' => 3, 'nome' => 'Parte 4', 'eps' => 8, 'ano' => '2026-10-20'],
            ], 4)),
        ]);

        $serie = $this->service()->detalhesSerie(96677);

        $this->assertSame([1, 2, 3], array_column($serie['temporadas'], 'numero'));
        $this->assertSame('Parte 4', $serie['temporadas'][2]['nome']);
        $this->assertSame(2026, $serie['temporadas'][2]['ano']);
    }

    /**
     * Quando o TMDB devolve o contador mas não a lista, o backend não inventa
     * temporada — quem completa a numeração é o modal, usando
     * `numero_temporadas` como piso.
     */
    public function test_lista_vazia_preserva_o_contador_para_o_modal_completar(): void
    {
        Http::fake([
            '*/tv/999*' => Http::response($this->ficha('Série sem seasons', [], 3)),
        ]);

        $serie = $this->service()->detalhesSerie(999);

        $this->assertSame(3, $serie['numero_temporadas']);
        $this->assertSame([], $serie['temporadas']);
    }
}
