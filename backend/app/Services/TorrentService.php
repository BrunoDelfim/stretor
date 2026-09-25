<?php

namespace App\Services;

use App\Enums\IdiomaFonte;
use App\Support\MensagensTorrent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Busca de fontes de torrent para um filme.
 *
 * O provedor externo fica isolado atrás deste serviço: o restante do sistema
 * conhece apenas o contrato normalizado (título, qualidade, idioma, seeds e
 * magnet). Trocar de provedor significa reescrever só a normalização, sem tocar
 * no controller nem no frontend.
 *
 * Há dois provedores, nesta ordem:
 *
 * 1. **Torznab (Prowlarr/Jackett)** — agrega trackers PT-BR e é o caminho para
 *    obter fontes dubladas. É o provedor principal.
 * 2. **YTS** — reserva em inglês, usada quando o indexador não está configurado
 *    ou não devolve resultados.
 *
 * As respostas são cacheadas no Redis porque a busca é a etapa mais lenta do
 * fluxo de reprodução — e o mesmo filme costuma ser aberto mais de uma vez.
 */
class TorrentService
{
    public function __construct(
        private readonly TorznabService $torznab,
    ) {
    }

    /**
     * Anunciadores públicos usados para completar o magnet. São estáveis há
     * anos e cobrem tanto UDP quanto HTTP, o que ajuda o WebTorrent a achar
     * peers mesmo quando o DHT está bloqueado na rede.
     */
    private const TRACKERS_PUBLICOS = [
        'udp://tracker.opentrackr.org:1337/announce',
        'udp://open.tracker.cl:1337/announce',
        'udp://tracker.openbittorrent.com:6969/announce',
        'udp://exodus.desync.com:6969/announce',
        'udp://tracker.torrent.eu.org:451/announce',
        'https://tracker.tamersunion.org:443/announce',
    ];

    /**
     * Fontes disponíveis para um filme, já ordenadas por prioridade.
     *
     * A ordenação coloca o dublado em PT-BR primeiro e, dentro do mesmo idioma,
     * as fontes com mais seeds. Fontes sem seeds são descartadas: não têm como
     * servir dados e só fariam o frontend perder tempo tentando.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fontes(string $titulo, ?int $ano = null, ?string $imdbId = null): array
    {
        $chave = 'torrent:fontes:'.md5(mb_strtolower($titulo).'|'.$ano.'|'.$imdbId);
        $ttl = (int) config('services.torrents.cache_ttl', 1800);

        return Cache::remember($chave, $ttl, function () use ($titulo, $ano, $imdbId) {
            return $this->consultarProvedores($titulo, $ano, $imdbId);
        });
    }

    /**
     * Consulta o indexador Torznab e, se necessário, o YTS como reserva.
     *
     * @return array<int, array<string, mixed>>
     */
    private function consultarProvedores(string $titulo, ?int $ano, ?string $imdbId): array
    {
        $fontes = [];

        // Provedor principal: Torznab, com catálogo PT-BR.
        if ($this->torznab->configurado()) {
            try {
                $fontes = $this->normalizarTorznab($this->torznab->buscar($titulo, $ano));
            } catch (RuntimeException $excecao) {
                // O indexador pode estar fora do ar; não derrubamos a busca por
                // isso — caímos para o YTS.
                report($excecao);
            }
        }

        // Reserva: YTS, quando o indexador não devolveu nada.
        if (empty($fontes)) {
            $fontes = $this->consultarYts($titulo, $ano, $imdbId);
        }

        return $this->ordenar($fontes);
    }

    /**
     * Normaliza os itens do Torznab para o contrato do sistema.
     *
     * @param  array<int, array<string, mixed>>  $itens
     * @return array<int, array<string, mixed>>
     */
    private function normalizarTorznab(array $itens): array
    {
        $fontes = [];

        foreach ($itens as $item) {
            $titulo = (string) ($item['titulo'] ?? '');
            $magnet = (string) ($item['magnet'] ?? '');

            // Sem magnet não há como reproduzir; o infohash sozinho não basta
            // porque o indexador pode não informar trackers.
            if ($titulo === '' || $magnet === '') {
                continue;
            }

            $idioma = IdiomaFonte::deduzirDoTitulo($titulo);

            $fontes[] = [
                'id' => (string) ($item['infohash'] ?? md5($magnet)),
                'titulo' => $titulo,
                'qualidade' => $this->deduzirQualidade($titulo),
                'idioma' => $idioma->value,
                'idioma_rotulo' => $idioma->rotulo(),
                'tamanho' => $this->formatarTamanho($item['tamanho_bytes'] ?? null),
                'seeds' => (int) ($item['seeds'] ?? 0),
                'peers' => (int) ($item['peers'] ?? 0),
                'magnet' => $this->completarMagnet($magnet),
            ];
        }

        return $fontes;
    }

    /**
     * Consulta o YTS (reserva em inglês) e normaliza a resposta.
     *
     * @return array<int, array<string, mixed>>
     */
    private function consultarYts(string $titulo, ?int $ano, ?string $imdbId): array
    {
        $baseUrl = rtrim((string) config('services.torrents.base_url'), '/');

        if ($baseUrl === '') {
            return [];
        }

        // O imdb_id é o termo de busca mais confiável: o título sozinho traz
        // remakes e traduções erradas (ex.: "Fight Club" devolve também o filme
        // de 2023). Só caímos para o título quando o TMDB não informa o imdb_id.
        $parametros = [
            'query_term' => $imdbId ?: $titulo,
            'limit' => MensagensTorrent::LIMITE_FONTES,
            'sort_by' => 'seeds',
            'order_by' => 'desc',
        ];

        try {
            $resposta = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->timeout(15)
                ->get('/api/v2/list_movies.json', $parametros);
        } catch (\Throwable $excecao) {
            throw new RuntimeException(MensagensTorrent::FALHA_PROVEDOR, previous: $excecao);
        }

        if ($resposta->failed()) {
            throw new RuntimeException(
                MensagensTorrent::FALHA_PROVEDOR.' (HTTP '.$resposta->status().')'
            );
        }

        $filmes = $resposta->json('data.movies') ?? [];

        // Quando a busca foi por título, o provedor pode devolver remakes de
        // outros anos. Mantemos apenas os que batem com o ano do filme.
        if (! $imdbId && $ano) {
            $filmes = array_values(array_filter(
                $filmes,
                fn (array $filme) => (int) ($filme['year'] ?? 0) === $ano
            ));
        }

        $fontes = [];

        foreach ($filmes as $filme) {
            foreach ($filme['torrents'] ?? [] as $torrent) {
                $fontes[] = $this->normalizarYts($filme, $torrent);
            }
        }

        return $fontes;
    }

    /**
     * Converte um torrent cru do YTS no contrato do sistema.
     *
     * @param  array<string, mixed>  $filme
     * @param  array<string, mixed>  $torrent
     * @return array<string, mixed>
     */
    private function normalizarYts(array $filme, array $torrent): array
    {
        $titulo = (string) ($filme['title_long'] ?? $filme['title'] ?? '');
        $idioma = IdiomaFonte::deduzirDoTitulo($titulo);

        return [
            'id' => (string) ($torrent['hash'] ?? ''),
            'titulo' => $titulo,
            'qualidade' => $torrent['quality'] ?? MensagensTorrent::QUALIDADE_NAO_INFORMADA,
            'idioma' => $idioma->value,
            'idioma_rotulo' => $idioma->rotulo(),
            'tamanho' => $this->formatarTamanho($torrent['size_bytes'] ?? null),
            'seeds' => (int) ($torrent['seeds'] ?? 0),
            'peers' => (int) ($torrent['peers'] ?? 0),
            'magnet' => $this->montarMagnetYts($torrent, $titulo),
        ];
    }

    /**
     * Ordena as fontes por idioma (dublado primeiro) e, dentro do idioma, por
     * seeds. Fontes sem seeds são descartadas: não têm como servir dados.
     *
     * @param  array<int, array<string, mixed>>  $fontes
     * @return array<int, array<string, mixed>>
     */
    private function ordenar(array $fontes): array
    {
        // Sem seeds a fonte não conecta; oferecê-la só faria o frontend perder
        // tempo tentando. O filtro é a primeira barreira contra fontes mortas.
        $fontes = array_values(array_filter(
            $fontes,
            fn (array $fonte) => ($fonte['seeds'] ?? 0) > 0 && ($fonte['magnet'] ?? '') !== ''
        ));

        usort($fontes, function (array $a, array $b) {
            $prioridadeA = IdiomaFonte::tryFrom($a['idioma'])?->prioridade() ?? 99;
            $prioridadeB = IdiomaFonte::tryFrom($b['idioma'])?->prioridade() ?? 99;

            return $prioridadeA <=> $prioridadeB ?: $b['seeds'] <=> $a['seeds'];
        });

        return array_slice($fontes, 0, MensagensTorrent::LIMITE_FONTES);
    }

    /**
     * Deduz a qualidade a partir do título do torrent.
     *
     * O Torznab não tem um campo de qualidade padronizado; a resolução costuma
     * vir no nome do arquivo (ex.: "1080p", "2160p", "WEB-DL").
     */
    private function deduzirQualidade(string $titulo): string
    {
        $texto = mb_strtolower($titulo);

        foreach (['2160p', '4k', '1080p', '720p', '480p'] as $marcador) {
            if (str_contains($texto, $marcador)) {
                return strtoupper($marcador);
            }
        }

        return MensagensTorrent::QUALIDADE_NAO_INFORMADA;
    }

    /**
     * Completa o magnet do Torznab com os anunciadores públicos.
     *
     * O indexador pode devolver um magnet sem trackers (só o infohash). Sem
     * trackers o WebTorrent depende só do DHT, que é bloqueado em muitas redes;
     * acrescentar os anunciadores públicos garante a montagem da malha.
     */
    private function completarMagnet(string $magnet): string
    {
        $faltantes = array_filter(
            self::TRACKERS_PUBLICOS,
            fn (string $tracker) => ! str_contains($magnet, rawurlencode($tracker))
                && ! str_contains($magnet, $tracker)
        );

        foreach ($faltantes as $tracker) {
            $magnet .= '&tr='.rawurlencode($tracker);
        }

        return $magnet;
    }

    /**
     * Monta o link magnet a partir do hash e das trackers do YTS.
     *
     * O YTS devolve em `url` um link de download do .torrent, não uma lista de
     * trackers. Sem trackers o magnet não encontra peers, então acrescentamos
     * os anunciadores públicos mais usados.
     *
     * @param  array<string, mixed>  $torrent
     */
    private function montarMagnetYts(array $torrent, string $titulo): string
    {
        $hash = (string) ($torrent['hash'] ?? '');

        if ($hash === '') {
            return '';
        }

        $magnet = 'magnet:?xt=urn:btih:'.$hash.'&dn='.rawurlencode($titulo);

        foreach ($this->trackersYts($torrent) as $tracker) {
            $magnet .= '&tr='.rawurlencode($tracker);
        }

        return $magnet;
    }

    /**
     * Trackers do torrent do YTS, aceitando tanto a lista quanto o link único
     * que ele devolve, e completando com os anunciadores públicos padrão.
     *
     * @param  array<string, mixed>  $torrent
     * @return array<int, string>
     */
    private function trackersYts(array $torrent): array
    {
        $informados = $torrent['url'] ?? [];

        if (is_string($informados)) {
            $informados = [$informados];
        }

        $informados = array_filter(
            (array) $informados,
            fn ($tracker) => is_string($tracker)
                && (str_starts_with($tracker, 'udp://') || str_starts_with($tracker, 'http'))
        );

        return array_values(array_unique(array_merge($informados, self::TRACKERS_PUBLICOS)));
    }

    /** Converte bytes em um rótulo legível (ex.: "2.1 GB"). */
    private function formatarTamanho(?int $bytes): ?string
    {
        if (empty($bytes) || $bytes <= 0) {
            return null;
        }

        $unidades = ['B', 'KB', 'MB', 'GB', 'TB'];
        $indice = (int) floor(log($bytes, 1024));
        $indice = min($indice, count($unidades) - 1);
        $valor = $bytes / (1024 ** $indice);

        return number_format($valor, $indice > 1 ? 1 : 0, ',', '.').' '.$unidades[$indice];
    }
}
