<?php

namespace App\Enums;

/**
 * Estado da verificação de uma página da Home.
 *
 * A página nasce `pendente`; enquanto não está `ok`, a Home serve a lista crua do
 * TMDB. Quando a rotina termina de testar os itens e grava a lista verificada, a
 * página vira `ok` e a Home passa a servir só o que tem fonte provada.
 *
 * `em_andamento` marca a página que está sendo processada agora — é o que evita
 * o status enxergar uma execução em curso como "nunca vista" e disparar trabalho
 * duplicado.
 */
enum StatusVerificacao: string
{
    case PENDENTE = 'pendente';
    case EM_ANDAMENTO = 'em_andamento';
    case OK = 'ok';

    /** Rótulo exibido na interface e nos comandos. */
    public function rotulo(): string
    {
        return match ($this) {
            self::PENDENTE => 'Pendente',
            self::EM_ANDAMENTO => 'Em andamento',
            self::OK => 'Verificada',
        };
    }
}
