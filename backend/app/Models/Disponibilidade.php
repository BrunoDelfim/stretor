<?php

namespace App\Models;

use App\Enums\OrigemVerificacao;
use App\Enums\TipoMidia;
use App\Models\Concerns\ProvaDeFonte;
use Illuminate\Database\Eloquent\Model;

/**
 * Veredito de disponibilidade de um título.
 *
 * Uma linha por (tipo, tmdb_id). `tem_fonte` é o coração do portão: só título com
 * `true` pode chegar à Home ou à busca. O resto é o rastro da prova — de onde
 * veio, qual a melhor fonte achada e quando isso foi verificado — para a rotina
 * saber o que já está resolvido e o que ainda precisa ser tentado de novo.
 *
 * `inconclusivo` guarda a diferença entre "não tem fonte" e "não deu para
 * concluir". Numa busca que não teve como perguntar a todos, o `tem_fonte = false`
 * não é veredito: é só o resultado de uma tentativa que não valeu.
 *
 * `deve_retentar` é a política de retentativa, separada do fato registrado. Quem
 * não deu para concluir volta à fila na virada do dia; o veredito definitivo
 * espera a janela longa antes de ser reexaminado. A marca é lida por
 * [`expirado()`], que é quem responde à rotina se a prova já venceu.
 */
class Disponibilidade extends Model
{
    use ProvaDeFonte;

    protected $table = 'disponibilidades';

    protected $fillable = [
        'tipo',
        'tmdb_id',
        'tem_fonte',
        'inconclusivo',
        'deve_retentar',
        'fonte_idioma',
        'fonte_tipo',
        'origem',
        'verificado_em',
    ];

    protected $casts = [
        'tipo' => TipoMidia::class,
        'origem' => OrigemVerificacao::class,
        'tem_fonte' => 'boolean',
        'inconclusivo' => 'boolean',
        'deve_retentar' => 'boolean',
        'verificado_em' => 'datetime',
    ];
}
