<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TmdbService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Orquestra as requisições de filmes vindas do frontend.
 * A regra de negócio e o acesso ao TMDB ficam no TmdbService.
 */
class MovieController extends Controller
{
    public function __construct(private readonly TmdbService $tmdb)
    {
    }

    /**
     * Filmes mais assistidos do Brasil — alimenta o carrossel e o grid da Home.
     *
     * Além da lista, devolve os metadados de paginação para que a rolagem
     * infinita saiba quando parar de pedir novas páginas.
     */
    public function populares(Request $request): JsonResponse
    {
        $pagina = max(1, (int) $request->query('page', 1));

        return $this->responder(function () use ($pagina) {
            $resultado = $this->tmdb->populares($pagina);

            return [
                'data' => $resultado['resultados'],
                'meta' => [
                    'page' => $resultado['pagina'],
                    'total_pages' => $resultado['total_paginas'],
                    'has_more' => $resultado['pagina'] < $resultado['total_paginas'],
                ],
            ];
        });
    }

    /**
     * Busca por título, usada pela caixa de pesquisa da navbar.
     */
    public function buscar(Request $request): JsonResponse
    {
        $termo = (string) $request->query('query', '');

        if (trim($termo) === '') {
            return response()->json(['data' => []]);
        }

        $pagina = max(1, (int) $request->query('page', 1));

        return $this->responder(fn () => $this->tmdb->buscar($termo, $pagina));
    }

    /**
     * Detalhes de um filme para o modal.
     */
    public function detalhes(int $id): JsonResponse
    {
        return $this->responder(fn () => $this->tmdb->detalhes($id), singular: true);
    }

    /**
     * Centraliza o tratamento de erro para que falhas do TMDB (chave ausente,
     * rate limit, indisponibilidade) cheguem ao frontend como JSON previsível.
     *
     * Quando o callback já devolve um envelope pronto (com `data` e `meta`),
     * ele é repassado como está; caso contrário, o resultado é embrulhado em
     * `data` para manter o contrato simples dos demais endpoints.
     */
    private function responder(callable $callback, bool $singular = false): JsonResponse
    {
        try {
            $dados = $callback();
        } catch (RuntimeException $excecao) {
            return response()->json([
                'message' => $excecao->getMessage(),
            ], 502);
        }

        if (is_array($dados) && array_key_exists('data', $dados)) {
            return response()->json($dados);
        }

        return response()->json([
            'data' => $dados,
        ]);
    }
}
