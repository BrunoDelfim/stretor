<?php

namespace App\Enums;

use App\Support\IndiciosPtBr;

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
     * (ex.: "Dublado", "Dual Áudio", "Nacional", "PT-BR", "🇧🇷"). A lista de
     * indícios mora em [`IndiciosPtBr`], compartilhada com a leitura do conteúdo
     * dos packs — assim o que vale como "nacional" é o mesmo em todos os pontos.
     *
     * A ordem das checagens é o que separa um release dublado de um apenas
     * legendado:
     *
     * 1. **Dual áudio** — é a tag mais específica: diz que o arquivo carrega as
     *    duas faixas. Vem antes de tudo.
     * 2. **Áudio explícito** — "dublado", "nacional", "português", "brasileiro"
     *    e a bandeira. Se qualquer um aparecer, o áudio é PT-BR e ponto.
     * 3. **Legendado** — antes da faixa ambígua de propósito. Um release
     *    "Legendado pt BR" carrega o "pt BR" da **legenda**, não do áudio: se o
     *    código viesse primeiro, ele viraria "Dublado" e a fonte seria oferecida
     *    errada (era o caso do filme 550).
     * 4. **Código ambíguo** — "PT-BR", "PT", "PTBR". Sem uma tag de legendado por
     *    perto, o código prova que o áudio é PT-BR.
     */
    public static function deduzirDoTitulo(string $titulo): self
    {
        if (IndiciosPtBr::eDual($titulo)) {
            return self::DUAL_AUDIO;
        }

        if (IndiciosPtBr::temAudioExplicito($titulo)) {
            return self::DUBLADO;
        }

        $texto = mb_strtolower($titulo);

        if (str_contains($texto, 'legendado') || str_contains($texto, 'legenda')) {
            return self::LEGENDADO;
        }

        if (IndiciosPtBr::contem($titulo)) {
            return self::DUBLADO;
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

        if (in_array($texto, $codigosPt, true) || IndiciosPtBr::contem($idioma)) {
            return self::DUBLADO;
        }

        return null;
    }
}
