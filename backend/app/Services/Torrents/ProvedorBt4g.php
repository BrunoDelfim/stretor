<?php

namespace App\Services\Torrents;

use App\Contracts\ProvedorTorrents;
use Illuminate\Support\Facades\Http;

/**
 * Provedor BT4G — metabuscador público com magnet na própria página de busca.
 *
 * O BT4G indexa por DHT e entrega o link do magnet direto no resultado, o que
 * dispensa abrir a página de cada lançamento. É o provedor de HTML mais barato
 * de consultar, e cobre releases que os indexadores de catálogo não têm.
 *
 * Sem API e sem chave, a leitura é por HTML — por isso a extração é genérica de
 * propósito: em vez de amarrar em classes CSS (que mudam a cada redesenho),
 * procuramos qualquer link de magnet e lemos o contexto ao redor para achar
 * tamanho e seeds. Se o layout mudar, a busca continua funcionando; no pior caso
 * perdemos os metadados opcionais, não a fonte.
 *
 * Como o site troca de endereço com frequência (espelho novo a cada bloqueio),
 * a lista de espelhos vem da configuração e é percorrida até um responder.
 */
class ProvedorBt4g implements ProvedorTorrents
{
    use NormalizaFonte;

    public function identificador(): string
    {
        return 'bt4g';
    }

    public function rotulo(): string
    {
        return 'BT4G';
    }

    /** Provedor público: sempre disponível, sem credencial. */
    public function disponivel(): bool
    {
        return ! empty($this->espelhos());
    }

    public function buscar(string $titulo, ?int $ano = null, ?string $imdbId = null): array
    {
        $termos = array_merge(
            [TermosBusca::base($titulo, $ano)],
            array_slice(TermosBusca::paraDublado($titulo, $ano), 0, 2),
        );

        foreach ($this->espelhos() as $espelho) {
            $fontes = [];

            foreach ($termos as $termo) {
                $html = $this->baixar($espelho, $termo);

                if ($html === null) {
                    // Espelho fora do ar: tenta o próximo endereço da lista.
                    continue 2;
                }

                $fontes = array_merge($fontes, $this->extrair($html));
            }

            if (! empty($fontes)) {
                return $fontes;
            }
        }

        return [];
    }

    /**
     * Busca o HTML do termo, ou `null` quando o espelho não responde.
     */
    private function baixar(string $base, string $termo): ?string
    {
        $timeout = (int) config('services.torrents.tempo_limite', 15);

        try {
            $resposta = Http::baseUrl($base)
                ->withUserAgent($this->navegador())
                ->timeout($timeout)
                ->get('/search', [
                    'q' => TermosBusca::limpar($termo),
                    'orderby' => 'seeders',
                ]);
        } catch (\Throwable) {
            return null;
        }

        return $resposta->failed() ? null : $resposta->body();
    }

    /**
     * Varre o HTML atrás de links de magnet.
     *
     * @return array<int, array<string, mixed>>
     */
    private function extrair(string $html): array
    {
        $documento = $this->carregarHtml($html);

        if ($documento === null) {
            return [];
        }

        $xpath = new \DOMXPath($documento);

        $links = $xpath->query(
            '//a[contains(@href, "/magnet/") or starts-with(@href, "magnet:")]'
        );

        $fontes = [];
        $vistos = [];

        foreach ($links as $link) {
            /** @var \DOMElement $link */
            $hash = $this->hashDoLink($link->getAttribute('href'));

            if ($hash === '' || isset($vistos[$hash])) {
                continue;
            }

            $titulo = $this->limparTexto($link->textContent);

            if ($titulo === '') {
                continue;
            }

            $contexto = $this->textoDoContexto($link);

            $vistos[$hash] = true;

            $fontes[] = $this->montarFonte([
                'id' => $hash,
                'titulo' => $titulo,
                'magnet' => $this->magnetDoHash($hash, $titulo),
                'tamanho_bytes' => $this->tamanhoDoTexto($contexto),
                'seeds' => $this->seedsDoTexto($contexto),
                'peers' => 0,
                // Sem campo de idioma próprio: o BT4G não informa, então quem
                // decide é a dedução pela tag do nome, feita em montarFonte().
            ], $this->identificador(), $this->rotulo());
        }

        return $fontes;
    }

    /**
     * Extrai o infohash tanto do `magnet:` completo quanto do caminho
     * `/magnet/<hash>` usado pelo site.
     */
    private function hashDoLink(string $href): string
    {
        if (preg_match('/magnet:\?xt=urn:btih:([A-Za-z0-9]{32,40})/', $href, $achados)) {
            return strtolower($achados[1]);
        }

        if (preg_match('#/magnet/([A-Za-z0-9]{32,40})#', $href, $achados)) {
            return strtolower($achados[1]);
        }

        return '';
    }

    /**
     * Junta o texto do link e dos ancestrais próximos.
     *
     * Tamanho e seeds ficam em elementos irmãos do link, não dentro dele. Subir
     * três níveis alcança o cartão do resultado inteiro sem chegar ao container
     * da página, onde os números de outros resultados contaminariam a leitura.
     */
    private function textoDoContexto(\DOMElement $link): string
    {
        $texto = $link->textContent;
        $no = $link->parentNode;

        for ($nivel = 0; $nivel < 3 && $no instanceof \DOMElement; $nivel++) {
            $texto .= ' '.$no->textContent;
            $no = $no->parentNode;
        }

        return $this->limparTexto($texto);
    }

    /** Lê o tamanho do contexto ("1.4 GB", "700 MiB"). */
    private function tamanhoDoTexto(string $texto): ?int
    {
        if (preg_match('/\b([\d.,]+\s*[KMGT]?i?B)\b/i', $texto, $achados)) {
            return $this->tamanhoEmBytes($achados[1]);
        }

        return null;
    }

    /**
     * Lê a contagem de seeds do contexto.
     *
     * Quando o site não publica a contagem, devolvemos o piso de seeds não
     * medidos: sem ele a fonte seria descartada como morta, e uma fonte PT-BR
     * plausível vale mais que um número faltando.
     */
    private function seedsDoTexto(string $texto): int
    {
        if (preg_match('/\b(\d+)\s*(?:seeders?|seeds|semead(?:or|ores))\b/i', $texto, $achados)) {
            return (int) $achados[1];
        }

        return self::SEEDS_NAO_MEDIDOS;
    }

    /** @return array<int, string> */
    private function espelhos(): array
    {
        $bruto = (string) config('services.torrents.bt4g_urls', 'https://bt4gprx.com');

        return array_values(array_filter(array_map(
            fn (string $url) => rtrim(trim($url), '/'),
            explode(',', $bruto)
        )));
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

            // O prefixo com a declaração de encoding força a interpretação em
            // UTF-8; sem ele o parser assume Latin-1 e os títulos com acento
            // chegam corrompidos, quebrando a dedução de idioma.
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
