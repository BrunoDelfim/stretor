<?php

namespace App\Services\Torrents;

use App\Contracts\ProvedorTorrents;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Provedor de addons Stremio hospedados — agregador por identificador.
 *
 * O Torrentio provou o valor do protocolo do Stremio: addons públicos respondem
 * por `imdb_id` (e por temporada/episódio no caso de série) com uma lista de
 * streams já pronta, e vários deles varrem trackers que o backend não consulta
 * sozinho. Em vez de escrever um provedor para cada addon — o Torrentio, o TPB+,
 * e o que vier depois —, este provedor genérico consulta **todos os endereços
 * configurados** em `TORRENTS_STREMIO_ADDONS` e trata a resposta pelo mesmo
 * contrato Stremio, reaproveitando a leitura da trait [`LeituraStreamStremio`].
 *
 * O Torrentio tem o seu próprio provedor porque carrega a configuração de idioma
 * embutida na URL e é sempre consultado primeiro; aqui ficam os demais. Como a
 * mesclagem do catálogo deduplica por infohash, é inofensivo listar aqui um addon
 * que já responda pelo Torrentio — a release repetida perde para a primeira.
 *
 * Cada entrada da lista pode trazer o **caminho de configuração** do addon junto
 * do host (ex.: `https://torrentio.strem.fun/language=portuguese`): o provedor
 * só concatena `/stream/...` ao final, então o segmento de configuração chega
 * intacto ao addon. É assim que se pede a um addon configurável um recorte
 * específico sem tocar no código.
 */
class ProvedorAddonStremio implements ProvedorTorrents
{
    use NormalizaFonte;
    use LeituraStreamStremio;

    public function identificador(): string
    {
        return 'addon_stremio';
    }

    public function rotulo(): string
    {
        return 'Addon Stremio';
    }

    /** Disponibilidade é ter ao menos um addon configurado. */
    public function disponivel(): bool
    {
        return $this->enderecos() !== [];
    }

    public function buscar(
        string $titulo,
        ?int $ano = null,
        ?string $imdbId = null,
        ?int $temporada = null,
        ?int $episodio = null,
    ): array {
        // Como o Torrentio, só responde por identificador: sem um `tt...` não há
        // como consultar, e adivinhar pelo nome devolveria o addon errado.
        if (empty($imdbId) || ! str_starts_with($imdbId, 'tt')) {
            return [];
        }

        $enderecos = $this->enderecos();

        if ($enderecos === []) {
            return [];
        }

        $timeout = (int) config('services.torrents.tempo_limite', 15);

        // Série pede a numeração no caminho; filme pede só o identificador.
        $caminho = ($temporada !== null && $episodio !== null)
            ? "stream/series/{$imdbId}:{$temporada}:{$episodio}.json"
            : "stream/movie/{$imdbId}.json";

        /*
         * Os addons são independentes entre si, então consultá-los em paralelo
         * evita somar os tempos de rede — um addon lento não atrasa a resposta
         * dos outros. A falha de um é absorvida no lugar: só seguimos com as
         * respostas HTTP de fato.
         */
        $respostas = Http::pool(fn ($pool) => array_map(
            fn (string $base) => $pool->as(md5($base))
                ->acceptJson()
                ->timeout($timeout)
                ->get($base.'/'.$caminho),
            $enderecos
        ));

        $fontes = [];

        foreach ($enderecos as $base) {
            $resposta = $respostas[md5($base)] ?? null;

            if (! $resposta instanceof Response || $resposta->failed()) {
                continue;
            }

            $streams = $resposta->json('streams');

            if (! is_array($streams)) {
                continue;
            }

            foreach ($streams as $stream) {
                $fonte = $this->normalizarStream((array) $stream, $titulo);

                if ($fonte === null) {
                    continue;
                }

                /*
                 * O addon responde pela série inteira e mistura temporadas: é o
                 * mesmo motivo do corte no provedor do Torrentio. Releases sem
                 * numeração (os packs) passam, para não apagar o socorro das
                 * séries antigas.
                 */
                if ($temporada !== null && $episodio !== null
                    && ! TermosBusca::correspondeAoEpisodio($fonte['titulo'], $temporada, $episodio)) {
                    continue;
                }

                // O array por hash deduplica: o mesmo torrent em dois addons
                // entra uma única vez, e a primeira ocorrência vence.
                $fontes[$fonte['id']] = $fonte;
            }
        }

        return array_values($fontes);
    }

    /**
     * Lista de addons configurados, sem repetição e sem entradas vazias.
     *
     * @return array<int, string>
     */
    private function enderecos(): array
    {
        $urls = config('services.torrents.stremio_addons', []);

        if (! is_array($urls)) {
            return [];
        }

        $limpos = [];

        foreach ($urls as $url) {
            $url = rtrim(trim((string) $url), '/');

            if ($url === '' || ! str_starts_with($url, 'http')) {
                continue;
            }

            $limpos[$url] = $url;
        }

        return array_values($limpos);
    }
}
