<?php

namespace App\Services;

use App\Enums\ClassificacaoIndicativa;
use App\Enums\Genero;
use App\Enums\TamanhoImagem;
use App\Enums\TipoVideo;
use App\Support\MensagensFilme;
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
    /**
     * Filmes mais assistidos/populares do Brasil.
     *
     * Devolve a lista normalizada junto com os metadados de paginação do TMDB.
     * O frontend precisa saber se ainda há páginas seguintes para decidir se
     * continua alimentando a rolagem infinita da Home.
     *
     * @return array{resultados: array<int, array<string, mixed>>, pagina: int, total_paginas: int}
     */
    public function populares(int $pagina = 1): array
    {
        $teto = max(1, (int) config('services.tmdb.max_pages', 25));

        // Acima do teto nem consultamos o TMDB: devolvemos vazio com
        // `total_paginas` igual à página pedida, o que faz `has_more` virar falso
        // e encerra a rolagem infinita com a mensagem de fim.
        if ($pagina > $teto) {
            return [
                'resultados' => [],
                'pagina' => $pagina,
                'total_paginas' => $pagina,
            ];
        }

        try {
            $payload = $this->requisitar('/movie/popular', [
                'page' => $pagina,
                'region' => config('services.tmdb.region'),
            ]);
        } catch (RuntimeException $excecao) {
            // O TMDB responde HTTP 400 quando a página pedida passa do limite do
            // catálogo. Isso não é uma falha: significa que a lista acabou. Nesse
            // caso devolvemos vazio com `total_paginas` igual à página pedida, o
            // que faz `has_more` virar falso e o frontend exibir o fim da lista
            // em vez de um erro.
            if ($this->paginaForaDoLimite($excecao)) {
                return [
                    'resultados' => [],
                    'pagina' => $pagina,
                    'total_paginas' => $pagina,
                ];
            }

            throw $excecao;
        }

        $resultados = $payload['results'] ?? [];

        // Uma página vazia é sinal definitivo de fim de catálogo, mesmo que o
        // TMDB ainda informe um `total_pages` maior. Sem isso, a rolagem infinita
        // ficaria pedindo páginas vazias indefinidamente e nunca exibiria o fim.
        if (empty($resultados)) {
            return [
                'resultados' => [],
                'pagina' => $pagina,
                'total_paginas' => $pagina,
            ];
        }

        return [
            'resultados' => $this->normalizarLista($resultados),
            'pagina' => (int) ($payload['page'] ?? $pagina),
            // O teto entra no cálculo do `has_more` feito pelo controller: mesmo
            // que o TMDB informe centenas de páginas, paramos no limite.
            'total_paginas' => min((int) ($payload['total_pages'] ?? 1), $teto),
        ];
    }

    /**
     * Tendências do dia misturando filmes e séries (Home unificada).
     *
     * O `/trending/all/day` devolve filmes, séries e animação no mesmo fluxo,
     * cada item marcado com `media_type`. É o que permite a Home exibir tudo
     * junto em vez de só filmes. O envelope de paginação é idêntico ao de
     * `populares()`, então a rolagem infinita do frontend funciona sem mudança.
     *
     * @return array{resultados: array<int, array<string, mixed>>, pagina: int, total_paginas: int}
     */
    public function tendenciasDoDia(int $pagina = 1): array
    {
        $teto = max(1, (int) config('services.tmdb.max_pages', 25));

        if ($pagina > $teto) {
            return [
                'resultados' => [],
                'pagina' => $pagina,
                'total_paginas' => $pagina,
            ];
        }

        try {
            $payload = $this->requisitar('/trending/all/day', [
                'page' => $pagina,
            ]);
        } catch (RuntimeException $excecao) {
            if ($this->paginaForaDoLimite($excecao)) {
                return [
                    'resultados' => [],
                    'pagina' => $pagina,
                    'total_paginas' => $pagina,
                ];
            }

            throw $excecao;
        }

        $resultados = $payload['results'] ?? [];

        if (empty($resultados)) {
            return [
                'resultados' => [],
                'pagina' => $pagina,
                'total_paginas' => $pagina,
            ];
        }

        // O trending pode trazer lançamentos futuros (estreias anunciadas). A
        // Home só deve mostrar o que já está disponível, então descartamos o
        // que tem data de lançamento/estreia posterior a hoje.
        $resultados = array_values(array_filter(
            $resultados,
            fn (array $item) => ! $this->ehLancamentoFuturo($item)
        ));

        if (empty($resultados)) {
            return [
                'resultados' => [],
                'pagina' => $pagina,
                'total_paginas' => $pagina,
            ];
        }

        return [
            'resultados' => $this->normalizarLista($resultados),
            'pagina' => (int) ($payload['page'] ?? $pagina),
            'total_paginas' => min((int) ($payload['total_pages'] ?? 1), $teto),
        ];
    }

    /**
     * Diz se o item do trending ainda não foi lançado.
     *
     * Filmes usam `release_date` e séries usam `first_air_date`. Quando a data
     * está ausente não há como afirmar que é futuro, então o item é mantido —
     * descartar por falta de dado esconderia conteúdo válido.
     *
     * @param  array<string, mixed>  $item
     */
    private function ehLancamentoFuturo(array $item): bool
    {
        $data = $item['release_date'] ?? $item['first_air_date'] ?? null;

        if (empty($data)) {
            return false;
        }

        return $data > now()->toDateString();
    }

    /**
     * Identifica o erro de "página além do limite" do TMDB (HTTP 400), que na
     * prática sinaliza o fim do catálogo e não uma falha real.
     */
    private function paginaForaDoLimite(RuntimeException $excecao): bool
    {
        return str_contains($excecao->getMessage(), 'HTTP 400');
    }

    /**
     * Busca filmes e séries pelo título informado na navbar.
     *
     * Usamos `/search/multi` em vez de `/search/movie` porque a Home unificada
     * exibe os dois tipos: buscar só filmes fazia séries como "American Horror
     * Story" aparecerem no trending mas sumirem na pesquisa. O endpoint multi
     * também devolve pessoas, que descartamos — elas não têm `media_type` de
     * filme/série e quebrariam a normalização.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buscar(string $termo, int $pagina = 1): array
    {
        $termo = trim($termo);

        if ($termo === '') {
            return [];
        }

        $payload = $this->requisitar('/search/multi', [
            'query' => $termo,
            'page' => $pagina,
            'region' => config('services.tmdb.region'),
        ]);

        // O multi mistura filmes, séries e pessoas. Mantemos apenas os dois
        // primeiros: pessoas não têm capa/sinopse no mesmo contrato e não são
        // clicáveis na Home.
        $resultados = array_values(array_filter(
            $payload['results'] ?? [],
            fn (array $item) => in_array($item['media_type'] ?? null, ['movie', 'tv'], true)
        ));

        return $this->normalizarLista($resultados);
    }

    /**
     * Detalhes completos de um filme, usados pelo modal estilo Netflix.
     *
     * @return array<string, mixed>
     */
    public function detalhes(int $id): array
    {
        // Além das datas de lançamento (classificação), trazemos vídeos e
        // créditos para o modal exibir trailer e elenco sem novas requisições.
        $payload = $this->requisitar("/movie/{$id}", [
            'append_to_response' => 'release_dates,videos,credits',
        ]);

        return $this->normalizarFilme($payload, $payload['release_dates'] ?? null);
    }

    /**
     * Detalhes completos de uma série, usados pelo modal de série.
     *
     * A base é a mesma do filme (reaproveitamos `normalizarFilme()`), mas o
     * endpoint é `/tv/{id}` e a classificação vem de `content_ratings` em vez de
     * `release_dates`. Acrescentamos ainda a lista de temporadas para o seletor
     * do modal — sem ela o frontend não teria como montar os balões.
     *
     * @return array<string, mixed>
     */
    public function detalhesSerie(int $id): array
    {
        // O `external_ids` é obrigatório aqui: diferente de `/movie/{id}`, o
        // endpoint `/tv/{id}` não devolve `imdb_id` no corpo principal — ele só
        // aparece dentro de `external_ids`. Sem pedi-lo, toda série saía com
        // `imdb_id` nulo e o Torrentio (que só busca por identificador) abstinha-se
        // em silêncio, deixando apenas os provedores de busca por nome (APIBay)
        // responderem. Era por isso que "American Horror Story" só achava fonte
        // no APIBay.
        $payload = $this->requisitar("/tv/{$id}", [
            'append_to_response' => 'content_ratings,videos,credits,external_ids',
        ]);

        // O `imdb_id` da série vive em `external_ids`; copiamos para a raiz do
        // payload para que `normalizarFilme()` o encontre no mesmo lugar em que
        // ele aparece no payload de filme.
        if (empty($payload['imdb_id']) && ! empty($payload['external_ids']['imdb_id'])) {
            $payload['imdb_id'] = $payload['external_ids']['imdb_id'];
        }

        $serie = $this->normalizarFilme($payload, null, $this->classificacaoDeSerie($payload));

        // A duração de uma série não é um número único: cada episódio tem a sua.
        // O `episode_run_time` costuma trazer a duração típica, então usamos a
        // primeira como referência para o cabeçalho do modal.
        $serie['duracao'] = $this->formatarDuracao($payload['episode_run_time'][0] ?? null);

        $serie['numero_temporadas'] = (int) ($payload['number_of_seasons'] ?? 0);
        $serie['numero_episodios'] = (int) ($payload['number_of_episodes'] ?? 0);
        $serie['temporadas'] = $this->normalizarTemporadas($payload['seasons'] ?? []);

        return $serie;
    }

    /**
     * Episódios de uma temporada específica.
     *
     * O TMDB devolve os episódios já ordenados; normalizamos cada um para o
     * contrato do frontend (capa, sinopse, título, nota e duração por episódio).
     *
     * @return array<string, mixed>
     */
    public function temporada(int $id, int $numero): array
    {
        $payload = $this->requisitar("/tv/{$id}/season/{$numero}");

        $episodios = array_map(
            fn (array $episodio) => $this->normalizarEpisodio($episodio),
            $payload['episodes'] ?? []
        );

        return [
            'temporada' => (int) ($payload['season_number'] ?? $numero),
            'nome' => $payload['name'] ?? null,
            'sinopse' => $payload['overview'] ?: null,
            'capa' => $this->montarImagem($payload['poster_path'] ?? null, TamanhoImagem::POSTER),
            'ano' => $this->extrairAno($payload['air_date'] ?? null),
            'episodios' => $episodios,
        ];
    }

    /**
     * Extrai a classificação indicativa brasileira de uma série.
     *
     * Séries não têm `release_dates`; a classificação vive em `content_ratings`,
     * com a mesma estrutura de país + certificação. Mantemos a lógica separada
     * para não misturar os dois formatos dentro de `extrairClassificacao()`.
     *
     * @param  array<string, mixed>  $serie
     */
    private function classificacaoDeSerie(array $serie): string
    {
        if (isset($serie['adult']) && $serie['adult'] === true) {
            return ClassificacaoIndicativa::DEZOITO->rotulo();
        }

        foreach ($serie['content_ratings']['results'] ?? [] as $pais) {
            if (($pais['iso_3166_1'] ?? null) !== 'BR') {
                continue;
            }

            $certificacao = trim((string) ($pais['rating'] ?? ''));

            if ($certificacao !== '') {
                return ClassificacaoIndicativa::normalizar($certificacao);
            }
        }

        return MensagensFilme::NAO_CLASSIFICADA;
    }

    /**
     * Normaliza a lista de temporadas para o seletor do modal.
     *
     * A temporada 0 é o "Especiais" do TMDB e não faz parte da numeração
     * regular, então fica de fora para não confundir o usuário.
     *
     * @param  array<int, array<string, mixed>>  $temporadas
     * @return array<int, array<string, mixed>>
     */
    private function normalizarTemporadas(array $temporadas): array
    {
        $normalizadas = array_map(
            fn (array $temporada) => [
                'numero' => (int) ($temporada['season_number'] ?? 0),
                'nome' => $temporada['name'] ?? null,
                'qtd_episodios' => (int) ($temporada['episode_count'] ?? 0),
                'ano' => $this->extrairAno($temporada['air_date'] ?? null),
                'capa' => $this->montarImagem($temporada['poster_path'] ?? null, TamanhoImagem::POSTER),
            ],
            $temporadas
        );

        return array_values(array_filter(
            $normalizadas,
            fn (array $temporada) => $temporada['numero'] > 0
        ));
    }

    /**
     * Converte um episódio cru do TMDB no contrato do frontend.
     *
     * @param  array<string, mixed>  $episodio
     * @return array<string, mixed>
     */
    private function normalizarEpisodio(array $episodio): array
    {
        return [
            'numero' => (int) ($episodio['episode_number'] ?? 0),
            'temporada' => (int) ($episodio['season_number'] ?? 0),
            'titulo' => $episodio['name'] ?: MensagensFilme::TITULO_INDISPONIVEL,
            'sinopse' => $episodio['overview'] ?: MensagensFilme::SINOPSE_INDISPONIVEL,
            'capa' => $this->montarImagem($episodio['still_path'] ?? null, TamanhoImagem::POSTER),
            'nota' => isset($episodio['vote_average']) ? round((float) $episodio['vote_average'], 1) : null,
            'duracao' => $this->formatarDuracao($episodio['runtime'] ?? null),
            'data_exibicao' => $episodio['air_date'] ?? null,
        ];
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
     * Normaliza uma listagem de filmes ou séries (popular / search / trending).
     *
     * @param  array<int, array<string, mixed>>  $resultados
     * @return array<int, array<string, mixed>>
     */
    private function normalizarLista(array $resultados): array
    {
        // O TMDB ignora `append_to_response` em endpoints de listagem, então a
        // classificação indicativa só existe em endpoints próprios: release_dates
        // para filmes e content_ratings para séries. Buscamos em paralelo (com
        // cache por item) para não serializar N requisições e travar a Home.
        $classificacoes = $this->classificacoesEmLote($resultados);

        return array_map(
            fn (array $item) => $this->normalizarFilme(
                $item,
                null,
                $classificacoes[$this->chaveClassificacao($item)] ?? null
            ),
            $resultados
        );
    }

    /**
     * Chave de cache da classificação, distinguindo filme de série.
     *
     * Um filme e uma série podem compartilhar o mesmo id numérico no TMDB, então
     * a chave precisa do tipo para não devolver a classificação errada.
     *
     * @param  array<string, mixed>  $item
     */
    private function chaveClassificacao(array $item): string
    {
        return $this->tipoDe($item).':'.($item['id'] ?? 0);
    }

    /**
     * Descobre o tipo do item do TMDB.
     *
     * O `/trending/all/day` marca cada item com `media_type`. Nos endpoints de
     * filme (`/movie/...`) o campo não existe, então o padrão é `movie`.
     *
     * @param  array<string, mixed>  $item
     */
    private function tipoDe(array $item): string
    {
        return ($item['media_type'] ?? 'movie') === 'tv' ? 'tv' : 'movie';
    }

    /**
     * Resolve a classificação indicativa brasileira de vários itens de uma vez,
     * sejam filmes ou séries. Cada item tem seu próprio cache, então visitas
     * repetidas não geram requisições novas.
     *
     * @param  array<int, array<string, mixed>>  $itens
     * @return array<string, string>  Mapa "tipo:id" => classificação
     */
    private function classificacoesEmLote(array $itens): array
    {
        $ttl = (int) config('services.tmdb.cache_ttl', 3600);

        // Só consulta o TMDB para os itens que ainda não estão em cache.
        $classificacoes = [];
        $pendentes = [];

        foreach ($itens as $item) {
            $id = $item['id'] ?? null;

            if (empty($id)) {
                continue;
            }

            $chave = $this->chaveClassificacao($item);
            $valor = Cache::get("tmdb:classificacao:{$chave}");

            if ($valor !== null) {
                $classificacoes[$chave] = $valor;
            } else {
                $pendentes[$chave] = $item;
            }
        }

        if (empty($pendentes)) {
            return $classificacoes;
        }

        $respostas = Http::pool(function ($pool) use ($pendentes) {
            return array_map(
                function ($item) use ($pool) {
                    $tipo = $this->tipoDe($item);
                    $id = $item['id'];

                    // Filmes expõem a classificação em release_dates; séries, em
                    // content_ratings. São endpoints distintos com o mesmo papel.
                    $endpoint = $tipo === 'tv'
                        ? "/tv/{$id}/content_ratings"
                        : "/movie/{$id}/release_dates";

                    return $pool->as($this->chaveClassificacao($item))
                        ->baseUrl(config('services.tmdb.base_url'))
                        ->acceptJson()
                        ->timeout(15)
                        ->get($endpoint, [
                            'api_key' => config('services.tmdb.key'),
                            'language' => config('services.tmdb.language'),
                        ]);
                },
                $pendentes
            );
        });

        foreach ($pendentes as $chave => $item) {
            $resposta = $respostas[$chave] ?? null;

            if (! $resposta || $resposta->failed()) {
                continue;
            }

            $classificacao = $this->extrairClassificacao([], $resposta->json());
            $classificacoes[$chave] = $classificacao;

            Cache::put("tmdb:classificacao:{$chave}", $classificacao, $ttl);
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
            // O tipo distingue filme de série na Home unificada. O frontend usa
            // isso para decidir o que fazer no clique (o modal de série ainda não
            // existe) e para rotular o card.
            'tipo' => $this->tipoDe($filme),
            // O imdb_id é o identificador mais preciso para a busca de fontes de
            // torrent: o título sozinho gera falsos positivos (remakes, títulos
            // traduzidos). Só existe no endpoint de detalhes.
            'imdb_id' => $filme['imdb_id'] ?? null,
            'titulo' => $filme['title'] ?? $filme['name'] ?? MensagensFilme::TITULO_INDISPONIVEL,
            // O título original entra como alternativa de busca: o tracker
            // nacional publica pelo nome traduzido, mas o release em si — e os
            // indexadores internacionais — costumam usar o original. Tentar os
            // dois evita perder a fonte quando a tradução ficou ambígua.
            'titulo_original' => $filme['original_title'] ?? $filme['original_name'] ?? null,
            // Nem todo item da listagem traz `overview` (acontece em páginas
            // mais profundas da Home). O acesso direto quebrava a requisição
            // inteira com "Undefined array key", então tratamos a ausência.
            'sinopse' => ($filme['overview'] ?? '') ?: MensagensFilme::SINOPSE_INDISPONIVEL,
            'capa' => $this->montarImagem($filme['poster_path'] ?? null, TamanhoImagem::POSTER),
            'backdrop' => $this->montarImagem($filme['backdrop_path'] ?? null, TamanhoImagem::BACKDROP),
            'backdrop_alta' => $this->montarImagem($filme['backdrop_path'] ?? null, TamanhoImagem::ORIGINAL),
            'generos' => $generos,
            'genero' => $generos[0] ?? MensagensFilme::GENERO_NAO_INFORMADO,
            'classificacao' => $classificacao ?? $this->extrairClassificacao($filme, $releaseDates),
            'nota' => isset($filme['vote_average']) ? round((float) $filme['vote_average'], 1) : null,
            // Filmes usam `release_date`; séries usam `first_air_date`. Sem o
            // fallback, toda série apareceria sem ano na Home.
            'ano' => $this->extrairAno($filme['release_date'] ?? $filme['first_air_date'] ?? null),
            'duracao' => $this->formatarDuracao($filme['runtime'] ?? null),
            'elenco' => $this->extrairElenco($filme['credits'] ?? null),
            'trailer' => $this->extrairTrailer($filme['videos'] ?? null),
        ];
    }

    /**
     * Converte a duração em minutos para o formato "2h 15min".
     * Devolve null quando o TMDB não informa o runtime (comum em lançamentos).
     */
    private function formatarDuracao(?int $minutos): ?string
    {
        if (empty($minutos) || $minutos <= 0) {
            return null;
        }

        $horas = intdiv($minutos, 60);
        $restante = $minutos % 60;

        if ($horas === 0) {
            return "{$restante}min";
        }

        return $restante > 0 ? "{$horas}h {$restante}min" : "{$horas}h";
    }

    /**
     * Seleciona os principais nomes do elenco para exibição no modal.
     *
     * @param  array<string, mixed>|null  $credits
     * @return array<int, string>
     */
    private function extrairElenco(?array $credits): array
    {
        $elenco = $credits['cast'] ?? [];

        if (empty($elenco)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (array $ator) => $ator['name'] ?? null,
            array_slice($elenco, 0, MensagensFilme::LIMITE_ELENCO)
        )));
    }

    /**
     * Procura o trailer oficial no YouTube. Preferimos o trailer oficial
     * publicado no canal do YouTube e, na ausência dele, qualquer vídeo do tipo
     * Trailer — o modal só precisa da chave para montar a URL de embed.
     */
    private function extrairTrailer(?array $videos): ?string
    {
        $resultados = $videos['results'] ?? [];

        if (empty($resultados)) {
            return null;
        }

        $trailers = array_values(array_filter(
            $resultados,
            fn (array $video) => ($video['site'] ?? null) === TipoVideo::YOUTUBE->value
                && ($video['type'] ?? null) === TipoVideo::TRAILER->value
        ));

        if (empty($trailers)) {
            return null;
        }

        $oficial = array_values(array_filter(
            $trailers,
            fn (array $video) => ($video['official'] ?? false) === true
        ));

        return ($oficial[0]['key'] ?? $trailers[0]['key'] ?? null) ?: null;
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
                fn ($genero) => Genero::rotuloPorId($genero['id'] ?? null) ?? ($genero['name'] ?? null),
                $filme['genres']
            )));
        }

        $ids = $filme['genre_ids'] ?? [];

        return array_values(array_filter(array_map(
            fn ($id) => Genero::rotuloPorId($id),
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
            return ClassificacaoIndicativa::DEZOITO->rotulo();
        }

        $resultados = $releaseDates['results'] ?? [];

        foreach ($resultados as $pais) {
            if (($pais['iso_3166_1'] ?? null) !== 'BR') {
                continue;
            }

            foreach ($pais['release_dates'] ?? [] as $data) {
                $certificacao = trim((string) ($data['certification'] ?? ''));

                if ($certificacao !== '') {
                    return ClassificacaoIndicativa::normalizar($certificacao);
                }
            }
        }

        return MensagensFilme::NAO_CLASSIFICADA;
    }

    private function extrairAno(?string $data): ?int
    {
        if (empty($data)) {
            return null;
        }

        $ano = (int) substr($data, 0, 4);

        return $ano > 0 ? $ano : null;
    }

    private function montarImagem(?string $caminho, TamanhoImagem $tamanho): ?string
    {
        if (empty($caminho)) {
            return null;
        }

        return rtrim(config('services.tmdb.image_url'), '/')."/{$tamanho->value}{$caminho}";
    }
}
