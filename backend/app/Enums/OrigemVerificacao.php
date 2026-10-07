<?php

namespace App\Enums;

/**
 * De onde partiu a verificação de um título.
 *
 * O mesmo veredito pode ter sido produzido pela rotina em lote (`comando`), na
 * hora em que o usuário abriu as fontes (`play`) ou no meio de uma busca
 * (`busca`). Guardar a origem responde à pergunta que importa quando se afina a
 * rotina: o que ela sozinha já cobriu e o que só apareceu porque alguém pediu.
 */
enum OrigemVerificacao: string
{
    case COMANDO = 'comando';
    case PLAY = 'play';
    case BUSCA = 'busca';

    /** Rótulo exibido na interface e nos comandos. */
    public function rotulo(): string
    {
        return match ($this) {
            self::COMANDO => 'Rotina',
            self::PLAY => 'Reprodução',
            self::BUSCA => 'Busca',
        };
    }
}
