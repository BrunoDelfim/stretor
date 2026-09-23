<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente de integração com o catálogo mundial de filmes (TMDB).
 *
 * Toda a comunicação com o TMDB passa por aqui: a chave da API nunca sai do
 * backend e as respostas são normalizadas para um contrato único consumido
 * pelo frontend. O cache em Redis evita estourar o rate limit da API e deixa
 * a Home instantânea em visitas repetidas.
 */
class TmdbService
{
    private const GENEROS = [
        28 => 'Ação',
        12 => 'Aventura',
        16 => 'Animação',
        35 => 'Comédia',
        80 => 'Crime',
        99 => 'Documentário',
        18 => 'Drama',
        10751 => 'Família',
        14 => 'Fantasia',
        36 => 'História',
        27 => 'Terror',
        10402 => 'Música',
        9648 => 'Mistério',
        10749 => 'Romance',
        878 => 'Ficção Científica',
        10770 => 'Cinema TV',
        53 => 'Suspense',
        10752 => 'Guerra',
        37 => 'Faroeste',
    ];

    private const CLASSIFICACOES = [
        'L' => 'L',
        '10' => '10',
        '12' => '12',
        '14' => '14',
        '16' => '16',
        '18' => '18',
    ];

    /**
     * Filmes mais assistidos/populares do Brasil.
     *
     * @return array<int, array<string, mixed>>
     */
    public function populares(int $pagina = 1): array
    {
        $payload = $this->requisitar('/movie/popular', [
            'page' => $pagina,
            'region' => config('services.tmdb.region'),
        ]);

        return $this->normalizarLista($payload['results'] ?? []);
    }

    /**
     * Busca filmes pelo título informado na navbar.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buscar(string $termo, int $pagina = 1): array
    {
        $termo = trim($termo);

        if ($termo === '') {
            return [];
        }

        $payload = $this->requisitar('/search/movie', [
            'query' => $termo,
            'page' => $pagina,
            'region' => config('services.tmdb.region'),
        ]);

        return $this->normalizarLista($payload['results'] ?? []);
    }

    /**
     * Detalhes completos de um filme, usados pelo modal estilo Netflix.
     *
     * @return array<string, mixed>
     */
    public function detalhes(int $id): array
    {
        $payload = $this->requisitar("/movie/{$id}", [
            'append_to_response' => 'release_dates',
        ]);

        return $this->normalizarFilme($payload, $payload['release_dates'] ?? null);
    }

    /**
     * Executa a requisição HTTP com cache. A chave de cache é derivada do
     * endpoint + parâmetros, garantindo que buscas diferentes não colidam.
     *
     * @param  array<string, mixed>  $parametros
     * @return array<string, mixed>
     */
    private function requisitar(string $endpoint, array $parametros = []): array
    {
        $chave = config('services.tmdb.key');

        if (empty($chave)) {
            throw new RuntimeException(
                'A chave TMDB_API_KEY não foi configurada. Defina-a no arquivo .env para carregar os filmes.'
            );
        }

        // A chave do TMDB é do tipo v3 e precisa ir como query param `api_key`.
        // Enviá-la como Bearer (v4) resulta em HTTP 401.
        $parametros['api_key'] = $chave;

        // Centraliza o idioma para que títulos, sinopses e gêneros venham em PT-BR
        // em todos os endpoints (inclusive os de detalhes).
        $parametros['language'] = $parametros['language'] ?? config('services.tmdb.language');

        $parametros = array_filter($parametros, fn ($valor) => $valor !== null && $valor !== '');

        $cacheKey = 'tmdb:'.md5($endpoint.'?'.http_build_query($parametros));
        $ttl = (int) config('services.tmdb.cache_ttl', 3600);

        return Cache::remember($cacheKey, $ttl, function () use ($endpoint, $parametros) {
            $resposta = $this->cliente()->get($endpoint, $parametros);

            if ($resposta->failed()) {
                throw new RuntimeException(
                    'Falha ao consultar o TMDB (HTTP '.$resposta->status().'). Tente novamente em instantes.'
                );
            }

            return $resposta->json() ?? [];
        });
    }

    private function cliente(): PendingRequest
    {
        return Http::baseUrl(config('services.tmdb.base_url'))
            ->acceptJson()
            ->timeout(15);
    }

    /**
     * Normaliza uma listagem de filmes (popular / search).
     *
     * @param  array<int, array<string, mixed>>  $resultados
     * @return array<int, array<string, mixed>>
     */
    private function normalizarLista(array $resultados): array
    {
        // O TMDB ignora `append_to_response` em endpoints de listagem, então a
        // classificação indicativa só existe em /movie/{id}/release_dates.
        // Buscamos em paralelo (com cache por filme) para não serializar N
        // requisições e travar a Home.
        $classificacoes = $this->classificacoesEmLote(
            array_column($resultados, 'id')
        );

        return array_map(
            fn (array $filme) => $this->normalizarFilme(
                $filme,
                null,
                $classificacoes[$filme['id'] ?? 0] ?? null
            ),
            $resultados
        );
    }

    /**
     * Resolve a classificação indicativa brasileira de vários filmes de uma vez.
     * Cada filme tem seu próprio cache, então visitas repetidas não geram
     * requisições novas.
     *
     * @param  array<int, int>  $ids
     * @return array<int, string>
     */
    private function classificacoesEmLote(array $ids): array
    {
        $ids = array_values(array_filter($ids));

        if (empty($ids)) {
            return [];
        }

        $ttl = (int) config('services.tmdb.cache_ttl', 3600);

        // Só consulta o TMDB para os ids que ainda não estão em cache.
        $classificacoes = [];
        $pendentes = [];

        foreach ($ids as $id) {
            $cacheKey = "tmdb:classificacao:{$id}";
            $valor = Cache::get($cacheKey);

            if ($valor !== null) {
                $classificacoes[$id] = $valor;
            } else {
                $pendentes[] = $id;
            }
        }

        if (empty($pendentes)) {
            return $classificacoes;
        }

        $respostas = Http::pool(function ($pool) use ($pendentes) {
            return array_map(
                fn ($id) => $pool->as((string) $id)
                    ->baseUrl(config('services.tmdb.base_url'))
                    ->acceptJson()
                    ->timeout(15)
                    ->get("/movie/{$id}/release_dates", [
                        'api_key' => config('services.tmdb.key'),
                        'language' => config('services.tmdb.language'),
                    ]),
                $pendentes
            );
        });

        foreach ($pendentes as $id) {
            $resposta = $respostas[(string) $id] ?? null;

            if (! $resposta || $resposta->failed()) {
                continue;
            }

            $classificacao = $this->extrairClassificacao([], $resposta->json());
            $classificacoes[$id] = $classificacao;

            Cache::put("tmdb:classificacao:{$id}", $classificacao, $ttl);
        }

        return $classificacoes;
    }

    /**
     * Converte o payload cru do TMDB no contrato usado pelo frontend.
     *
     * @param  array<string, mixed>  $filme
     * @param  array<string, mixed>|null  $releaseDates
     * @param  string|null  $classificacao  Classificação já resolvida em lote
     * @return array<string, mixed>
     */
    private function normalizarFilme(
        array $filme,
        ?array $releaseDates = null,
        ?string $classificacao = null
    ): array {
        $generos = $this->mapearGeneros($filme);

        return [
            'id' => $filme['id'] ?? null,
            'titulo' => $filme['title'] ?? $filme['name'] ?? 'Título indisponível',
            'sinopse' => $filme['overview'] ?: 'Sinopse não disponível para este título.',
            'capa' => $this->montarImagem($filme['poster_path'] ?? null, 'w500'),
            'backdrop' => $this->montarImagem($filme['backdrop_path'] ?? null, 'w1280'),
            'backdrop_alta' => $this->montarImagem($filme['backdrop_path'] ?? null, 'original'),
            'generos' => $generos,
            'genero' => $generos[0] ?? 'Gênero não informado',
            'classificacao' => $classificacao ?? $this->extrairClassificacao($filme, $releaseDates),
            'nota' => isset($filme['vote_average']) ? round((float) $filme['vote_average'], 1) : null,
            'ano' => $this->extrairAno($filme['release_date'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $filme
     * @return array<int, string>
     */
    private function mapearGeneros(array $filme): array
    {
        // O endpoint de detalhes já devolve os gêneros resolvidos. Como o TMDB
        // pode responder nomes em inglês mesmo com language=pt-BR, traduzimos
        // pelo id quando possível para manter a interface consistente.
        if (! empty($filme['genres']) && is_array($filme['genres'])) {
            return array_values(array_filter(array_map(
                fn ($genero) => self::GENEROS[$genero['id'] ?? null] ?? ($genero['name'] ?? null),
                $filme['genres']
            )));
        }

        $ids = $filme['genre_ids'] ?? [];

        return array_values(array_filter(array_map(
            fn ($id) => self::GENEROS[$id] ?? null,
            $ids
        )));
    }

    /**
     * O endpoint de listagem não traz classificação indicativa; ela só existe
     * em /movie/{id}/release_dates. Quando não há dado brasileiro, devolvemos
     * um rótulo neutro em vez de inventar uma idade.
     *
     * @param  array<string, mixed>  $filme
     * @param  array<string, mixed>|null  $releaseDates
     */
    private function extrairClassificacao(array $filme, ?array $releaseDates): string
    {
        if (isset($filme['adult']) && $filme['adult'] === true) {
            return '18';
        }

        $resultados = $releaseDates['results'] ?? [];

        foreach ($resultados as $pais) {
            if (($pais['iso_3166_1'] ?? null) !== 'BR') {
                continue;
            }

            foreach ($pais['release_dates'] ?? [] as $data) {
                $certificacao = trim((string) ($data['certification'] ?? ''));

                if ($certificacao !== '') {
                    return self::CLASSIFICACOES[$certificacao] ?? $certificacao;
                }
            }
        }

        return 'Não classificada';
    }

    private function extrairAno(?string $data): ?int
    {
        if (empty($data)) {
            return null;
        }

        $ano = (int) substr($data, 0, 4);

        return $ano > 0 ? $ano : null;
    }

    private function montarImagem(?string $caminho, string $tamanho): ?string
    {
        if (empty($caminho)) {
            return null;
        }

        return rtrim(config('services.tmdb.image_url'), '/')."/{$tamanho}{$caminho}";
    }
}
