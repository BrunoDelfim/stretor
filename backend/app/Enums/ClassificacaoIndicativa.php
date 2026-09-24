<?php

namespace App\Enums;

/**
 * Classificações indicativas brasileiras usadas pelo TMDB.
 *
 * O TMDB devolve a certificação como texto livre (às vezes com espaços ou
 * variações). O enum concentra os valores válidos para que a normalização
 * aconteça em um único lugar.
 */
enum ClassificacaoIndicativa: string
{
    case LIVRE = 'L';
    case DEZ = '10';
    case DOZE = '12';
    case QUATORZE = '14';
    case DEZESSEIS = '16';
    case DEZOITO = '18';

    /** Rótulo exibido na interface. */
    public function rotulo(): string
    {
        return $this->value;
    }

    /**
     * Normaliza a certificação crua do TMDB.
     *
     * Quando o valor não corresponde a uma classificação conhecida, devolvemos
     * o próprio texto recebido em vez de inventar uma idade — o modal exibe o
     * que a fonte informou.
     */
    public static function normalizar(string $certificacao): string
    {
        return self::tryFrom(trim($certificacao))?->rotulo() ?? trim($certificacao);
    }
}
