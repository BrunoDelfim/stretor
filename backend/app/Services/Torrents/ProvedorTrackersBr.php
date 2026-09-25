<?php

namespace App\Services\Torrents;

use App\Contracts\ProvedorTorrents;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Provedor nativo de trackers públicos PT-BR.
 *
 * Este é o caminho principal do sistema: em vez de depender de um indexador
 * externo (Prowlarr) para achar o filme dublado, o backend fala direto com os
 * trackers brasileiros e faz o que o indexador faria — busca, abre a página do
 * lançamento e recolhe o magnet.
 *
 * Como esses sites são de catálogo aberto (WordPress-like), o magnet raramente
 * aparece na página de busca: o resultado é um cartão que leva para a página do
 * filme, onde ficam os links. O fluxo é, portanto, de dois passos:
 *
 * 1. Buscar o termo e recolher os candidatos (título + link) do resultado.
 * 2. Abrir as páginas dos candidatos **em paralelo** e varrer cada uma atrás de
 *    magnet. O limite de páginas evita transformar a busca num rastreador do
 *    site inteiro.
 *
 * Cada site é isolado: um domínio fora do ar, com HTML inesperado ou com
 * Cloudflare não derruba os outros nem a busca como um todo. O resultado de cada
 * um é somado — quanto mais trackers respondem, maior a chance de achar o
 * dublado.
 */
class ProvedorTrackersBr implements ProvedorTorrents
{
    use NormalizaFonte;

    /**
     * Quantas páginas de lançamento abrir por site.
     *
     * Os trackers brasileiros costumam publicar o filme em uma ou duas páginas
     * (dublado e legendado). Abrir mais do que isso só aumenta o tempo de espera
     * sem ganho real — a ordenação do site já põe o mais relevante primeiro.
     */
    private const MAX_DETALHES_POR_SITE = 6;

    /**
     * Seletor de links do resultado, o mesmo conjunto usado na definição
     * customizada do Prowlarr: os trackers brasileiros variam entre `h2`, `h3` e
     * classes de título, e aceitar os três cobre a maioria dos layouts.
     */
    private const SELETOR_RESULTADOS = '//h2//a | //h3//a | //a[contains(concat(" ", normalize-space(@class), " "), " title ")]';

    public function identificador(): string
    {
        return 'trackers_br';
    }

    public function rotulo(): string
    {
        return 'Tracker PT-BR';
    }

    /** Provedor público: sempre disponível enquanto houver site configurado. */
    public function disponivel(): bool
    {
        return ! empty($this->sites());
    }

    public function buscar(string $titulo, ?int $ano = null, ?string $imdbId = null): array
    {
        $termos = [
            TermosBusca::base($titulo, $ano),
            TermosBusca::base($titulo, $ano).' dublado',
        ];

        $candidatos = [];

        foreach ($this->sites() as $site) {
            foreach ($termos as $termo) {
                $candidatos = array_merge($candidatos, $this->candidatosDoSite($site, $termo));
            }
        }

        if (empty($candidatos)) {
            return [];
        }

        return $this->recolherMagnets($candidatos);
    }

    /**
     * Passo 1 — recolhe os candidatos (título + link) da página de busca.
     *
     * @return array<int, array{titulo: string, url: string}>
     */
    private function candidatosDoSite(string $site, string $termo): array
    {
        $html = $this->baixar($site.$this->caminhoDaBusca().'?'.http_build_query([
            'search' => TermosBusca::limpar($termo),
            // Alguns temas usam `s` em vez de `search`; mandar os dois é inócuo
            // e cobre as duas famílias de layout sem precisar de configuração.
            's' => TermosBusca::limpar($termo),
        ]));

        if ($html === null) {
            return [];
        }

        $documento = $this->carregarHtml($html);

        if ($documento === null) {
            return [];
        }

        $xpath = new \DOMXPath($documento);

        $candidatos = [];
        $vistos = [];

        foreach ($xpath->query(self::SELETOR_RESULTADOS) as $link) {
            /** @var \DOMElement $link */
            $titulo = $this->limparTexto($link->textContent);
            $url = $this->absolutizar($site, $link->getAttribute('href'));

            if (! $this->pareceLancamento($titulo, $url) || isset($vistos[$url])) {
                continue;
            }

            $vistos[$url] = true;

            $candidatos[] = ['titulo' => $titulo, 'url' => $url];
        }

        // A ordem do site já é por relevância; o corte fica depois da
        // deduplicação para não gastar o limite com links repetidos do menu.
        return array_slice($candidatos, 0, self::MAX_DETALHES_POR_SITE);
    }

    /**
     * Passo 2 — abre as páginas dos candidatos em paralelo e recolhe os magnets.
     *
     * @param  array<int, array{titulo: string, url: string}>  $candidatos
     * @return array<int, array<string, mixed>>
     */
    private function recolherMagnets(array $candidatos): array
    {
        /*
         * O teto por site vale dentro de cada site, mas a soma dos sites poderia
         * estourar. Limitamos o lote total para a busca continuar respondendo em
         * tempo razoável mesmo com vários trackers configurados.
         */
        $limite = (int) config('services.torrents.max_detalhes_busca', 16);
        $candidatos = array_slice($candidatos, 0, max(1, $limite));

        $timeout = (int) config('services.torrents.tempo_limite', 15);

        $respostas = Http::pool(fn ($pool) => array_map(
            fn (array $candidato) => $pool->as(md5($candidato['url']))
                ->withUserAgent($this->navegador())
                ->timeout($timeout)
                ->get($candidato['url']),
            $candidatos
        ));

        $fontes = [];
        $vistos = [];

        foreach ($candidatos as $candidato) {
            $resposta = $respostas[md5($candidato['url'])] ?? null;

            if (! $resposta instanceof Response || $resposta->failed()) {
                continue;
            }

            $fonte = $this->extrairDoDetalhe($resposta->body(), $candidato);

            if ($fonte === null || isset($vistos[$fonte['id']])) {
                continue;
            }

            $vistos[$fonte['id']] = true;
            $fontes[] = $fonte;
        }

        if (empty($fontes)) {
            Log::info('Busca nativa PT-BR abriu páginas de lançamento mas não encontrou magnet.', [
                'candidatos' => count($candidatos),
            ]);
        }

        return $fontes;
    }

    /**
     * Vare a página do lançamento atrás de um magnet.
     *
     * @param  array{titulo: string, url: string}  $candidato
     * @return array<string, mixed>|null
     */
    private function extrairDoDetalhe(string $html, array $candidato): ?array
    {
        if (! preg_match_all('/magnet:\?xt=urn:btih:([A-Za-z0-9]{32,40})/i', $html, $achados)) {
            return null;
        }

        // A página pode trazer o magnet de vários episódios/qualidades. O
        // primeiro é o do lançamento principal; os outros seriam o mesmo filme em
        // resoluções diferentes, que a lista não precisa repetir.
        $hash = strtolower($achados[1][0]);

        $contexto = $this->contextoDoMagnet($html, $hash);

        return $this->montarFonte([
            'id' => $hash,
            'titulo' => $candidato['titulo'],
            'magnet' => $this->magnetDoHash($hash, $candidato['titulo']),
            'tamanho_bytes' => $this->tamanhoDoTexto($contexto),
            'seeds' => $this->seedsDoTexto($contexto),
            'peers' => 0,
        ], $this->identificador(), $this->rotulo());
    }

    /**
     * Recorta o texto ao redor do magnet para ler tamanho e seeds.
     *
     * Os trackers brasileiros quase nunca publicam a contagem de peers — o que
     * existe é a menção de tamanho ao lado do link. O recorte usa o trecho do
     * documento em volta do magnet (e não a página inteira) porque a página tem
     * tabelas de outros lançamentos e o primeiro número encontrado seria de
     * outro filme.
     */
    private function contextoDoMagnet(string $html, string $hash): string
    {
        $posicao = stripos($html, $hash);

        if ($posicao === false) {
            return '';
        }

        return $this->limparTexto(strip_tags(substr($html, $posicao, 1200)));
    }

    /** Lê o tamanho do contexto ("1.4 GB", "700 MiB"). */
    private function tamanhoDoTexto(string $texto): ?int
    {
        if (preg_match('/\b([\d.,]+\s*[KMGT]?i?B)\b/i', $texto, $achados)) {
            return $this->tamanhoEmBytes($achados[1]);
        }

        return null;
    }

    /** Lê a contagem de seeds, ou aplica o piso quando o site não a publica. */
    private function seedsDoTexto(string $texto): int
    {
        if (preg_match('/\b(\d+)\s*(?:seeders?|seeds|semead(?:or|ores))\b/i', $texto, $achados)) {
            return (int) $achados[1];
        }

        return self::SEEDS_NAO_MEDIDOS;
    }

    /**
     * Descarta links que claramente não são lançamentos.
     *
     * O seletor pega `h2/h3` porque é onde os temas põem o título do filme, mas
     * os mesmos níveis abrigam widgets ("Últimos lançamentos", "Categorias") e
     * links institucionais. Um lançamento tem link absoluto de página e título
     * com mais de um punhado de caracteres.
     */
    private function pareceLancamento(string $titulo, string $url): bool
    {
        if (mb_strlen($titulo) < 8 || $url === '') {
            return false;
        }

        if (! str_starts_with($url, 'http') || str_contains($url, '?cat=')) {
            return false;
        }

        return ! str_contains(mb_strtolower($titulo), 'categorias');
    }

    /** Converte um href relativo em absoluto, ignorando âncoras e javascript. */
    private function absolutizar(string $site, string $href): string
    {
        $href = trim($href);

        if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
            return '';
        }

        if (str_starts_with($href, 'http')) {
            return $href;
        }

        return $site.'/'.ltrim($href, '/');
    }

    /** Baixa o HTML da página, ou `null` quando o site não responde. */
    private function baixar(string $url): ?string
    {
        $timeout = (int) config('services.torrents.tempo_limite', 15);

        try {
            $resposta = Http::withUserAgent($this->navegador())
                ->timeout($timeout)
                ->get($url);
        } catch (\Throwable $excecao) {
            Log::info('Tracker PT-BR indisponível na busca nativa.', [
                'url' => $url,
                'motivo' => $excecao->getMessage(),
            ]);

            return null;
        }

        return $resposta->failed() ? null : $resposta->body();
    }

    /** @return array<int, string> */
    private function sites(): array
    {
        $bruto = (string) config('services.torrents.trackers_br_urls', '');

        return array_values(array_filter(array_map(
            fn (string $url) => rtrim(trim($url), '/'),
            explode(',', $bruto)
        )));
    }

    private function caminhoDaBusca(): string
    {
        return '/'.ltrim((string) config('services.torrents.trackers_br_busca', 'index.php'), '/');
    }

    /** Cria o DOM a partir do HTML cru, tolerando marcação quebrada. */
    private function carregarHtml(string $html): ?\DOMDocument
    {
        if (trim($html) === '') {
            return null;
        }

        $anterior = libxml_use_internal_errors(true);

        try {
            $documento = new \DOMDocument();
            $documento->loadHTML(
                '<?xml encoding="UTF-8">'.$html,
                LIBXML_NOWARNING | LIBXML_NOERROR
            );
            $documento->encoding = 'UTF-8';

            return $documento;
        } catch (\Throwable) {
            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
        }
    }

    /** Evita o bloqueio por user-agent vazio, que vários trackers aplicam. */
    private function navegador(): string
    {
        return (string) config(
            'services.torrents.user_agent',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36'
        );
    }
}
