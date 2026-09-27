<?php

namespace App\Services\Torrents;

use App\Contracts\ProvedorTorrents;
use App\Services\TorznabService;
use RuntimeException;

/**
 * Provedor Torznab (Prowlarr/Jackett) — primeiro degrau do fallback.
 *
 * A busca nativa no backend é o caminho principal, mas ela depende de HTML, e
 * HTML quebra: os trackers brasileiros trocam de domínio, entram em Cloudflare
 * ou mudam o tema. Quando a busca nativa não devolve nenhuma fonte dublada, o
 * indexador entra — ele agrega **os mesmos** trackers, porém com um raspador
 * mantido por terceiros, o que cobre justamente os casos em que o nosso parser
 * ficou para trás.
 *
 * A ordem importa: o Prowlarr não substitui a busca nativa, ele a socorre. Se o
 * indexador viesse primeiro, o sistema voltaria a depender de uma configuração
 * manual (cadastrar indexadores no painel) para chegar ao dublado.
 */
class ProvedorTorznab implements ProvedorTorrents
{
    use NormalizaFonte;

    public function __construct(
        private readonly TorznabService $torznab,
    ) {
    }

    public function identificador(): string
    {
        return 'torznab';
    }

    public function rotulo(): string
    {
        return 'Indexador (Torznab)';
    }

    /**
     * Sem URL e chave o indexador não tem como ser consultado.
     *
     * O catálogo usa isto para **pular** o provedor sem erro, exatamente como
     * pede a regra de credencial ausente: a falta da chave empurra o fluxo para o
     * próximo degrau (YTS) em vez de interromper a busca.
     */
    public function disponivel(): bool
    {
        return $this->torznab->configurado();
    }

    public function buscar(
        string $titulo,
        ?int $ano = null,
        ?string $imdbId = null,
        ?int $temporada = null,
        ?int $episodio = null,
    ): array {
        /*
         * Duas consultas, como antes: a busca pelo título puro mistura dezenas de
         * lançamentos em inglês, e o nome com a tag "dublado" é o que faz o
         * indexador devolver o release nacional. Cada consulta falha isolada.
         *
         * O indexador busca por nome, então o termo já chega com a numeração do
         * episódio quando for o caso. O ano sai do termo em episódio: o release
         * traz o ano de exibição dele, não o da série.
         *
         * Em episódio o TorrentService já entrega as variações dubladas prontas
         * ("... S01E01 dublado"). Nesse caso não pedimos `buscarDublado()`, que
         * reanexaria a tag e geraria "dublado dublado" — termo que não casa com
         * release nenhum e ainda gasta uma consulta extra por episódio.
         */
        $episodioDeSerie = $temporada !== null && $episodio !== null;

        $anoDoTermo = $episodioDeSerie ? null : $ano;

        /*
         * A categoria muda com o tipo de mídia e isso não é detalhe: o Prowlarr
         * filtra por categoria, então pedir um episódio com `cat=2000` (filmes)
         * devolve zero resultados mesmo com o release indexado. Séries usam
         * `5000` (TV).
         */
        $categoria = $episodioDeSerie
            ? (string) config('services.torrents.torznab_categoria_serie', '5000')
            : null;

        $consultas = [];

        if (! TermosBusca::jaEDublado($titulo)) {
            $consultas[] = fn () => $this->torznab->buscarDublado($titulo, $anoDoTermo, $categoria);
        }

        $consultas[] = fn () => $this->torznab->buscar($titulo, $anoDoTermo, $categoria);

        $itens = array_merge(...array_map(
            fn (callable $consulta) => $this->consultar($consulta),
            $consultas,
        ));

        $fontes = [];
        $vistos = [];

        foreach ($itens as $item) {
            $tituloItem = trim((string) ($item['titulo'] ?? ''));
            $magnet = trim((string) ($item['magnet'] ?? ''));

            // Sem magnet não há como reproduzir: o infohash sozinho não basta
            // porque o indexador pode não informar anunciadores.
            if ($tituloItem === '' || $magnet === '') {
                continue;
            }

            $id = (string) ($item['infohash'] ?? '') ?: $this->idDoMagnet($magnet);

            if ($id === '' || isset($vistos[$id])) {
                continue;
            }

            $vistos[$id] = true;

            $fontes[] = $this->montarFonte([
                'id' => $id,
                'titulo' => $tituloItem,
                'magnet' => $magnet,
                'tamanho_bytes' => $item['tamanho_bytes'] ?? null,
                'seeds' => $item['seeds'] ?? 0,
                'peers' => $item['peers'] ?? 0,
                'idioma' => (string) ($item['idioma'] ?? ''),
            ], $this->identificador(), $this->rotulo());
        }

        return $fontes;
    }

    /**
     * Executa uma consulta absorvendo a falha.
     *
     * O indexador pode estar fora do ar ou recusar só um termo; nesse caso
     * seguimos com o que a outra consulta trouxe. Um erro aqui nunca pode
     * interromper a cascata.
     *
     * @param  callable(): array<int, array<string, mixed>>  $consulta
     * @return array<int, array<string, mixed>>
     */
    private function consultar(callable $consulta): array
    {
        try {
            return $consulta();
        } catch (RuntimeException $excecao) {
            report($excecao);

            return [];
        }
    }
}
