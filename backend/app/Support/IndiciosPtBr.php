<?php

namespace App\Support;

/**
 * Prova única de que um release carrega áudio em PT-BR.
 *
 * O sistema decide o que é "dublado" em três lugares diferentes — no título da
 * fonte ([`IdiomaFonte`]), no nome do pack e no conteúdo do pack (lido pelo
 * media-service). Se cada um tivesse a própria lista de tags, bastaria um deles
 * ficar para trás para o pack deixar de ser reconhecido como nacional. Aqui fica
 * a lista canônica; a versão em JavaScript no media-service
 * (`src/utils/idiomas.js`) é o espelho dela e só existe porque o Node não lê
 * PHP.
 *
 * A lista foi montada com o que os trackers PT-BR usam na prática. O critério do
 * usuário é generoso de propósito: **um** indício já prova o áudio — não precisa
 * ter todos. "Dublado", "dual áudio", "nacional", "PT-BR", "🇧🇷" e "Brasileiro"
 * apontam todos para a mesma coisa.
 *
 * Ficou de fora o "br" solto: em "BRRip" (BluRay ripado) ele apareceria em
 * release americano e marcaria como dublado o que não é. O "pt" também não pode
 * ser procurado como texto puro — casaria com "script" —, então usa borda de
 * palavra.
 */
final class IndiciosPtBr
{
    /**
     * Indícios que provam áudio PT-BR por si só.
     *
     * São palavras inteiras ou radicais que, no nome de um release, só fazem
     * sentido como marca de dublagem. O radical "portugu" cobre "português",
     * "portugues" e "portuguese" de uma vez.
     *
     * @var array<int, string>
     */
    public const TAGS_AUDIO = [
        'dublado',
        'dublada',
        'dublagem',
        'dual',
        'nacional',
        'portugu',
        'áudio pt',
        'audio pt',
        'brasileiro',
        'brasileira',
        'brasil',
        'brazil',
        'brazilian',
    ];

    /**
     * Códigos de idioma que provam PT-BR, mas podem ser a **legenda**.
     *
     * Um release "Legendado pt BR" carrega o código da legenda, não do áudio.
     * Por isso estes indícios só valem depois de descartado o "legendado" — ver
     * [`IdiomaFonte::deduzirDoTitulo()`].
     *
     * @var array<int, string>
     */
    public const TAGS_CODIGO = [
        'ptbr',
        'br-pt',
    ];

    /** Emoji da bandeira do Brasil (U+1F1E7 U+1F1F7), às vezes usado no lugar das tags. */
    public const EMOJI_BRASIL = "\u{1F1E7}\u{1F1F7}";

    /**
     * Diz se o texto é um release em **dual áudio**.
     *
     * O dual áudio é a informação mais específica da lista — declara as duas
     * faixas no mesmo arquivo —, então tem a prioridade mais alta na
     * classificação.
     */
    public static function eDual(string $texto): bool
    {
        return $texto !== '' && str_contains(mb_strtolower($texto), 'dual');
    }

    /**
     * Diz se o texto declara áudio PT-BR de forma inequívoca.
     *
     * Aqui entram só os indícios que falam de **áudio** ou de origem brasileira.
     * Um "PT-BR" sozinho não vale nesta checagem: pode ser legenda.
     */
    public static function temAudioExplicito(string $texto): bool
    {
        if ($texto === '') {
            return false;
        }

        if (str_contains($texto, self::EMOJI_BRASIL)) {
            return true;
        }

        $normalizado = mb_strtolower($texto);

        foreach (self::TAGS_AUDIO as $tag) {
            if (str_contains($normalizado, $tag)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Diz se o texto traz qualquer indício de PT-BR, inclusive os ambíguos.
     *
     * É a checagem usada sobre o conteúdo de um pack, onde não há uma tag de
     * "legendado" para desempatar: basta o nome de uma pasta ou de um arquivo
     * interno citar "Dublado", "PT-BR" ou a bandeira para considerarmos o pacote
     * nacional.
     */
    public static function contem(string $texto): bool
    {
        if ($texto === '') {
            return false;
        }

        if (self::temAudioExplicito($texto)) {
            return true;
        }

        $normalizado = mb_strtolower($texto);

        foreach (self::TAGS_CODIGO as $tag) {
            if (str_contains($normalizado, $tag)) {
                return true;
            }
        }

        return self::temCodigoPt($normalizado);
    }

    /**
     * Confere o "pt" como palavra, não como pedaço de outra.
     *
     * `str_contains('pt')` casaria com "script", "concept" e afins. A borda de
     * palavra (`\b` do PCRE não serve aqui por causa dos acentos) exige que o
     * "pt" não esteja colado a outra letra ou dígito dos dois lados — o que
     * libera "pt", "pt-br", "pt br", "pt_br" e "br-pt", mas barra "script".
     */
    public static function temCodigoPt(string $textoNormalizado): bool
    {
        return (bool) preg_match('/(?<![a-z0-9])pt(?![a-z0-9])/', $textoNormalizado);
    }
}
