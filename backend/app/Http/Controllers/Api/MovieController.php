<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\VerificarTemporada;
use App\Services\TmdbService;
use App\Services\Verificacao\CatalogoVerificado;
use App\Services\Verificacao\SeloVerificacao;
use App\Services\Verificacao\VerificadorEpisodios;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Orquestra as requisições de filmes vindas do frontend.
 * A regra de negócio e o acesso ao TMDB ficam no TmdbService.
 */
class MovieController extends Controller
{
    public function __construct(
        private readonly TmdbService $tmdb,
        private readonly CatalogoVerificado $verificado,
        private readonly SeloVerificacao $selo,
        private readonly VerificadorEpisodios $episodios,
    ) {}

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
     * Tendências do dia misturando filmes, animação e séries — alimenta a Home
     * unificada.
     *
     * O envelope é idêntico ao de `populares()` (data + meta), então a rolagem
     * infinita do frontend funciona sem nenhuma mudança.
     *
     * Quando a página já foi verificada, a lista vem do banco — só o que tem
     * fonte provada. Enquanto a verificação não chega nela, é o TMDB cru que
     * responde: a Home preenche na hora e vai ficando mais seletiva sozinha,
     * página a página, conforme a rotina avança.
     *
     * Cada item leva o selo do banco (`verificado`), independentemente da origem
     * da lista. A página verificada só guarda o que tinha fonte no dia da rodada;
     * a prova pode ter vencido depois, e o selo é o que conta essa diferença no
     * card sem obrigar o usuário a abrir o título.
     */
    public function tendencias(Request $request): JsonResponse
    {
        $pagina = max(1, (int) $request->query('page', 1));

        return $this->responder(function () use ($pagina) {
            $verificado = $this->verificado->itensDaPagina($pagina);
            $resultado = $verificado ?? $this->tmdb->tendenciasDoDia($pagina);

            return [
                'data' => $this->selo->marcar($resultado['resultados']),
                'meta' => [
                    'page' => $resultado['pagina'],
                    'total_pages' => $resultado['total_paginas'],
                    'has_more' => $resultado['pagina'] < $resultado['total_paginas'],
                    // Aviso para o frontend de que a lista já passou pelo portão.
                    'verificada' => $verificado !== null,
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
     * Detalhes de uma série para o modal de série.
     *
     * O envelope é o mesmo de `detalhes()` (tudo dentro de `data`), então o
     * frontend reaproveita a mesma leitura de resposta.
     */
    public function detalhesSerie(int $id): JsonResponse
    {
        return $this->responder(fn () => $this->tmdb->detalhesSerie($id), singular: true);
    }

    /**
     * Episódios de uma temporada específica da série.
     */
    /**
     * Episódios de uma temporada, cada um com o próprio selo.
     *
     * O `verificado` do episódio é o que o modal mostra na linha: o primeiro
     * episódio ter fonte não garante o resto, e o selo esconde essa diferença se
     * for o da série repetido dez vezes. O `meta.pendentes` diz quantos episódios
     * dessa temporada ainda não têm prova — é o que o modal usa para decidir se
     * continua pedindo a verificação em segundo plano.
     */
    public function temporada(int $id, int $numero): JsonResponse
    {
        return $this->responder(function () use ($id, $numero) {
            $dados = $this->tmdb->temporada($id, $numero);
            $episodios = $this->selo->marcarEpisodios($dados['episodios'] ?? [], $id, $numero);

            $dados['episodios'] = $episodios;

            return [
                'data' => $dados,
                'meta' => [
                    'pendentes' => count($this->episodios->pendentes($id, $numero, $episodios)),
                ],
            ];
        });
    }

    /**
     * Coloca a verificação da temporada na fila.
     *
     * O modal não espera a conta: uma temporada de doze episódios leva minutos,
     * e a requisição morreria no limite do navegador. Aqui só conferimos o que
     * falta, enfileiramos o trabalho e devolvemos os selos que já existem; o
     * frontend acompanha o resto pelo `GET` da temporada.
     *
     * `?sincrono=1` mantém o caminho antigo (verifica uma fatia agora e devolve o
     * resultado na resposta). É a rede de segurança para quando o worker da fila
     * não está no ar — sem ele, o selo ficaria parado sem o usuário entender por
     * quê.
     */
    public function verificarTemporada(Request $request, int $id, int $numero): JsonResponse
    {
        return $this->responder(function () use ($request, $id, $numero) {
            $dados = $this->tmdb->temporada($id, $numero);
            $episodios = $dados['episodios'] ?? [];

            /*
             * A abertura do modal só trabalha com o que **nunca** foi verificado.
             * Prova vencida é assunto da varredura diária: revisar o acervo
             * enquanto o usuário olha a lista faria a tela parecer instável. O
             * caminho síncrono (plano B) continua usando a fila completa, porque
             * ali o objetivo é entregar selo na hora.
             */
            $pendentes = $request->boolean('sincrono')
                ? $this->episodios->pendentes($id, $numero, $episodios)
                : $this->episodios->nuncaTestados($id, $numero, $episodios);

            if ($pendentes === []) {
                return [
                    'data' => [
                        'episodios' => $this->selo->marcarEpisodios($episodios, $id, $numero),
                        'na_fila' => false,
                    ],
                    'meta' => ['pendentes' => 0, 'na_fila' => false],
                ];
            }

            if ($request->boolean('sincrono')) {
                $resultado = $this->episodios->verificar($id, $numero, $episodios);

                return [
                    'data' => [
                        'episodios' => $this->selo->marcarEpisodios($episodios, $id, $numero),
                        'na_fila' => false,
                        'testados' => $resultado['testados'],
                    ],
                    'meta' => [
                        'pendentes' => $resultado['restantes'],
                        'na_fila' => false,
                    ],
                ];
            }

            VerificarTemporada::dispatch($id, $numero);

            return [
                'data' => [
                    'episodios' => $this->selo->marcarEpisodios($episodios, $id, $numero),
                    'na_fila' => true,
                ],
                'meta' => [
                    'pendentes' => count($pendentes),
                    'na_fila' => true,
                ],
            ];
        });
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
