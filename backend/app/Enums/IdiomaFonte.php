<?php

namespace App\Enums;

/**
 * Idioma de uma fonte de torrent.
 *
 * As APIs de torrents descrevem o idioma de formas muito diferentes (tags no
 * título, campos próprios, siglas). O enum concentra os valores que o sistema
 * entende e define a prioridade de exibição: como dificilmente um filme tem
 * fonte dublada logo na primeira tentativa, o frontend percorre a lista inteira
 * e o dublado precisa vir primeiro.
 */
enum IdiomaFonte: string
{
    case DUBLADO = 'pt-BR';
    case DUAL_AUDIO = 'dual';
    case LEGENDADO = 'legendado';
    case ORIGINAL = 'original';

    /** Rótulo exibido na interface. */
    public function rotulo(): string
    {
        return match ($this) {
            self::DUBLADO => 'Dublado',
            self::DUAL_AUDIO => 'Dual Áudio',
            self::LEGENDADO => 'Legendado',
            self::ORIGINAL => 'Idioma original',
        };
    }

    /**
     * Peso de prioridade: quanto menor, mais cedo a fonte aparece na lista.
     *
     * O dublado vem primeiro porque é o que o usuário brasileiro procura; o
     * dual áudio atende quem quer escolher a faixa; o legendado e o original
     * ficam como alternativa.
     */
    public function prioridade(): int
    {
        return match ($this) {
            self::DUBLADO => 0,
            self::DUAL_AUDIO => 1,
            self::LEGENDADO => 2,
            self::ORIGINAL => 3,
        };
    }

    /**
     * Deduz o idioma a partir do título da fonte.
     *
     * As APIs de torrents não têm um campo confiável de idioma, então a
     * classificação sai das tags que a comunidade usa nos nomes dos arquivos
     * (ex.: "Dublado", "Dual Áudio", "Legendado", "Nacional", "PT-BR").
     *
     * Os indexadores Torznab agregam trackers PT-BR, cujos títulos trazem essas
     * tags com frequência — é o que permite priorizar o dublado de verdade.
     */
    public static function deduzirDoTitulo(string $titulo): self
    {
        $texto = mb_strtolower($titulo);

        // A ordem importa: "dual áudio" contém "áudio", e "dublado" pode
        // aparecer junto de "legendado" em lançamentos com as duas faixas.
        if (str_contains($texto, 'dual')) {
            return self::DUAL_AUDIO;
        }

        /*
         * Tags de dublagem PT-BR. Além de "dublado", os trackers nacionais usam
         * "nacional" (produção brasileira), "pt-br"/"ptbr" e "br" isolado em
         * alguns casos. Verificamos " pt-br" com espaço para não casar com
         * "pt-br" dentro de outra palavra.
         */
        $tagsDublado = [
            'dublado',
            'dublada',
            'nacional',
            ' pt-br',
            ' ptbr',
            'pt-br',
            'ptbr',
            'áudio pt',
            'audio pt',
        ];

        foreach ($tagsDublado as $tag) {
            if (str_contains($texto, $tag)) {
                return self::DUBLADO;
            }
        }

        if (str_contains($texto, 'legendado') || str_contains($texto, 'legenda')) {
            return self::LEGENDADO;
        }

        return self::ORIGINAL;
    }
}
