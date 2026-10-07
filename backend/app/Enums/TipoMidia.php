<?php

namespace App\Enums;

/**
 * Tipo de mídia, no vocabulário do sistema.
 *
 * O catálogo fala `movie` e `tv`; aqui falamos `filme` e `serie`. O enum existe
 * para traduzir de um lado para o outro e para que o banco guarde uma palavra só
 * — espalhar as strings mágicas do TMDB pelo código é pedir para um dia gravar
 * `TV` numa linha e `tv` na outra, e a chave única deixar de casar.
 */
enum TipoMidia: string
{
    case FILME = 'filme';
    case SERIE = 'serie';

    /** Rótulo exibido na interface. */
    public function rotulo(): string
    {
        return match ($this) {
            self::FILME => 'Filme',
            self::SERIE => 'Série',
        };
    }

    /**
     * Traduz o `media_type` do TMDB para o vocabulário daqui.
     *
     * Devolve `null` para o que não é filme nem série (pessoas, por exemplo), o
     * que deixa o chamador descartar o item sem inventar um tipo.
     */
    public static function doTmdb(?string $mediaType): ?self
    {
        return match ($mediaType) {
            'movie' => self::FILME,
            'tv' => self::SERIE,
            default => null,
        };
    }
}
