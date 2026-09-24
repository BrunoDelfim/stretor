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
}
