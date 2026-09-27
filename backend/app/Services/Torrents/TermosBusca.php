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

        // Sem numeração declarada não há o que reprovar: a fonte fica.
        if ($numeracao === null) {
            return true;
        }

        return $numeracao['temporada'] === $temporada && $numeracao['episodio'] === $episodio;
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
