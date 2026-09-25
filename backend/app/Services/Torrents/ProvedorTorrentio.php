<?php

namespace App\Services\Torrents;

use App\Contracts\ProvedorTorrents;
use Illuminate\Support\Facades\Http;

/**
 * Provedor Torrentio — agregador por identificador do IMDb.
 *
 * O Torrentio (serviço público do ecossistema Stremio) agrega dezenas de
 * trackers e responde por `imdb_id`, o que o torna muito preciso: em vez de
 * procurar por nome e torcer para o ano filtrar o remake, pergunta direto pelo
 * filme e recebe as releases daquele identificador.
 *
 * Duas diferenças importantes em relação aos outros provedores:
 *
 * 1. **Só funciona por `imdb_id`.** Sem ele, o provedor devolve vazio em vez de
 *    tentar adivinhar pelo título.
 * 2. **Os metadados vêm em texto.** A contagem de seeds, o tamanho e (às vezes)
 *    o idioma não têm campo próprio: vêm embutidos no rótulo do stream, com
 *    emojis separando os valores. Por isso a leitura é por expressão regular.
 */
class ProvedorTorrentio implements ProvedorTorrents
{
    use NormalizaFonte;

    public function identificador(): string
    {
        return 'torrentio';
    }

    public function rotulo(): string
    {
        return 'Torrentio';
    }

    /** Provedor público: sempre disponível, sem credencial. */
    public function disponivel(): bool
    {
        return true;
    }

    public function buscar(string $titulo, ?int $ano = null, ?string $imdbId = null): array
    {
        // Sem o identificador do IMDb não há como consultar: o Torrentio não
        // aceita busca por nome.
        if (empty($imdbId) || ! str_starts_with($imdbId, 'tt')) {
            return [];
        }

        $base = rtrim((string) config('services.torrents.torrentio_url', 'https://torrentio.strem.fun'), '/');
        $timeout = (int) config('services.torrents.tempo_limite', 15);

        try {
            $resposta = Http::baseUrl($base)
                ->acceptJson()
                ->timeout($timeout)
                ->get("/stream/movie/{$imdbId}.json");
        } catch (\Throwable) {
            // Um provedor indisponível não pode derrubar a cascata; quem decide
            // se a busca falhou é o catálogo, com o conjunto de todos.
            return [];
        }

        if ($resposta->failed()) {
            return [];
        }

        $streams = $resposta->json('streams') ?? [];

        $fontes = [];

        foreach ($streams as $stream) {
            $fonte = $this->normalizarStream((array) $stream, $titulo);

            if ($fonte !== null) {
                $fontes[] = $fonte;
            }
        }

        return $fontes;
    }

    /**
     * Converte um stream do Torrentio no contrato do sistema.
     *
     * @param  array<string, mixed>  $stream
     * @return array<string, mixed>|null
     */
    private function normalizarStream(array $stream, string $titulo): ?array
    {
        $hash = strtolower(trim((string) ($stream['infoHash'] ?? '')));

        if ($hash === '') {
            return null;
        }

        $rotulo = (string) ($stream['title'] ?? '');
        $nome = (string) ($stream['behaviorHints']['filename'] ?? '')
            ?: (string) ($stream['name'] ?? '')
            ?: $titulo;

        $nome = $this->limparTexto($nome);

        $magnet = $this->magnetComFontes($hash, $nome, (array) ($stream['sources'] ?? []));

        return $this->montarFonte([
            'id' => $hash,
            'titulo' => $nome,
            'magnet' => $magnet,
            'tamanho_bytes' => $this->tamanhoDoRotulo($rotulo),
            'seeds' => $this->seedsDoRotulo($rotulo),
            'peers' => 0,
            'idioma' => $this->idiomaDoRotulo($nome.' '.$rotulo),
        ], $this->identificador(), $this->rotulo());
    }

    /**
     * Monta o magnet preservando os anunciadores que o Torrentio já informou.
     *
     * Os `sources` vêm no formato `tracker:udp://...` ou `dht:...`. Repassamos os
     * que são anunciadores de verdade e deixamos o DHT de fora (ele é implícito
     * no protocolo e não é endereço de tracker).
     *
     * @param  array<int, string>  $fontes
     */
    private function magnetComFontes(string $hash, string $nome, array $fontes): string
    {
        $magnet = 'magnet:?xt=urn:btih:'.$hash.'&dn='.rawurlencode($nome);

        foreach ($fontes as $fonte) {
            if (is_string($fonte) && str_starts_with($fonte, 'tracker:')) {
                $magnet .= '&tr='.rawurlencode(substr($fonte, strlen('tracker:')));
            }
        }

        return $this->completarMagnet($magnet);
    }

    /**
     * Lê a contagem de seeds do rótulo (ex.: "👤 123 💾 1.4 GB").
     *
     * Quando o Torrentio não informa a contagem, devolvemos 1 e não 0: o valor 0
     * faz o catálogo descartar a fonte como morta, e o Torrentio costuma omitir
     * o dado justamente quando não conseguiu medir — não quando não há peers. O
     * media-service valida os peers na prática antes de abrir a reprodução.
     */
    private function seedsDoRotulo(string $rotulo): int
    {
        if (preg_match('/👤\s*(\d+)/u', $rotulo, $achados)) {
            return (int) $achados[1];
        }

        if (preg_match('/(\d+)\s*(?:seeds|seeders)/i', $rotulo, $achados)) {
            return (int) $achados[1];
        }

        return 1;
    }

    /** Lê o tamanho do rótulo (ex.: "💾 1.4 GB"). */
    private function tamanhoDoRotulo(string $rotulo): ?int
    {
        if (preg_match('/💾\s*([\d.,]+\s*[KMGT]?i?B)/u', $rotulo, $achados)) {
            return $this->tamanhoEmBytes($achados[1]);
        }

        return null;
    }

    /**
     * Deduz o idioma pelo rótulo do stream.
     *
     * O Torrentio marca conteúdo dublado com a bandeira do país (🇧🇷) e às vezes
     * escreve o idioma por extenso. Quando encontra a bandeira, avisamos o
     * montador da fonte via campo de idioma, que tem precedência sobre a tag do
     * nome do arquivo.
     */
    private function idiomaDoRotulo(string $texto): string
    {
        if (str_contains($texto, '🇧🇷') || stripos($texto, 'brazil') !== false) {
            return 'pt-BR';
        }

        if (stripos($texto, 'portuguese') !== false) {
            return 'portuguese';
        }

        return '';
    }
}
