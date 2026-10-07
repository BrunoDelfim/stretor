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
     * classificação sai das tags que a comunidade usa nos nomes dos arquivos. A
     * lista canônica mora em [`IndiciosPtBr`], compartilhada com a leitura do
     * conteúdo dos packs — assim o que vale como "nacional" é o mesmo em todos os
     * pontos.
     *
     * O critério é **conservador**: só a marca que fala do áudio (ou da origem
     * brasileira) promove a fonte a dublado/dual — "Dublado", "Nacional",
     * "Português", "Brasileiro", a bandeira 🇧🇷 e o código colado de PT-BR.
     *
     * Dois indícios ficam de fora, e a razão é a mesma: eles não provam áudio.
     * Um marcador genérico de multi-faixa ("dual", "multi áudio") diz que o
     * arquivo carrega mais de uma faixa, não que uma delas é português — num pack
     * de anime quase sempre é japonês + inglês. Tratá-lo como prova rotulava de
     * "Dublado" releases que o ffprobe depois reprovava, e o usuário ficava sem
     * nada: as falsas dublagens esgotavam a lista e o original com legenda nem
     * chegava a ser oferecido. Pelo mesmo motivo, o "pt" solto não conta — em
     * "Legendado pt BR" ele descreve a legenda. Sem marca, a fonte é `original`:
     * o corte de idioma a rebaixa à reserva, e o fallback a serve com legenda (ou
     * a descarta, quando não há legenda alguma).
     *
     * A ordem das checagens:
     *
     * 1. **Marca de áudio PT-BR** — prova o áudio. Se o nome também traz "dual",
     *    a fonte é `dual`; senão, `dublado`.
     * 2. **Legendado** — fecha a classificação: sem marca de áudio, um release que
     *    anuncia legenda é `legendado`, não original puro.
     * 3. **Nada disso** — é `original`.
     */
    public static function deduzirDoTitulo(string $titulo): self
    {
        if (IndiciosPtBr::temProvaFortePtBr($titulo)) {
            return IndiciosPtBr::eDual($titulo) ? self::DUAL_AUDIO : self::DUBLADO;
        }

        if (IndiciosPtBr::marcaLegendado($titulo)) {
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

        if (in_array($texto, $codigosPt, true) || IndiciosPtBr::contem($idioma)) {
            return self::DUBLADO;
        }

        return null;
    }
}
