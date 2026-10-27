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
     * Marcas que falam do **áudio** PT-BR e valem mesmo quando o nome também
     * anuncia legenda.
     *
     * São palavras inteiras ou radicais que, no nome de um release, só fazem
     * sentido como marca de dublagem. "Dublado e Legendado" continua dublado: a
     * legenda ali é um extra, não o idioma do áudio.
     *
     * @var array<int, string>
     */
    public const TAGS_AUDIO_EXPLICITO = [
        'dublado',
        'dublada',
        'dublagem',
        'nacional',
        'áudio pt',
        'audio pt',
        'brasileiro',
        'brasileira',
        'brasil',
        'brazil',
        'brazilian',
    ];

    /**
     * Marca de **idioma** ("português", "portuguese") — prova o áudio só quando o
     * nome não anuncia legenda.
     *
     * O radical cobre "português", "portugues" e "portuguese" de uma vez. A
     * ressalva da legenda existe porque "PORTUGUESE.SUBtitles" e "Português
     * (Legenda)" dizem que a **legenda** é em português — o áudio é o original.
     * Sem ela, o release `Desperate.Housewives.S06E13.PORTUGUESE.SUBtitles`
     * entrava na lista como dublado e o player entregava áudio original com a
     * etiqueta errada.
     *
     * @var array<int, string>
     */
    public const TAGS_AUDIO_IDIOMA = [
        'portugu',
    ];

    /**
     * Todas as provas fortes de áudio PT-BR, na ordem em que são consultadas.
     *
     * A lista reunida serve à leitura de **conteúdo** e ao que precisa responder
     * "existe indício de áudio PT-BR neste texto?". A classificação de título usa
     * as duas metades separadas, porque a marca de legenda anula só a metade
     * ambígua (ver [`temProvaFortePtBr()`]).
     *
     * @var array<int, string>
     */
    public const TAGS_AUDIO_FORTE = [
        ...self::TAGS_AUDIO_EXPLICITO,
        ...self::TAGS_AUDIO_IDIOMA,
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

    /**
     * Marcas de **legenda** reconhecidas no nome do release.
     *
     * A portuguesa é a que a comunidade brasileira usa; as inglesas aparecem nos
     * releases internacionais e nos packs de anime. Todas negam o áudio PT-BR
     * quando não há marca explícita de dublagem ao lado.
     *
     * @var array<int, string>
     */
    public const TAGS_LEGENDA = [
        'legendado',
        'legendada',
        'legenda',
        'legendas',
        'subtitulado',
        'subtitulada',
        'subtitle',
        'subtitles',
    ];

    /**
     * Emoji da bandeira do Brasil (U+1F1E7 U+1F1F7), às vezes usado no lugar das tags.
     */
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
     * ("dublado", "nacional", "brasileiro", a bandeira), usa o código colado de
     * PT-BR ([`TAGS_CODIGO`]) ou nomeia o idioma ("português", "portuguese").
     * De fora ficam o indício genérico de multi-faixa ("dual", "multi áudio") e o
     * "pt" solto — nenhum dos dois prova que existe uma faixa em português (ver a
     * nota da classe).
     *
     * A ordem importa e tem três faixas:
     *
     * 1. **A marca explícita de áudio** vence sempre, inclusive contra a legenda:
     *    "Dublado e Legendado" é dublado com legenda extra.
     * 2. **A legenda anula o resto**: em "PORTUGUESE.SUBtitles" e em "Legendado pt
     *    BR", o português é o da legenda — o áudio é o original.
     * 3. Sem legenda, o idioma nomeado ("portugu") e o código colado ("pt-br")
     *    provam o áudio.
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

        foreach (self::TAGS_AUDIO_EXPLICITO as $tag) {
            if (str_contains($normalizado, $tag)) {
                return true;
            }
        }

        if (self::marcaLegendado($texto)) {
            return false;
        }

        foreach (self::TAGS_AUDIO_IDIOMA as $tag) {
            if (str_contains($normalizado, $tag)) {
                return true;
            }
        }

        foreach (self::TAGS_CODIGO as $tag) {
            if (str_contains($normalizado, $tag)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Diz se o título anuncia **legenda** — a marca portuguesa ou a inglesa.
     *
     * Fecha a classificação: um release sem marca explícita de áudio PT-BR que
     * anuncia legenda é `legendado` (áudio original com legenda), e não original
     * puro. O código de PT por perto ("Legendado pt BR") descreve a legenda — e é
     * justamente por isso que ele não conta como prova de dublagem.
     *
     * As formas inglesas entraram depois de um caso concreto: o único torrent de
     * "Donas de Casa Desesperadas" S06E13 era
     * `Desperate.Housewives.S06E13.[ON/OFF].PORTUGUESE.SUBtitles.iCAPx3`. O radical
     * "portugu" provava o áudio e a fonte saía como dublada — áudio original
     * entregue com a etiqueta errada, e ainda por cima contando como PT-BR no
     * corte de idioma, o que encerrava a busca cedo. "SUBS"/"SUB" só contam como
     * palavra: "subwoofer" e "subsídio" não anunciam legenda.
     */
    public static function marcaLegendado(string $texto): bool
    {
        if ($texto === '') {
            return false;
        }

        $normalizado = mb_strtolower($texto);

        foreach (self::TAGS_LEGENDA as $tag) {
            if (str_contains($normalizado, $tag)) {
                return true;
            }
        }

        return (bool) preg_match('/(?<![a-z0-9])subs?(?![a-z0-9])/', $normalizado);
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
