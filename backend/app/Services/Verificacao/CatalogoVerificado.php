<?php

namespace App\Services\Verificacao;

use App\Enums\StatusVerificacao;
use App\Models\VerificacaoHome;

/**
 * Leitura da Home verificada.
 *
 * A Home chama isto antes de pedir o TMDB: se a página já foi verificada, o
 * resultado vem do banco (só o que tem fonte), no mesmo formato que o
 * [`TmdbService`] devolveria — assim o controller não sabe nem se importa de onde
 * a lista veio. Página ainda não verificada devolve `null`, e o controller cai
 * para o TMDB cru.
 */
class CatalogoVerificado
{
    /** A verificação está ligada? Desligada, a Home volta ao comportamento antigo. */
    public function habilitado(): bool
    {
        return (bool) config('services.verificacao.habilitada', true);
    }

    /**
     * Itens verificados de uma página, ou `null` quando ela ainda não está pronta.
     *
     * O retorno espelha o de `TmdbService::tendenciasDoDia()` para o controller
     * tratar os dois caminhos do mesmo jeito.
     *
     * @return array{resultados: array<int, array<string, mixed>>, pagina: int, total_paginas: int}|null
     */
    public function itensDaPagina(int $pagina): ?array
    {
        if (! $this->habilitado()) {
            return null;
        }

        $registro = VerificacaoHome::query()
            ->where('pagina', $pagina)
            ->where('status', StatusVerificacao::OK->value)
            ->first();

        if ($registro === null) {
            return null;
        }

        return [
            'resultados' => $registro->itens ?? [],
            'pagina' => (int) $registro->pagina,
            // Sem o total gravado, a página não pode se declarar a última —
            // melhor assumir que é, do que prometer páginas que não existem.
            'total_paginas' => (int) ($registro->total_paginas ?? $registro->pagina),
        ];
    }
}
