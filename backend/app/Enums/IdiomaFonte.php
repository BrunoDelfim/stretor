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
         * "nacional" (produção brasileira), "dublagem", "pt-br"/"ptbr"/"pt br" e
         * variações de "áudio português". As formas com espaço no início
         * (" pt-br", " pt br") evitam casar com o sufixo "pt-br" colado em outra
         * palavra.
         */
        $tagsDublado = [
            'dublado',
            'dublada',
            'dublagem',
            'nacional',
            ' pt-br',
            ' ptbr',
            ' pt br',
            ' pt_br',
            'pt-br',
            'ptbr',
            'br-pt',
            'áudio pt',
            'audio pt',
            'português',
            'portugues',
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

    /**
     * Deduz o idioma a partir do campo de idioma do indexador.
     *
     * O Torznab expõe um atributo `language` que alguns indexadores preenchem
     * com o idioma real do release (ex.: "pt-BR", "Portuguese", "Brazilian").
     * Quando ele vem preenchido é informação melhor do que a tag no título, e
     * por isso tem precedência sobre `deduzirDoTitulo()`.
     *
     * Devolve `null` quando o campo está vazio ou não é reconhecido — aí o
     * chamador cai para a dedução pelo título, em vez de tratar a ausência como
     * "idioma original".
     *
     * A comparação evita um `str_contains('pt')` solto, que casaria com
     * qualquer palavra contendo essas letras (ex.: "script"). Os códigos são
     * conferidos inteiros e o radical "portugu" cobre as variações escritas.
     */
    public static function deduzirDoIdioma(string $idioma): ?self
    {
        $texto = mb_strtolower(trim($idioma));

        if ($texto === '') {
            return null;
        }

        $codigosPt = [
            'pt',
            'pt-br',
            'pt_br',
            'pt br',
            'ptbr',
            'por',
            'portuguese',
            'portuguese (brazil)',
            'português',
            'portugues',
            'brazilian',
            'brazil',
            'br',
        ];

        if (in_array($texto, $codigosPt, true) || str_contains($texto, 'portugu')) {
            return self::DUBLADO;
        }

        return null;
    }
}
