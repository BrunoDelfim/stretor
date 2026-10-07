<?php

namespace App\Models;

use App\Enums\OrigemVerificacao;
use App\Enums\TipoMidia;
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
 * não é veredito: é só o resultado de uma tentativa que não valeu. A rotina lê
 * esta marca para devolver o título à fila já na próxima rodada, em vez de
 * esperar a validade de uma prova que nunca existiu.
 */
class Disponibilidade extends Model
{
    protected $table = 'disponibilidades';

    protected $fillable = [
        'tipo',
        'tmdb_id',
        'tem_fonte',
        'inconclusivo',
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
        'verificado_em' => 'datetime',
    ];

    /**
     * A prova ainda vale?
     *
     * Título com fonte não é re-testado pela rotina no mesmo dia; título sem fonte
     * volta à fila depois da validade, porque o acervo dos trackers muda. Sem
     * data de verificação, a linha nunca é considerada válida — é o caso de um
     * registro criado e nunca preenchido.
     */
    public function expirado(): bool
    {
        if ($this->verificado_em === null) {
            return true;
        }

        $validade = (int) config('services.verificacao.validade_horas', 24);

        return $this->verificado_em->lte(now()->subHours($validade));
    }
}
