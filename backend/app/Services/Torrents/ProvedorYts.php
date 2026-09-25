<?php

namespace App\Services\Torrents;

use App\Contracts\ProvedorTorrents;
use App\Support\MensagensTorrent;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Provedor YTS — último degrau da cascata.
 *
 * O YTS é a rede de segurança: catálogo grande, API estável e áudio quase todo
 * em inglês. Ele só entra quando a busca nativa **e** o indexador não devolveram
 * nenhuma fonte dublada, o que normalmente significa que o filme não tem release
 * nacional com peers.
 *
 * O caminho é o mesmo de antes, agora atrás do contrato de provedor: a busca usa
 * o `imdb_id` quando existe (o título sozinho traz remakes — "Fight Club" devolve
 * também o filme de 2023) e filtra pelo ano quando só há o nome.
 */
class ProvedorYts implements ProvedorTorrents
{
    use NormalizaFonte;

    public function identificador(): string
    {
        return 'yts';
    }

    public function rotulo(): string
    {
        return 'YTS';
    }

    /** Sem a base configurada não há provedor. */
    public function disponivel(): bool
    {
        return $this->baseUrl() !== '';
    }

    public function buscar(string $titulo, ?int $ano = null, ?string $imdbId = null): array
    {
        $resposta = $this->consultar($titulo, $ano, $imdbId);

        $filmes = $resposta['data']['movies'] ?? [];

        if (empty($filmes)) {
            return [];
        }

        // Quando a busca foi por título, o provedor devolve remakes de outros
        // anos; mantemos só os que batem com o ano do filme.
        if (! $imdbId && $ano) {
            $filmes = array_values(array_filter(
                $filmes,
                fn (array $filme) => (int) ($filme['year'] ?? 0) === $ano
            ));
        }

        $fontes = [];

        foreach ($filmes as $filme) {
            $tituloFilme = (string) ($filme['title_long'] ?? $filme['title'] ?? '');

            foreach ($filme['torrents'] ?? [] as $torrent) {
                $hash = strtolower(trim((string) ($torrent['hash'] ?? '')));

                if ($hash === '') {
                    continue;
                }

                $fontes[] = $this->montarFonte([
                    'id' => $hash,
                    'titulo' => $this->limparTexto($tituloFilme),
                    'magnet' => $this->magnetDoHash($hash, $tituloFilme),
                    'tamanho_bytes' => $torrent['size_bytes'] ?? null,
                    'seeds' => $torrent['seeds'] ?? 0,
                    'peers' => $torrent['peers'] ?? 0,
                ], $this->identificador(), $this->rotulo());
            }
        }

        return $fontes;
    }

    /**
     * Consulta a API v2 do YTS.
     *
     * Nota histórica: a versão anterior tratava o campo `url` do torrent (que é
     * o link de download do `.torrent`) como se fosse anunciador e o injetava no
     * magnet. Isso poluía a lista de trackers com um endereço HTTP que não
     * anuncia nada. Agora o magnet sai só do infohash mais os anunciadores
     * públicos, que é o que o WebTorrent precisa.
     *
     * @return array<string, mixed>
     */
    private function consultar(string $titulo, ?int $ano, ?string $imdbId): array
    {
        $base = $this->baseUrl();

        if ($base === '') {
            return [];
        }

        $parametros = [
            'query_term' => $imdbId ?: $titulo,
            'limit' => MensagensTorrent::LIMITE_FONTES,
            'sort_by' => 'seeds',
            'order_by' => 'desc',
        ];

        try {
            $resposta = Http::baseUrl($base)
                ->acceptJson()
                ->timeout((int) config('services.torrents.tempo_limite', 15))
                ->get('/api/v2/list_movies.json', $parametros);
        } catch (\Throwable $excecao) {
            throw new RuntimeException(MensagensTorrent::FALHA_PROVEDOR, previous: $excecao);
        }

        if ($resposta->failed()) {
            throw new RuntimeException(
                MensagensTorrent::FALHA_PROVEDOR.' (HTTP '.$resposta->status().')'
            );
        }

        return (array) ($resposta->json() ?? []);
    }

    /**
     * O domínio principal (yts.mx) é bloqueado por DNS em vários provedores de
     * hospedagem; o espelho yts.gg responde com o mesmo contrato da API v2 e é o
     * padrão.
     */
    private function baseUrl(): string
    {
        return rtrim((string) config('services.torrents.base_url', ''), '/');
    }
}
