<?php

namespace App\Support;

/**
 * Decide por qual canal a busca começa, a partir da idade da série.
 *
 * O problema que isto resolve é de orçamento, não de preferência. Uma série
 * recente tem release fresco nos indexadores de torrent: o Torrentio e o APIBay
 * respondem em segundos, com seeders de sobra. Uma série antiga é o oposto — os
 * indexadores devolvem pouco ou nada, e cada provedor consultado gasta uma volta
 * do relógio global antes de o fallback de stream direto sequer começar. O
 * resultado era o timeout: a cascata de torrents consumia o orçamento inteiro
 * procurando algo que não estava lá, e o scraper web — que é justamente quem
 * acha o conteúdo raro — chegava sem tempo.
 *
 * A regra é simples e deliberadamente grosseira: série dentro do limiar de anos
 * segue o fluxo normal (torrents primeiro); série fora dele começa pelo stream
 * direto. O limiar é configurável porque "antiga" é uma medida de mercado, não
 * uma verdade: dois anos é o padrão, mas quem opera o sistema pode ajustar.
 *
 * O critério é o **ano de lançamento da série**, não o da temporada. Uma série
 * de 2004 continua sendo "de 2004" na décima temporada — e é isso que se quer:
 * o catálogo de torrents envelhece junto com a série, não com a temporada.
 */
final class RoteadorBusca
{
    /**
     * Canal de torrents: a cascata de provedores por nome e por identificador.
     */
    public const CANAL_TORRENTS = 'torrents';

    /**
     * Canal de stream direto: o scraper web que extrai o vídeo da página.
     */
    public const CANAL_STREAM_DIRETO = 'stream_direto';

    /**
     * Diz se a estratégia por idade está ligada.
     *
     * Desligada, o roteador devolve sempre o canal de torrents e o fluxo volta a
     * ser exatamente o de antes — a chave existe para poder desligar a estratégia
     * sem mexer no código, caso ela se mostre ruim para algum catálogo.
     */
    public static function habilitado(): bool
    {
        return (bool) config('services.torrents.busca_por_idade_habilitada', true);
    }

    /**
     * Limiar, em anos, que separa série recente de série antiga.
     *
     * Zero ou negativo desliga o corte por idade na prática: nenhuma série fica
     * "fora" do limiar, e o canal preferido volta a ser sempre torrents.
     */
    public static function limiarAnos(): int
    {
        return (int) config('services.torrents.busca_idade_limite_anos', 2);
    }

    /**
     * Canal por onde a busca deve começar.
     *
     * Sem ano conhecido não há como medir idade, e a aposta segura é o fluxo
     * normal: o catálogo pode não ter informado o ano, mas os provedores por
     * identificador (Torrentio, addons Stremio) respondem pelo `imdb_id` e não
     * dependem disso. Tratar a ausência como "antiga" mandaria para o scraper uma
     * série recente que os torrents resolveriam em um segundo.
     */
    public static function canalPreferido(?int $ano): string
    {
        if (! self::habilitado() || ! self::eAntiga($ano)) {
            return self::CANAL_TORRENTS;
        }

        return self::CANAL_STREAM_DIRETO;
    }

    /**
     * Diz se a série está fora do limiar de idade.
     *
     * A conta é feita contra o ano corrente: uma série de 2024, em 2026, tem dois
     * anos e ainda é recente; uma de 2023 tem três e já é antiga. O limiar é
     * inclusivo de propósito — "até 2 anos" é o que o operador espera ao
     * configurar 2.
     */
    public static function eAntiga(?int $ano): bool
    {
        if ($ano === null || $ano <= 0) {
            return false;
        }

        $limiar = self::limiarAnos();

        if ($limiar <= 0) {
            return false;
        }

        return (self::anoCorrente() - $ano) > $limiar;
    }

    /**
     * O canal oposto, para o fallback cruzado.
     *
     * Quando o canal preferido não devolve nada, o outro ainda pode ter a
     * resposta: a série antiga que o scraper não achou pode ter um pack nos
     * indexadores, e a série recente que os torrents não cobriram pode estar num
     * agregador de vídeo. O cruzamento é o último recurso, nunca o primeiro.
     */
    public static function canalOposto(string $canal): string
    {
        return $canal === self::CANAL_STREAM_DIRETO
            ? self::CANAL_TORRENTS
            : self::CANAL_STREAM_DIRETO;
    }

    /**
     * Ano corrente, isolado num método para os testes poderem fixá-lo.
     *
     * A idade é sempre relativa a "agora", e um teste que dependesse do relógio
     * real quebraria na virada do ano. Aqui o relógio é o do sistema, mas o ponto
     * de leitura é único — quem precisar de determinismo troca só este método.
     */
    private static function anoCorrente(): int
    {
        return (int) date('Y');
    }
}
