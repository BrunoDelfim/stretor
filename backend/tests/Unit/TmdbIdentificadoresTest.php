<?php

namespace Tests\Unit;

use App\Services\TmdbService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O `imdb_id` é o identificador que o Torrentio e o addon Stremio usam para
 * procurar, e a lista de tendências não o traz.
 *
 * A verificação da Home usava o item cru e ficava sem identificador — enquanto o
 * player, que busca a ficha completa em `/fontes`, encontrava o título. O
 * resultado era um selo de "sem fonte" em cima de algo que toca.
 */
class TmdbIdentificadoresTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.tmdb.key' => 'chave-de-teste',
            'services.tmdb.base_url' => 'https://api.themoviedb.org/3',
        ]);

        Cache::flush();
    }

    public function test_resolve_o_identificador_por_tipo(): void
    {
        Http::fake([
            'api.themoviedb.org/3/movie/1384216/external_ids*' => Http::response(['imdb_id' => 'tt21285562']),
            'api.themoviedb.org/3/tv/1413/external_ids*' => Http::response(['imdb_id' => 'tt1844624']),
        ]);

        $mapa = (new TmdbService())->identificadoresEmLote([
            ['id' => 1384216, 'tipo' => 'movie'],
            ['id' => 1413, 'tipo' => 'tv'],
        ]);

        $this->assertSame('tt21285562', $mapa['movie:1384216']);
        $this->assertSame('tt1844624', $mapa['tv:1413']);
    }

    public function test_item_que_ja_trouxe_o_identificador_nao_pede_de_novo(): void
    {
        Http::fake(['*' => Http::response(['imdb_id' => 'tt0000001'])]);

        $mapa = (new TmdbService())->identificadoresEmLote([
            ['id' => 550, 'tipo' => 'movie', 'imdb_id' => 'tt0137523'],
        ]);

        $this->assertSame('tt0137523', $mapa['movie:550']);
        Http::assertNothingSent();
    }

    /**
     * "Não tem imdb" também é resposta: sem o cache negativo, cada rodada faria
     * a mesma pergunta para o mesmo título sem identificador.
     */
    public function test_ausencia_de_identificador_fica_em_cache(): void
    {
        Http::fake(['*' => Http::response(['imdb_id' => null])]);

        $servico = new TmdbService();
        $servico->identificadoresEmLote([['id' => 10, 'tipo' => 'movie']]);

        Http::fake(['*' => Http::response(['imdb_id' => 'tt9999999'])]);

        $this->assertSame([], $servico->identificadoresEmLote([['id' => 10, 'tipo' => 'movie']]));
        Http::assertNothingSent();
    }
}
