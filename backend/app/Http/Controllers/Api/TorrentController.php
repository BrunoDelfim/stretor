<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TmdbService;
use App\Services\TorrentService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Orquestra a busca de fontes de torrent para um filme.
 *
 * O controller só junta as duas pontas: pega os metadados do filme no catálogo
 * (título, ano e imdb_id) e delega a busca ao TorrentService. Nenhuma regra de
 * negócio mora aqui.
 */
class TorrentController extends Controller
{
    public function __construct(
        private readonly TmdbService $tmdb,
        private readonly TorrentService $torrents,
    ) {
    }

    /**
     * Fontes de torrent disponíveis para o filme informado.
     *
     * O frontend usa esta lista para percorrer as fontes em ordem de prioridade
     * até encontrar uma que conecte — por isso a resposta já vem ordenada, com
     * o dublado em PT-BR na frente.
     */
    public function fontes(int $id): JsonResponse
    {
        try {
            $filme = $this->tmdb->detalhes($id);

            $fontes = $this->torrents->fontes(
                titulo: (string) ($filme['titulo'] ?? ''),
                ano: $filme['ano'] ?? null,
                imdbId: $filme['imdb_id'] ?? null,
            );
        } catch (RuntimeException $excecao) {
            return response()->json([
                'message' => $excecao->getMessage(),
            ], 502);
        }

        return response()->json([
            'data' => [
                'filme_id' => $id,
                'titulo' => $filme['titulo'] ?? null,
                'ano' => $filme['ano'] ?? null,
                'fontes' => $fontes,
            ],
        ]);
    }
}
