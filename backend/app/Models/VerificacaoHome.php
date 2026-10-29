<?php

namespace App\Models;

use App\Enums\StatusVerificacao;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Estado da verificação de uma página da Home.
 *
 * A chave é a própria página, então não há auto-incremento: o número que o
 * frontend manda em `?page=` é o mesmo que identifica a linha aqui. Quando o
 * `status` está `ok`, o `itens` está pronto para ser servido no lugar do TMDB cru.
 */
class VerificacaoHome extends Model
{
    protected $table = 'verificacoes_home';

    protected $primaryKey = 'pagina';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'pagina',
        'status',
        'itens',
        'total_paginas',
        'itens_tmdb',
        'verificada_em',
    ];

    protected $casts = [
        'status' => StatusVerificacao::class,
        'itens' => 'array',
        'verificada_em' => 'datetime',
    ];

    /** A página já pode substituir o TMDB cru na Home. */
    public function pronta(): bool
    {
        return $this->status === StatusVerificacao::OK;
    }

    /**
     * Páginas prontas cuja prova passou da validade.
     *
     * É a definição de "a Home está devendo uma rodada": o [`CatalogoVerificado`]
     * ignora `itens` vencido e volta ao TMDB do dia, então contar essas linhas é
     * o mesmo que contar quantas páginas estão servindo o catálogo cru. O escopo
     * existe para o `fontes:status` e para o resgate do agendador usarem a
     * **mesma** régua — se divergissem, o status diria "tudo fresco" e o resgate
     * não rodaria.
     */
    public function scopeComProvaVencida(Builder $query, int $horas): Builder
    {
        $limite = now()->subHours($horas);

        return $query->where('status', StatusVerificacao::OK->value)
            ->where(function (Builder $query) use ($limite): void {
                $query->where('verificada_em', '<', $limite)
                    ->orWhereNull('verificada_em');
            });
    }
}
