<?php

namespace App\Services\Verificacao;

use App\Enums\StatusVerificacao;
use App\Models\VerificacaoHome;
use Illuminate\Support\Carbon;

/**
 * Leitura da Home verificada.
 *
 * A Home chama isto antes de pedir o TMDB: se a página já foi verificada, o
 * resultado vem do banco (só o que tem fonte), no mesmo formato que o
 * [`TmdbService`] devolveria — assim o controller não sabe nem se importa de onde
 * a lista veio. Página ainda não verificada devolve `null`, e o controller cai
 * para o TMDB cru.
 *
 * A verificação **vence**: passado o prazo de `verificacao.validade_horas`, a
 * página volta a valer como "não pronta" e a Home serve o TMDB do dia. Sem esse
 * prazo a lista ficava congelada no dia em que foi verificada — foi o que
 * aconteceu quando a rotina diária parou de rodar: as páginas 1 e 2 seguiram
 * `ok`, com os títulos de vinte dias antes, e a Home nunca mais mudou.
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

        // Prova vencida não é prova: a Home volta ao TMDB do dia até a rodada
        // seguinte regravar a lista. Sem log aqui — a checagem acontece a cada
        // requisição da Home, e o `fontes:status` já mostra o frescor.
        if ($this->venceu($registro)) {
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

    /**
     * A prova desta página já perdeu a validade?
     *
     * A data usada é a da verificação; `updated_at` entra como reserva para as
     * linhas gravadas antes de a coluna `verificada_em` existir. Validade zero ou
     * negativa desliga o prazo (a página vale para sempre) — é o que permite
     * congelar a Home numa apresentação, se algum dia for preciso.
     */
    private function venceu(VerificacaoHome $registro): bool
    {
        $horas = $this->validadeHoras();

        if ($horas <= 0) {
            return false;
        }

        $marca = $registro->verificada_em ?? $registro->updated_at;

        if (! $marca instanceof Carbon) {
            return true;
        }

        return $marca->lessThan(now()->subHours($horas));
    }

    private function validadeHoras(): int
    {
        return (int) config('services.verificacao.validade_horas', 24);
    }
}
