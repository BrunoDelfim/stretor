<?php

namespace App\Enums;

/**
 * Gêneros de filme do catálogo TMDB.
 *
 * O TMDB devolve os gêneros como ids numéricos e, mesmo com `language=pt-BR`,
 * pode responder nomes em inglês. Manter o mapa aqui garante que a interface
 * sempre exiba o rótulo em português, independente do que a API retornar.
 */
enum Genero: int
{
    case ACAO = 28;
    case AVENTURA = 12;
    case ANIMACAO = 16;
    case COMEDIA = 35;
    case CRIME = 80;
    case DOCUMENTARIO = 99;
    case DRAMA = 18;
    case FAMILIA = 10751;
    case FANTASIA = 14;
    case HISTORIA = 36;
    case TERROR = 27;
    case MUSICA = 10402;
    case MISTERIO = 9648;
    case ROMANCE = 10749;
    case FICCAO_CIENTIFICA = 878;
    case CINEMA_TV = 10770;
    case SUSPENSE = 53;
    case GUERRA = 10752;
    case FAROESTE = 37;

    /** Rótulo em português exibido na interface. */
    public function rotulo(): string
    {
        return match ($this) {
            self::ACAO => 'Ação',
            self::AVENTURA => 'Aventura',
            self::ANIMACAO => 'Animação',
            self::COMEDIA => 'Comédia',
            self::CRIME => 'Crime',
            self::DOCUMENTARIO => 'Documentário',
            self::DRAMA => 'Drama',
            self::FAMILIA => 'Família',
            self::FANTASIA => 'Fantasia',
            self::HISTORIA => 'História',
            self::TERROR => 'Terror',
            self::MUSICA => 'Música',
            self::MISTERIO => 'Mistério',
            self::ROMANCE => 'Romance',
            self::FICCAO_CIENTIFICA => 'Ficção Científica',
            self::CINEMA_TV => 'Cinema TV',
            self::SUSPENSE => 'Suspense',
            self::GUERRA => 'Guerra',
            self::FAROESTE => 'Faroeste',
        };
    }

    /**
     * Traduz um id do TMDB para o rótulo em português.
     *
     * Devolve null quando o id não pertence ao catálogo conhecido — o chamador
     * decide o fallback (normalmente o nome cru devolvido pela API).
     */
    public static function rotuloPorId(?int $id): ?string
    {
        return $id === null ? null : self::tryFrom($id)?->rotulo();
    }
}
