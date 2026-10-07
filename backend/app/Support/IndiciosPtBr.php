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
 * O critério é **conservador** de propósito: só a marca que fala do áudio (ou da
 * origem brasileira) prova a dublagem. "Dublado", "nacional", "português",
 * "brasileiro", a bandeira 🇧🇷 e o código colado de PT-BR apontam todos para a
 * mesma coisa.
 *
 * Ficaram de fora duas pistas que enganavam a classificação de título:
 *
 * - **O indício genérico de multi-faixa** ("dual", "multi áudio"). Ele diz que o
 *   arquivo carrega mais de uma faixa, **não** que uma delas é português — num
 *   pack de anime quase sempre é japonês + inglês. Tratado como prova, ele
 *   rotulava de "Dublado" releases que o ffprobe depois reprovava, e o usuário
 *   ficava sem nada: as falsas dublagens esgotavam a lista e o original com
 *   legenda nem chegava a ser oferecido.
 * - **O código `pt` solto.** Em "Legendado pt BR" ele descreve a legenda, não o
 *   áudio; num nome sem outra pista, é ambíguo demais para provar dublagem. A
 *   forma colada (`pt-br`, `ptbr`, `br-pt`) continua valendo — ela não se
 *   confunde com a legenda.
 *
 * Um release sem marca descritiva não é promovido: cai para o idioma original e a
 * fonte passa a ser servida com legenda (ou sai da lista, quando não há legenda).
 * Isso também dispensa a detecção de idioma estrangeiro que existia aqui: um nome
 * que declarava alemão já não tinha marca de PT-BR e virava original de qualquer
 * jeito.
 *
 * Ficou de fora o "br" solto: em "BRRip" (BluRay ripado) ele apareceria em
 * release americano e marcaria como dublado o que não é. O "pt" também não pode
 * ser procurado como texto puro — casaria com "script" —, então usa borda de
 * palavra (só na leitura de **conteúdo**, onde a ambiguidade é tolerável).
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
    public const TAGS_AUDIO_FORTE = [
        'dublado',
        'dublada',
        'dublagem',
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
     * Indícios genéricos de multi-faixa — pista fraca, nunca prova.
     *
     * Dizem que o arquivo carrega mais de uma faixa de áudio, não que uma delas é
     * português. Servem à leitura de **conteúdo** ([`contem()`], sobre o nome de
     * uma pasta ou de um arquivo interno), onde a ausência de outra pista
     * justifica um palpite; a classificação de título não os aceita sozinhos (ver
     * a nota da classe).
     *
     * @var array<int, string>
     */
    public const TAGS_MULTIFA = [
        'dual',
        'duplo áudio',
        'duplo audio',
        'multi áudio',
        'multi audio',
        'multi-audio',
    ];

    /**
     * Lista dos indícios de **áudio** PT-BR: as provas fortes mais os genéricos
     * de multi-faixa.
     *
     * É a base da leitura de **conteúdo** ([`contem()`]). A classificação de
     * título não usa esta lista: ela exige a marca descritiva
     * ([`temProvaFortePtBr()`]), e o genérico de multi-faixa de nada prova ali.
     *
     * @var array<int, string>
     */
    public const TAGS_AUDIO = [
        ...self::TAGS_AUDIO_FORTE,
        ...self::TAGS_MULTIFA,
    ];

    /**
     * Códigos **colados** de idioma PT-BR.
     *
     * Diferente do "pt" solto, estas formas não se confundem com a legenda nem com
     * outro idioma, então provam o áudio por si (ver [`temProvaFortePtBr()`]). São
     * a marca mais fraca que ainda conta; a forma separada ("pt" sozinho) fica de
     * fora da classificação de título e só vale na leitura de **conteúdo**.
     *
     * @var array<int, string>
     */
    public const TAGS_CODIGO = [
        'pt-br',
        'pt_br',
        'pt br',
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
     * Diz se o texto traz uma **marca** de áudio PT-BR.
     *
     * É a única prova aceita pela classificação de título
     * ([`IdiomaFonte::deduzirDoTitulo()`]): fala do áudio ou da origem brasileira
     * ("dublado", "nacional", "português", "brasileiro", a bandeira) ou usa o
     * código colado de PT-BR ([`TAGS_CODIGO`]). De fora ficam o indício genérico
     * de multi-faixa ("dual", "multi áudio") e o "pt" solto — nenhum dos dois
     * prova que existe uma faixa em português (ver a nota da classe).
     *
     * O código, porém, só conta quando o nome **não** anuncia legenda: em
     * "Legendado pt BR" o "pt BR" descreve a **legenda**, não o áudio. A prova
     * descritiva ("Dublado e Legendado") vem antes e vence sem essa ressalva.
     */
    public static function temProvaFortePtBr(string $texto): bool
    {
        if ($texto === '') {
            return false;
        }

        if (str_contains($texto, self::EMOJI_BRASIL)) {
            return true;
        }

        $normalizado = mb_strtolower($texto);

        foreach (self::TAGS_AUDIO_FORTE as $tag) {
            if (str_contains($normalizado, $tag)) {
                return true;
            }
        }

        if (self::marcaLegendado($texto)) {
            return false;
        }

        foreach (self::TAGS_CODIGO as $tag) {
            if (str_contains($normalizado, $tag)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Diz se o título anuncia **legenda** — a marca "legendado"/"legenda".
     *
     * Fecha a classificação: um release sem marca de áudio PT-BR que anuncia
     * legenda é `legendado` (áudio original com legenda), e não original puro. O
     * código de PT por perto ("Legendado pt BR") descreve a legenda — e é
     * justamente por isso que ele não conta como prova de dublagem.
     */
    public static function marcaLegendado(string $texto): bool
    {
        if ($texto === '') {
            return false;
        }

        $normalizado = mb_strtolower($texto);

        return str_contains($normalizado, 'legendado') || str_contains($normalizado, 'legenda');
    }

    /**
     * Confere o "pt" como palavra, não como pedaço de outra.
     *
     * `str_contains('pt')` casaria com "script", "concept" e afins. A borda de
     * palavra (`\b` do PCRE não serve aqui por causa dos acentos) exige que o
     * "pt" não esteja colado a outra letra ou dígito dos dois lados — o que
     * libera "pt", "pt-br", "pt br", "pt_br" e "br-pt", mas barra "script".
     *
     * Só a leitura de **conteúdo** ([`contem()`]) recorre a ele: no título, o
     * "pt" solto é ambíguo demais para contar como prova (ver a nota da classe).
     */
    public static function temCodigoPt(string $textoNormalizado): bool
    {
        return (bool) preg_match('/(?<![a-z0-9])pt(?![a-z0-9])/', $textoNormalizado);
    }
}
