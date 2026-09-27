<?php

namespace App\Services\Torrents;

use App\Contracts\ProvedorTorrents;
use Illuminate\Support\Facades\Http;

/**
 * Provedor Knaben — meta-buscador de indexadores públicos.
 *
 * O Knaben agrega o acervo de dezenas de trackers (ThePirateBay, LimeTorrents,
 * entre outros) atrás de uma única API JSON, sem chave e sem HTML. É a aposta da
 * Fase 2 para as séries antigas: como cobre trackers que os nossos provedores
 * nativos não varrem, ele devolve justamente os packs nacionais que a busca
 * atual não enxerga — nos testes, o termo "American Horror Story S01 completa"
 * trouxe o pack "American Horror Story S01 Completa Legendado PT-BR", e o termo
 * "Temporada 1" trouxe o "1ª Temporada [2011 DUAL AUDIO] 720p".
 *
 * A armadilha do contrato é o `search_type`. Com `"score"` — que é o que a
 * maioria dos exemplos por aí usa — a API **ignora a query** e devolve os
 * torrents mais semeados do acervo inteiro: perguntar por "American Horror
 * Story" devolvia Adobe Photoshop. Só com `"100%"` a API trata a `query` como
 * busca de verdade (exigindo que todos os termos casem). Foi essa a diferença
 * entre zero e o pack certo, então o valor fica fixo em `"100%"` e comentado —
 * trocá-lo de volta por `"score"` ressuscita o bug silencioso.
 *
 * O `order_by` aceita `seeders`, `date`, `size` e `peers`; `relevance` **não**
 * existe e a API responde 400. Ordenamos por seeds para os packs vivos subirem.
 */
class ProvedorKnaben implements ProvedorTorrents
{
    use NormalizaFonte;

    public function identificador(): string
    {
        return 'knaben';
    }

    public function rotulo(): string
    {
        return 'Knaben';
    }

    /**
     * Provedor público, mas atrás de uma chave liga/desliga.
     *
     * Diferente dos outros nativos, o Knaben bate num único host externo que
     * agrega muitos trackers; se ele cair ou passar a limitar requisições, os
     * quatro termos por episódio viram quatro erros. A configuração permite
     * desligá-lo sem reverter código.
     */
    public function disponivel(): bool
    {
        return (bool) config('services.torrents.knaben_habilitado', true)
            && trim((string) config('services.torrents.knaben_url', '')) !== '';
    }

    public function buscar(
        string $titulo,
        ?int $ano = null,
        ?string $imdbId = null,
        ?int $temporada = null,
        ?int $episodio = null,
    ): array {
        if (! $this->disponivel() || trim($titulo) === '') {
            return [];
        }

        $base = rtrim((string) config('services.torrents.knaben_url', ''), '/');
        $timeout = (int) config('services.torrents.tempo_limite', 15);
        $limite = (int) config('services.torrents.knaben_limite', 20);

        $corpo = [
            // "100%" é o único modo que aplica a query (ver docblock da classe).
            'search_type' => '100%',
            'search_field' => 'title',
            'query' => $titulo,
            'order_by' => 'seeders',
            'order_direction' => 'desc',
            'size' => $limite,
            'hide_unsafe' => false,
            'hide_xxx' => true,
        ];

        try {
            $resposta = Http::asJson()
                ->timeout($timeout)
                ->withUserAgent((string) config('services.torrents.user_agent', 'Mozilla/5.0'))
                ->post($base, $corpo);
        } catch (\Throwable) {
            // Provedor externo fora do ar não pode derrubar a cascata.
            return [];
        }

        if ($resposta->failed()) {
            return [];
        }

        $hits = $resposta->json('hits');

        if (! is_array($hits)) {
            return [];
        }

        $fontes = [];

        foreach ($hits as $hit) {
            if (! is_array($hit)) {
                continue;
            }

            $hash = strtolower(trim((string) ($hit['hash'] ?? '')));

            // Só infohash SHA-1 de verdade: o resto é índice quebrado.
            if (! preg_match('/^[a-f0-9]{40}$/', $hash)) {
                continue;
            }

            $nome = $this->limparTexto((string) ($hit['title'] ?? ''));

            if ($nome === '') {
                continue;
            }

            /*
             * Mesma peneira dos outros provedores por nome: um pack de "S01"
             * pode vir junto de um release "S10E01" da mesma série. Sem o corte,
             * um dublado de outra temporada subiria ao topo e o player abriria o
             * episódio errado. Releases sem numeração (os packs) passam.
             */
            if ($temporada !== null && $episodio !== null
                && ! TermosBusca::correspondeAoEpisodio($nome, $temporada, $episodio)) {
                continue;
            }

            /*
             * O piso de seeds (`SEEDS_NAO_MEDIDOS`) vale aqui apesar de o Knaben
             * informar a contagem: os números dele vêm de indexadores em cache e
             * os packs nacionais antigos aparecem com 0 mesmo vivos — foi o caso
             * do próprio "S01 Completa Legendado PT-BR". O corte de `ordenar()`
             * descarta quem tem 0 seeds, então tratar esse 0 como definitivo
             * repetiria, no Knaben, o descarte que a Fase 2 veio resolver. Quem
             * confirma se a fonte vive é o media-service, que mede os peers na
             * prática antes de abrir a reprodução.
             */
            $seeds = max(self::SEEDS_NAO_MEDIDOS, (int) ($hit['seeders'] ?? 0));

            // A última ocorrência vence — o array por hash já deduplica o que o
            // Knaben devolve duas vezes (mesmo torrent em dois indexadores).
            $fontes[$hash] = $this->montarFonte([
                'id' => $hash,
                'titulo' => $nome,
                'magnet' => $this->magnetDoHash($hash, $nome),
                'tamanho_bytes' => (int) ($hit['bytes'] ?? 0),
                'seeds' => $seeds,
                'peers' => (int) ($hit['peers'] ?? 0),
            ], $this->identificador(), $this->rotulo());
        }

        return array_values($fontes);
    }
}
