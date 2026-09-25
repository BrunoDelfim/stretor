<?php

namespace App\Support;

/**
 * Textos de fallback e limites da busca de fontes de torrent.
 *
 * Assim como os textos do catálogo, os rótulos da busca de fontes ficam
 * concentrados aqui para não saírem de sincronia entre os serviços, o controller
 * e o contrato devolvido ao frontend.
 */
final class MensagensTorrent
{
    public const SEM_FONTES = 'Nenhuma fonte encontrada para este título no momento.';

    public const FALHA_PROVEDOR = 'Não foi possível consultar as fontes agora. Tente novamente em instantes.';

    public const QUALIDADE_NAO_INFORMADA = 'Qualidade não informada';

    /**
     * Aviso usado quando todos os provedores foram pulados por falta de
     * credencial. É diferente de "não achei nada": aqui o sistema não chegou a
     * perguntar, então a mensagem precisa apontar a configuração, não o título.
     */
    public const AVISO_SEM_PROVEDOR = 'Nenhum provedor de fontes pôde ser consultado. Confira as chaves de API e se os serviços subiram corretamente.';

    /**
     * Aviso do catálogo fora do ar. Sem o TMDB não existe título/ano/imdb_id, e
     * sem eles a busca por nome não tem por onde começar — a mensagem explica
     * isso em vez de devolver um erro genérico de gateway.
     */
    public const AVISO_SEM_CATALOGO = 'Não foi possível obter os dados do filme no catálogo. Verifique a chave TMDB_API_KEY.';

    /** Quantidade máxima de fontes devolvidas ao frontend. */
    public const LIMITE_FONTES = 20;
}
