<?php

namespace App\Enums;

/**
 * Tamanhos de imagem suportados pela CDN do TMDB.
 *
 * Centralizar os sufixos evita strings soltas espalhadas pelo serviço e deixa
 * explícito qual resolução cada campo do contrato usa.
 */
enum TamanhoImagem: string
{
    case POSTER = 'w500';
    case BACKDROP = 'w1280';
    case ORIGINAL = 'original';
}
