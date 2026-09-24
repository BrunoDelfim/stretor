<?php

namespace App\Support;

/**
 * Textos de fallback e limites da busca de fontes de torrent.
 *
 * Assim como os textos do catálogo, os rótulos da busca de fontes ficam
 * concentrados aqui para não saírem de sincronia entre o serviço e o contrato
 * devolvido ao frontend.
 */
final class MensagensTorrent
{
    public const SEM_FONTES = 'Nenhuma fonte encontrada para este título no momento.';

    public const FALHA_PROVEDOR = 'Não foi possível consultar as fontes agora. Tente novamente em instantes.';

    public const QUALIDADE_NAO_INFORMADA = 'Qualidade não informada';

    /** Quantidade máxima de fontes devolvidas ao frontend. */
    public const LIMITE_FONTES = 20;
}
