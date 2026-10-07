<?php

namespace App\Models;

use App\Enums\OrigemVerificacao;
use Illuminate\Database\Eloquent\Model;

/**
 * Registro de uma execução da rotina de verificação.
 *
 * O status é guardado como texto simples porque tem um valor que não pertence ao
 * ciclo de vida da página — `falha`. O `finalizada_em` nulo é o que denuncia uma
 * execução interrompida no meio (o processo morreu, o container caiu).
 */
class VerificacaoExecucao extends Model
{
    /** A execução foi concluída do começo ao fim. */
    public const STATUS_OK = 'ok';

    /** A execução começou mas ainda não terminou. */
    public const STATUS_EM_ANDAMENTO = 'em_andamento';

    /** A execução parou no meio por erro. */
    public const STATUS_FALHA = 'falha';

    protected $table = 'verificacoes_execucoes';

    protected $fillable = [
        'origem',
        'status',
        'iniciada_em',
        'finalizada_em',
        'paginas_processadas',
        'titulos_testados',
        'titulos_com_fonte',
        'detalhe',
    ];

    protected $casts = [
        'origem' => OrigemVerificacao::class,
        'iniciada_em' => 'datetime',
        'finalizada_em' => 'datetime',
    ];
}
