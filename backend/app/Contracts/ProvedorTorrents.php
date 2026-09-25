<?php

namespace App\Contracts;

/**
 * Contrato de um provedor de fontes de torrent.
 *
 * O Stretor consulta vários provedores diferentes: raspagem de HTML em trackers
 * públicos PT-BR, APIs JSON públicas (APIBay, Torrentio, BT4G) e indexadores
 * Torznab. Cada um devolve os dados num formato próprio, mas todos precisam
 * entregar a mesma coisa ao resto do sistema: uma lista de fontes já no contrato
 * do frontend (título, qualidade, idioma, tamanho, seeds, magnet e a origem).
 *
 * Concentrar isso numa interface permite duas coisas que o sistema exige:
 *
 * 1. **Cascata de fallback** — o `CatalogoProvedores` percorre os provedores na
 *    ordem de prioridade e só avança quando o anterior não devolve nenhuma fonte
 *    dublada válida. Trocar a ordem é trocar a lista, não o código.
 * 2. **Tolerância a falha** — cada provedor é chamado isolado; um site fora do
 *    ar, um HTML que mudou ou uma chave ausente derrubam apenas aquele provedor.
 */
interface ProvedorTorrents
{
    /**
     * Identificador estável do provedor, usado no cache, no log e no campo
     * `provedor` de cada fonte devolvida ao frontend.
     */
    public function identificador(): string;

    /** Rótulo do provedor exibido na interface (ex.: "APIBay"). */
    public function rotulo(): string;

    /**
     * Diz se o provedor tem o que precisa para ser consultado (no caso dos
     * provedores públicos, sempre `true`; para os que exigem chave, `false`
     * quando ela não está configurada).
     *
     * O catálogo usa isto para **pular** o provedor sem erro: a ausência de uma
     * credencial não pode interromper a busca, apenas empurrar o fluxo para o
     * próximo da cascata.
     */
    public function disponivel(): bool;

    /**
     * Busca fontes para o filme informado.
     *
     * Os provedores que trabalham por identificador (Torrentio, BT4G) usam o
     * `imdbId` quando ele existe; os que trabalham por nome usam o título e o
     * ano. Devolver lista vazia é uma resposta legítima — significa "não achei
     * nada", e o catálogo segue para o próximo da cascata.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buscar(string $titulo, ?int $ano = null, ?string $imdbId = null): array;
}
