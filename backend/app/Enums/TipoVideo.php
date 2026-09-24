<?php

namespace App\Enums;

/**
 * Tipos e provedores de vídeo relevantes para o trailer do modal.
 *
 * O TMDB devolve vários vídeos por filme (teasers, clipes, bastidores). Só nos
 * interessa o trailer hospedado no YouTube, então os literais ficam aqui em vez
 * de soltos na lógica de seleção.
 */
enum TipoVideo: string
{
    case TRAILER = 'Trailer';
    case YOUTUBE = 'YouTube';

    /**
     * Idiomas considerados na escolha do trailer, em ordem de preferência.
     *
     * O TMDB marca cada vídeo com o idioma original da publicação. Queremos o
     * trailer em PT-BR quando existir; na falta dele, aceitamos inglês e, por
     * fim, qualquer idioma (o original do filme).
     */
    public const IDIOMA_PREFERIDO = 'pt';

    public const IDIOMA_FALLBACK = 'en';
}
