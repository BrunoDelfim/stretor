<?php

namespace App\Services\Torrents;

/**
 * Monta os termos de busca enviados aos provedores.
 *
 * Os trackers brasileiros não têm campo de idioma: a dublagem é declarada no
 * próprio nome do arquivo ("Dublado", "Dual Áudio", "PT-BR"). Por isso a busca
 * não pode ser só o título — quem procura o filme dublado precisa perguntar pelo
 * título **junto** com a tag de idioma, senão o provedor devolve dezenas de
 * lançamentos em inglês e o dublado fica fora da primeira página.
 *
 * Centralizar as tags aqui evita que cada provedor invente a sua variação e
 * garante que uma tag nova (ex.: uma gíria nova dos trackers) entre em todos os
 * provedores de uma vez.
 */
final class TermosBusca
{
    /**
     * Tags usadas pelos trackers nacionais para marcar áudio em PT-BR.
     *
     * A ordem importa: as mais comuns vêm primeiro, e os provedores que limitam
     * o número de consultas usam só as primeiras.
     *
     * @var array<int, string>
     */
    public const TAGS_PT_BR = [
        'dublado',
        'dublada',
        'dual áudio',
        'dual audio',
        'pt-br',
        'ptbr',
        'nacional',
        'áudio pt',
        'audio pt',
    ];

    /** Termo mais simples: título e ano, sem tag de idioma. */
    public static function base(string $titulo, ?int $ano): string
    {
        $titulo = trim($titulo);

        return $ano ? "{$titulo} {$ano}" : $titulo;
    }

    /**
     * Termo de busca de um episódio específico, no padrão SxxExx.
     *
     * Séries não são publicadas como um arquivo único: cada episódio é um release
     * separado, identificado pela numeração "S01E02". O ano não entra aqui porque
     * a numeração já é única dentro da série — e o ano da série atrapalharia a
     * busca, já que o release do episódio costuma trazer o ano de exibição dele.
     */
    public static function episodio(string $titulo, int $temporada, int $episodio): string
    {
        $titulo = trim($titulo);

        return sprintf('%s S%02dE%02d', $titulo, $temporada, $episodio);
    }

    /**
     * Variações de busca de um episódio, incluindo a tag de dublado.
     *
     * Mesma lógica de `paraDublado()`, mas aplicada ao termo do episódio: os
     * trackers nacionais marcam o áudio no nome do release, então o termo precisa
     * carregar a tag junto da numeração para o dublado aparecer primeiro.
     *
     * @return array<int, string>
     */
    public static function episodioDublado(string $titulo, int $temporada, int $episodio): array
    {
        $base = self::episodio($titulo, $temporada, $episodio);

        return [
            "{$base} dublado",
            "{$base} dublada",
            "{$base} dual áudio",
        ];
    }

    /**
     * Termos de pack de temporada: a temporada inteira num release só.
     *
     * É o socorro das séries antigas. Um episódio isolado de 2011 raramente
     * sobrevive com seeds — ninguém mais está baixando aquele arquivo —, mas o
     * pack da temporada continua na malha, porque é o que a comunidade ainda
     * procura. O termo muda de "S01E02" para "S01 completa"/"Temporada 1
     * completa", que é como os trackers publicam o pacote.
     *
     * @return array<int, string>
     */
    public static function packTemporada(string $titulo, int $temporada): array
    {
        $titulo = trim($titulo);
        $numerada = sprintf('S%02d', $temporada);

        return [
            "{$titulo} {$numerada} completa",
            "{$titulo} Temporada {$temporada} completa",
            "{$titulo} Season {$temporada} complete",
        ];
    }

    /**
     * Variações dubladas dos termos de pack.
     *
     * O tracker nacional marca o áudio no nome do pacote igual faz no episódio
     * ("Temporada 1 Completa Dublada"), então o termo precisa carregar a tag
     * junto para o release nacional aparecer na primeira página.
     *
     * @return array<int, string>
     */
    public static function packTemporadaDublado(string $titulo, int $temporada): array
    {
        $titulo = trim($titulo);
        $numerada = sprintf('S%02d', $temporada);

        return [
            "{$titulo} {$numerada} completa dublada",
            "{$titulo} Temporada {$temporada} completa dublada",
        ];
    }

    /**
     * Termos de série em PT-BR: a temporada pelo nome, sem numeração e sem "completa".
     *
     * É o terceiro socorro da série antiga, depois dos termos de episódio e dos de
     * pack. Os buscadores por nome que casam todas as palavras do termo (o Knaben
     * é o caso) devolvem zero quando o termo leva "S01E01" ou "completa": cada
     * palavra a mais corta o recall. Tirando os dois ruídos, o termo pergunta pela
     * série pelo nome — que é como os packs multi-temporada são publicados
     * ("1ª 2ª 3ª Temporadas Dublado e Legendado") — e deixa o gate de temporada do
     * [`CatalogoProvedores`] descartar o que não cobrir a temporada pedida.
     *
     * @return array<int, string>
     */
    public static function serieDublado(string $titulo, int $temporada): array
    {
        $titulo = trim($titulo);
        $numerada = sprintf('S%02d', $temporada);

        return [
            "{$titulo} dublado",
            "{$titulo} dual áudio",
            "{$titulo} temporada {$temporada}",
            "{$titulo} {$numerada}",
        ];
    }

    /**
     * Diz se o termo é um dos termos de série (sem numeração de episódio).
     *
     * Espelha [`eTermoDePack()`]: separa os termos de série dos de episódio e dos
     * de pack para o [`CatalogoProvedores`] saber onde aplicar o gate de temporada.
     * Um termo de série não tem numeração de episódio, não é termo de pack e
     * carrega uma tag de idioma ou uma temporada explícita.
     */
    public static function eTermoDeSerie(string $termo): bool
    {
        if (self::numeracaoDoTitulo($termo) !== null) {
            return false;
        }

        if (self::eTermoDePack($termo)) {
            return false;
        }

        if (self::jaEDublado($termo)) {
            return true;
        }

        return (bool) preg_match(
            '/(?<![a-z0-9])s\d{1,2}(?![a-z0-9])|(?:temporada|season)\s*\d{1,2}/u',
            mb_strtolower($termo)
        );
    }

    /**
     * Diz se o termo é um dos termos de pack de temporada.
     *
     * É este reconhecimento que sustenta a exceção de idioma do pack: só o pack
     * pode atravessar o corte de `apenas_pt_br` sem ser dublado. A checagem exige,
     * ao mesmo tempo, um marcador de pacote ("completa"/"complete") e uma
     * numeração de temporada, para que um filme de título "The Complete ..." não
     * seja confundido caso alguém o busque por aqui.
     */
    public static function eTermoDePack(string $termo): bool
    {
        $texto = mb_strtolower($termo);

        $temMarcador = false;

        foreach (['completa', 'completo', 'complete', 'superpack'] as $marcador) {
            if (str_contains($texto, $marcador)) {
                $temMarcador = true;
                break;
            }
        }

        if (! $temMarcador) {
            return false;
        }

        return (bool) preg_match(
            '/(?<![a-z0-9])s\d{1,2}(?![a-z0-9])|(?:temporada|season)\s*\d{1,2}/u',
            $texto
        );
    }

    /**
     * Termos de busca voltados ao dublado, do mais provável ao menos.
     *
     * Devolve uma lista curta de propósito: cada termo é uma requisição a mais, e
     * a experiência mostra que "dublado", "dublada" e "dual áudio" cobrem quase
     * tudo o que os trackers brasileiros publicam. As demais tags ficam no
     * fallback `variacoes()` para quem quiser varredura ampla.
     *
     * @return array<int, string>
     */
    public static function paraDublado(string $titulo, ?int $ano): array
    {
        $base = self::base($titulo, $ano);

        return [
            "{$base} dublado",
            "{$base} dublada",
            "{$base} dual áudio",
        ];
    }

    /**
     * Todas as variações de busca em PT-BR, incluindo o termo base.
     *
     * Usada pela varredura ampla do último recurso, quando os termos curtos não
     * acharam nada e vale a pena gastar mais requisições.
     *
     * @return array<int, string>
     */
    public static function variacoes(string $titulo, ?int $ano): array
    {
        $base = self::base($titulo, $ano);

        $termos = [$base];

        foreach (self::TAGS_PT_BR as $tag) {
            $termos[] = "{$base} {$tag}";
        }

        return array_values(array_unique($termos));
    }

    /**
     * Diz se o termo já carrega uma tag de dublagem.
     *
     * O `TorrentService` já entrega os termos de episódio com as variações
     * dubladas montadas ("... S01E01 dublado"). Os provedores por nome, que antes
     * reanexavam a tag por conta própria, precisam saber disso para não gerar
     * "dublado dublado" — termo que não casa com release nenhum e ainda gasta uma
     * requisição por provedor.
     */
    public static function jaEDublado(string $termo): bool
    {
        $texto = mb_strtolower($termo);

        foreach (self::TAGS_PT_BR as $tag) {
            if (str_contains($texto, $tag)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Confere se o título de um release corresponde à temporada/episódio pedidos.
     *
     * Os provedores por identificador (Torrentio) e os agregadores por nome não
     * são infalíveis: o Torrentio, em especial, responde pela série inteira e
     * mistura temporadas na mesma lista. Foi assim que "American Horror Story"
     * pedido como S01E01 voltou com um release "S10E01" em dual áudio — que, por
     * ser dublado, subiu para o topo da ordenação e foi a primeira fonte tentada
     * pelo player, mesmo sendo de outra temporada.
     *
     * A regra é conservadora: só descartamos quando o título **declara** uma
     * numeração diferente da pedida. Releases sem numeração nenhuma (comuns em
     * packs e em alguns nomes nacionais) passam, porque não há como provar que
     * estão errados — e descartá-los apagaria fontes legítimas.
     *
     * Aceita as grafias que os trackers usam: "S01E01", "s1e1", "1x01" e
     * "Temporada 1 Episódio 1" (com ou sem acento).
     */
    public static function correspondeAoEpisodio(string $titulo, int $temporada, int $episodio): bool
    {
        $numeracao = self::numeracaoDoTitulo($titulo);

        if ($numeracao !== null) {
            return $numeracao['temporada'] === $temporada && $numeracao['episodio'] === $episodio;
        }

        /*
         * Sem numeração de episódio, o título pode ser um pack de temporada
         * ("S01 completa", "Temporada 1 completa"). Aí a temporada ainda dá para
         * conferir — e precisa ser conferida: o pack tem muitos seeds e, se for
         * da temporada errada, subiria ao topo da lista e o player abriria o
         * episódio de outra temporada.
         *
         * A checagem é conservadora de propósito: só reprova quando o título
         * declara a temporada de forma explícita E ela difere da pedida. Títulos
         * sem número nenhum seguem passando, para não apagar nomes nacionais
         * legítimos.
         *
         * A leitura usa [`temporadaNoRelease()`], e não [`temporadaDoTitulo()`],
         * porque o pack pode cobrir uma faixa ("1ª 2ª 3ª Temporadas", "Seasons 1
         * to 8"): a leitura simples devolveria só o primeiro número e reprovaria
         * um pack multi-temporada que inclui a pedida. `temporadaNoRelease()`
         * aceita a faixa e só reprova quando a pedida está fora dela.
         */
        if (self::temporadaNoRelease($titulo, $temporada)) {
            return true;
        }

        /*
         * A faixa não cobriu a pedida. Antes de reprovar, confirmamos que o
         * título **declara** alguma temporada: sem número nenhum, ele passa — é o
         * caso dos nomes nacionais legítimos, que não têm como provar a temporada
         * e seriam apagados por um corte cego.
         */
        return self::temporadaDoTitulo($titulo) === null;
    }

    /**
     * Extrai a temporada declarada num título, se houver.
     *
     * Só é chamada quando o título **não** declara numeração de episódio. A leitura
     * não exige mais um marcador de pack: exigir "completa"/"temporada" deixava
     * passar nomes como "American Horror Story.2ª.Temporada.Dual.Áudio.720p." — que
     * declara a temporada pelo ordinal, mas cujo marcador vinha depois de um ponto
     * e a regex antiga não enxergava. O pack da temporada errada entrava na busca
     * da 1ª e, por ter mais seeds, era o primeiro tentado pelo player.
     *
     * A leitura é conservadora por construção: cada padrão exige uma âncora forte
     * (o "S" de release, a palavra "temporada"/"season" ou o ordinal "ª/º"), de
     * modo que o ano do release ("2011") nunca é lido como temporada. Sem âncora,
     * devolvemos `null` e a fonte passa — nomes nacionais sem número continuam
     * legítimos.
     *
     * Aceita "S01", "Temporada 1", "Season 1", "1ª Temporada" e "2ª.Temporada".
     */
    public static function temporadaDoTitulo(string $titulo): ?int
    {
        $texto = mb_strtolower($titulo);

        /*
         * A ordem das checagens é a ordem da especificidade. O ordinal vem antes
         * do "Temporada N" porque "2ª Temporada" casaria os dois padrões e o
         * ordinal é o que carrega o número de fato; ler "temporada" primeiro
         * devolveria o número errado quando o ordinal viesse depois da palavra.
         */
        // "2ª Temporada", "2ª.Temporada", "2º temporada" — o separador cobre ponto,
        // hífen e underline, que os trackers usam no lugar do espaço.
        if (preg_match('/(?<![a-z0-9])(\d{1,2})\s*[ªº]\s*[\s._-]*temporada/u', $texto, $achados)) {
            return (int) $achados[1];
        }

        // "Temporada 2", "Season 2" — a palavra-chave seguida do número.
        if (preg_match('/(?:temporada|season)s?\s*[\s._-]*(\d{1,2})/u', $texto, $achados)) {
            return (int) $achados[1];
        }

        // "S02" — o formato dos releases; o lookbehind evita casar dentro de palavra.
        if (preg_match('/(?<![a-z0-9])s(\d{1,2})(?![a-z0-9])/i', $texto, $achados)) {
            return (int) $achados[1];
        }

        return null;
    }

    /**
     * Diz se um nome de release declara a temporada pedida — inclusive packs de
     * várias temporadas.
     *
     * Diferente de [`temporadaDoTitulo()`], não exige um marcador de pacote: o
     * nome de um torrent de temporada costuma ser só "Série S01 1080p", sem
     * "completa"/"season", e era justamente esse o pack que o corte de idioma
     * descartava. A leitura é feita sobre o nome do **torrent**, nunca sobre o
     * nome do arquivo — o arquivo aponta para um episódio e esconderia o pacote.
     * A ausência de numeração de episódio é condição imposta pelo chamador.
     *
     * Cobre "S01", "S1-S5", "Temporada 1", "Seasons 1 to 8" e "1ª Temporada".
     */
    public static function temporadaNoRelease(string $nome, int $temporada): bool
    {
        $texto = mb_strtolower($nome);
        $numeros = [];

        // "S01", "S1" — a fronteira evita casar dentro de palavra ("seasons").
        if (preg_match_all('/(?<![a-z0-9])s(\d{1,2})(?![a-z0-9])/i', $texto, $achados)) {
            $numeros = array_merge($numeros, $achados[1]);
        }

        /*
         * "Temporada 1", "Season 1", "Seasons 1" — o separador cobre ponto,
         * hífen e underline, que os trackers usam no lugar do espaço.
         *
         * O `(?![\d p])` é essencial: sem ele, "Temporadas 720p" casava o "72"
         * como número de temporada (o `s?` consumia o "s" de "Temporadas" e o
         * `\s*` pulava para a resolução). Isso injetava 72 na lista e `max()`
         * virava 72, fazendo qualquer temporada "caber" na faixa — foi o que
         * deixou o pack da 2ª passar por um gate que devia reprová-lo. O
         * lookahead rejeita tanto o dígito seguinte ("720" não vira "72") quanto
         * a resolução colada ("720p").
         */
        if (preg_match_all('/(?:temporada|season)s?\s*[\s._-]*(\d{1,2})(?![\d p])/u', $texto, $achados)) {
            $numeros = array_merge($numeros, $achados[1]);
        }

        // "1ª Temporada", "2ª.Temporada".
        if (preg_match_all('/(?<![a-z0-9])(\d{1,2})\s*[ªº]\s*[\s._-]*temporada/u', $texto, $achados)) {
            $numeros = array_merge($numeros, $achados[1]);
        }

        /*
         * "1ª 2ª 3ª Temporada(s)" — séries antigas publicam a lista de temporadas
         * com um ordinal atrás do outro. A leitura anterior ("3ª Temporada") só
         * enxergava o ordinal colado na palavra, então o pack multi-temporada
         * parecia ser só da 3ª e o gate estrito o descartava para a 1ª.
         */
        if (preg_match('/((?:\d{1,2}\s*[ªº]\s*)+)temporadas?/u', $texto, $achados)
            && preg_match_all('/\d{1,2}/u', $achados[1], $ordinais)) {
            $numeros = array_merge($numeros, $ordinais[0]);
        }

        /*
         * "Temporadas 1, 2 e 3" — a palavra-chave vem uma vez e os números em
         * lista. Sem ler a lista inteira, só o primeiro número entrava e o pack
         * parecia ser só da temporada 1.
         *
         * O `(?![\d p])` fecha a mesma brecha da leitura anterior: "Temporadas
         * 720p" casava o "72" da resolução como se fosse número de temporada.
         * Com o 72 na lista, `max()` virava 72 e qualquer temporada "cabia" na
         * faixa.
         */
        if (preg_match_all('/(?:temporada|season)s?\s*(\d{1,2}(?![\d p])(?:\s*(?:,|e|and|&|\/)\s*\d{1,2}(?![\d p]))*)/u', $texto, $achados)) {
            foreach ($achados[1] as $lista) {
                if (preg_match_all('/\d{1,2}/', $lista, $itens)) {
                    $numeros = array_merge($numeros, $itens[0]);
                }
            }
        }

        /*
         * "Seasons 1 to 8" e "Temporada 1 a 4": o segundo número não repete a
         * palavra-chave, então a faixa precisa ser lida explicitamente. Sem isso,
         * o pack de várias temporadas só casaria a temporada do primeiro número.
         *
         * A leitura da faixa vem **antes** do corte de lista vazia: em "Seasons
         * 1 to 8" a regex de lista não casa nada (o "1" é seguido de " to", não
         * de vírgula), e o early-return apagaria o pack antes de a faixa ser
         * lida. A faixa é, ela própria, uma declaração de temporada.
         */
        foreach ([
            '/(?:temporada|season)s?\s*(\d{1,2})\s*(?:a|to|até|-|–|—)\s*(\d{1,2})/u',
            '/(?<![a-z0-9])s(\d{1,2})\s*(?:a|to|até|-|–|—)\s*s?(\d{1,2})/i',
        ] as $faixa) {
            if (preg_match($faixa, $texto, $achados)) {
                $numeros[] = $achados[1];
                $numeros[] = $achados[2];
            }
        }

        if ($numeros === []) {
            return false;
        }

        $numeros = array_map('intval', $numeros);

        return $temporada >= min($numeros) && $temporada <= max($numeros);
    }

    /**
     * Extrai a numeração de temporada/episódio declarada no título, se houver.
     *
     * Devolve `null` quando o título não traz numeração reconhecível. A leitura
     * cobre os formatos que aparecem na prática nos nomes de release:
     *
     * - `S01E02`, `s1e2`, `S01.E02`, `S01 E02`
     * - `1x02`, `01x02`
     * - `Temporada 1 Episódio 2`, `Temporada 1 Episodio 2`, `1ª Temporada`
     *
     * @return array{temporada: int, episodio: int}|null
     */
    public static function numeracaoDoTitulo(string $titulo): ?array
    {
        $texto = mb_strtolower($titulo);

        // Formato padrão dos releases: S01E02 (com separadores opcionais).
        if (preg_match('/s(\d{1,2})[\s._-]*e(\d{1,3})/i', $texto, $achados)) {
            return [
                'temporada' => (int) $achados[1],
                'episodio' => (int) $achados[2],
            ];
        }

        // Formato alternativo "1x02", comum em catálogos antigos.
        if (preg_match('/(?<!\d)(\d{1,2})x(\d{1,3})(?!\d)/i', $texto, $achados)) {
            return [
                'temporada' => (int) $achados[1],
                'episodio' => (int) $achados[2],
            ];
        }

        // Formato por extenso, usado por trackers que traduzem o nome do arquivo.
        if (preg_match('/temporada\s*(\d{1,2}).{0,20}?epis[oó]dio\s*(\d{1,3})/iu', $texto, $achados)) {
            return [
                'temporada' => (int) $achados[1],
                'episodio' => (int) $achados[2],
            ];
        }

        return null;
    }

    /**
     * Lê a temporada declarada considerando **todos** os nomes de uma fonte.
     *
     * O `release` de uma fonte nem sempre é o nome do torrent: nos provedores por
     * identificador ele é a primeira linha do rótulo (o pacote), mas quando o
     * provedor só informa o arquivo interno o `release` acaba sendo o nome do
     * episódio ("2x13 - Madness Ends"). Nesse caso a numeração de episódio
     * esconde a temporada do pacote e o pack da 2ª temporada passava batido pelo
     * gate, subindo ao topo de uma busca da 1ª.
     *
     * A leitura aqui é a da temporada, e não a da numeração: um nome que declara
     * "2ª Temporada" prova a temporada mesmo que outro nome do conjunto traga
     * "2x13". Só quando nenhum nome declara temporada é que devolvemos `null` —
     * e aí quem decide é a numeração de episódio, como antes.
     *
     * @param  array<int, string|null>  $nomes
     */
    public static function temporadaDosNomes(array $nomes): ?int
    {
        foreach ($nomes as $nome) {
            $nome = trim((string) $nome);

            if ($nome === '') {
                continue;
            }

            $temporada = self::temporadaDoTitulo($nome);

            if ($temporada !== null) {
                return $temporada;
            }
        }

        return null;
    }

    /**
     * Diz se algum dos nomes declara a temporada pedida — inclusive faixas.
     *
     * Complementa [`temporadaDosNomes()`] para o caso de pack multi-temporada:
     * um nome pode declarar "1ª 2ª 3ª Temporadas" e outro apenas "2x13". A
     * pergunta "a pedida está coberta?" precisa olhar todos os nomes, não só o
     * primeiro que declara uma temporada.
     *
     * @param  array<int, string|null>  $nomes
     */
    public static function algumNomeCobreTemporada(array $nomes, int $temporada): bool
    {
        foreach ($nomes as $nome) {
            $nome = trim((string) $nome);

            if ($nome !== '' && self::temporadaNoRelease($nome, $temporada)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove o que atrapalha a busca por palavra-chave.
     *
     * Alguns sites não lidam bem com dois-pontos (títulos como "Homem-Aranha:
     * Sem Volta para Casa") e o ano no termo reduz o recall em buscadores que
     * indexam o título limpo. A pontuação vira espaço e o resto é normalizado.
     */
    public static function limpar(string $termo): string
    {
        $termo = str_replace([':', '-', ',', '.', '/', '|', '(', ')', '&'], ' ', $termo);

        return trim((string) preg_replace('/\s+/', ' ', $termo));
    }
}
