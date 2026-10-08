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
 * não é veredito: é só o resultado de uma tentativa que não valeu.
 *
 * `deve_retentar` é a política de retentativa, separada do fato registrado. Quem
 * não deu para concluir volta à fila na virada do dia; o veredito definitivo
 * espera a janela longa antes de ser reexaminado. A marca é lida por
 * [`expirado()`], que é quem responde à rotina se a prova já venceu.
 */
class Disponibilidade extends Model
{
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

    /**
     * A prova ainda vale?
     *
     * Título com fonte não é re-testado enquanto a fonte estiver de pé; título sem
     * fonte volta à fila quando a prova vence. O prazo, porém, não é o mesmo para
     * todo mundo: quem pediu nova chance (`deve_retentar`) volta na virada do dia,
     * e o veredito definitivo só é reexaminado depois da janela longa. Sem data de
     * verificação, a linha nunca é considerada válida — é o caso de um registro
     * criado e nunca preenchido.
     */
    public function expirado(): bool
    {
        if ($this->verificado_em === null) {
            return true;
        }

        return $this->verificado_em->lte(now()->subHours($this->horasDeValidade()));
    }

    /**
     * Quantas horas a prova vale antes de o título voltar à fila.
     *
     * A marca decide a janela: curta para quem pediu nova chance, longa para o
     * veredito definitivo. Um "sem fonte" que perguntou a todos os provedores não
     * muda de um dia para o outro, e insistir nele todo dia só gastaria o ritmo
     * furtivo da rotina com o que o acervo já respondeu.
     */
    private function horasDeValidade(): int
    {
        if ($this->deve_retentar) {
            return (int) config('services.verificacao.validade_horas', 24);
        }

        return (int) config('services.verificacao.validade_dias_definitivo', 7) * 24;
    }
}
