<?php

namespace App\Services;

use App\Support\MensagensTorrent;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;

/**
 * Cliente do indexador Torznab (Prowlarr/Jackett).
 *
 * Sites públicos de torrents PT-BR não expõem APIs limpas como o YTS. A solução
 * padrão é um indexador proxy (Prowlarr ou Jackett) que agrega vários trackers e
 * publica uma API Torznab única. Este serviço fala essa API e devolve os itens
 * já normalizados no contrato do sistema.
 *
 * O Torznab responde em RSS/XML: cada `<item>` traz `title`, `size`, os
 * atributos `seeders`/`peers` e o `magneturl` (ou `link`). A normalização para o
 * contrato do frontend fica no TorrentService — aqui só traduzimos o XML.
 */
class TorznabService
{
    /**
     * Cache em memória dos ids de indexador habilitados, para não repetir a
     * listagem a cada consulta dentro da mesma requisição.
     *
     * @var list<int>|null
     */
    private ?array $idsEmCache = null;

    /**
     * Indica se o indexador está configurado.
     *
     * Sem URL e chave o serviço não tem como consultar; o TorrentService usa
     * isto para decidir se cai para o YTS.
     */
    public function configurado(): bool
    {
        return $this->url() !== '' && $this->chave() !== '';
    }

    /**
     * Busca torrents pelo título.
     *
     * A categoria é parâmetro porque o Prowlarr filtra por ela: filmes são
     * `2000` e séries são `5000`. Consultar um episódio com a categoria de
     * filme devolve zero resultados, por mais que o release exista no tracker.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buscar(string $titulo, ?int $ano = null, ?string $categoria = null): array
    {
        return $this->consultar($this->termoBase($titulo, $ano), $categoria);
    }

    /**
     * Busca lançamentos dublados em PT-BR.
     *
     * A busca pelo título puro mistura dezenas de lançamentos em inglês e o
     * indexador nem sempre devolve o dublado entre os primeiros resultados. Os
     * trackers nacionais publicam com a tag "dublado" no nome, então a consulta
     * com esse termo faz o indexador priorizar exatamente o que interessa.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buscarDublado(string $titulo, ?int $ano = null, ?string $categoria = null): array
    {
        return $this->consultar($this->termoBase($titulo, $ano).' dublado', $categoria);
    }

    /**
     * Monta o termo de busca com o ano, quando disponível.
     *
     * O indexador agrega trackers que misturam remakes; o ano reduz os falsos
     * positivos.
     */
    private function termoBase(string $titulo, ?int $ano): string
    {
        return $ano ? "{$titulo} {$ano}" : $titulo;
    }

    /**
     * Executa a consulta ao indexador e traduz o XML.
     *
     * A rota importa e já custou uma depuração inteira: o Prowlarr **não** expõe
     * `/api/v1/search` como o Jackett. A busca Torznab dele vive em
     * `/api/v1/indexer/{id}/search`, um endpoint por indexador. Chamar a rota do
     * Jackett devolve 404 e o provedor some da cascata sem erro visível — a lista
     * volta vazia e parece que "não há fonte".
     *
     * Como o backend não guarda os ids dos indexadores (eles nascem no
     * provisionamento), descobrimos a lista uma vez e consultamos cada um,
     * agregando os resultados. Um indexador fora do ar não derruba os outros.
     *
     * @return array<int, array<string, mixed>>
     */
    private function consultar(string $termo, ?string $categoria = null): array
    {
        if (! $this->configurado()) {
            return [];
        }

        $itens = [];

        foreach ($this->idsDosIndexadores() as $id) {
            $itens = array_merge($itens, $this->consultarIndexador($id, $termo, $categoria));
        }

        return $itens;
    }

    /**
     * Consulta um indexador específico pela rota Torznab do Prowlarr.
     *
     * @return array<int, array<string, mixed>>
     */
    private function consultarIndexador(int $id, string $termo, ?string $categoria): array
    {
        $parametros = [
            'apikey' => $this->chave(),
            't' => 'search',
            'cat' => $categoria ?? (string) config('services.torrents.torznab_categoria', '2000'),
            'q' => $termo,
        ];

        try {
            $resposta = Http::baseUrl($this->url())
                ->timeout(20)
                ->get(sprintf('/api/v1/indexer/%d/search', $id), $parametros);
        } catch (\Throwable $excecao) {
            // Um indexador indisponível não pode derrubar a busca inteira: o
            // outro pode ter o release. Só registramos e seguimos.
            report($excecao);

            return [];
        }

        if ($resposta->failed()) {
            return [];
        }

        return $this->interpretarXml($resposta->body());
    }

    /**
     * Ids de todos os indexadores cadastrados no Prowlarr.
     *
     * Não filtramos por `enable`: esse campo controla apenas se o indexador
     * participa das buscas automáticas do Prowlarr, não se o endpoint Torznab
     * dele responde. Como o provisionamento grava desabilitado quando o tracker
     * está fora do ar (fallback do Cloudflare/domínio sequestrado), filtrar por
     * `enable` deixaria a lista vazia e o degrau 2 nunca seria consultado — que
     * era exatamente o sintoma de "nenhuma fonte" com os indexadores cadastrados.
     *
     * O resultado fica em cache de memória durante a requisição para não repetir
     * a listagem a cada consulta (são duas por título, mais as variações).
     *
     * @return list<int>
     */
    private function idsDosIndexadores(): array
    {
        if ($this->idsEmCache !== null) {
            return $this->idsEmCache;
        }

        try {
            $resposta = Http::baseUrl($this->url())
                ->withHeaders(['X-Api-Key' => $this->chave()])
                ->timeout(20)
                ->get('/api/v1/indexer');
        } catch (\Throwable) {
            return $this->idsEmCache = [];
        }

        if ($resposta->failed()) {
            return $this->idsEmCache = [];
        }

        $lista = $resposta->json();

        if (! is_array($lista)) {
            return $this->idsEmCache = [];
        }

        $ids = [];

        foreach ($lista as $indexador) {
            if (! is_array($indexador)) {
                continue;
            }

            $id = (int) ($indexador['id'] ?? 0);

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $this->idsEmCache = $ids;
    }

    /**
     * Converte o XML Torznab em uma lista de itens crus.
     *
     * O XML pode vir com namespaces (`torznab`, `newznab`), então lemos os
     * atributos por nome local, sem depender do prefixo.
     *
     * @return array<int, array<string, mixed>>
     */
    private function interpretarXml(string $corpo): array
    {
        if (trim($corpo) === '') {
            return [];
        }

        // O XML do Torznab pode trazer entidades e declarações que o parser
        // estrito recusa; suprimimos os avisos e tratamos a falha como lista
        // vazia, para não derrubar a busca inteira por um item malformado.
        $anterior = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($corpo);

            if ($xml === false) {
                return [];
            }

            $itens = [];

            foreach ($xml->channel->item ?? [] as $item) {
                $itens[] = $this->normalizarItem($item);
            }

            return array_values(array_filter($itens));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($anterior);
        }
    }

    /**
     * Extrai os campos de um `<item>` do Torznab.
     *
     * @return array<string, mixed>|null
     */
    private function normalizarItem(SimpleXMLElement $item): ?array
    {
        $titulo = trim((string) ($item->title ?? ''));

        if ($titulo === '') {
            return null;
        }

        $atributos = $this->atributosTorznab($item);

        $magnet = trim((string) ($atributos['magneturl'] ?? ''));

        // Alguns indexadores devolvem o magnet no próprio `<link>`.
        if ($magnet === '') {
            $link = trim((string) ($item->link ?? ''));

            if (str_starts_with($link, 'magnet:')) {
                $magnet = $link;
            }
        }

        return [
            'titulo' => $titulo,
            'tamanho_bytes' => (int) ($atributos['size'] ?? $item->size ?? 0),
            'seeds' => (int) ($atributos['seeders'] ?? 0),
            'peers' => (int) ($atributos['peers'] ?? 0),
            'magnet' => $magnet,
            'infohash' => strtolower(trim((string) ($atributos['infohash'] ?? ''))),
            /*
             * Alguns indexadores informam o idioma do release num atributo
             * próprio. Repassamos o valor cru: quem decide se ele é útil é o
             * TorrentService, que conhece os códigos que o sistema entende.
             */
            'idioma' => trim((string) ($atributos['language'] ?? '')),
        ];
    }

    /**
     * Lê os atributos `torznab:attr` de um item, indexados pelo nome.
     *
     * O Torznab expõe os metadados como uma lista de `<torznab:attr name="..."
     * value="..."/>`. Como o prefixo do namespace varia, comparamos pelo nome
     * local do elemento.
     *
     * Dois detalhes do SimpleXML obrigam o cuidado aqui:
     *  1. `children()` sem argumento devolve apenas os filhos do namespace
     *     padrão, então os `torznab:attr` ficariam de fora e a busca perderia
     *     seeds, peers e magnet. Por isso percorremos os namespaces declarados.
     *  2. `$filho['name']` devolve um `SimpleXMLElement` (o atributo, não o
     *     texto), e o cast direto para string sai vazio. O acesso correto é
     *     `$filho->attributes()->name`.
     *
     * @return array<string, string>
     */
    private function atributosTorznab(SimpleXMLElement $item): array
    {
        $atributos = [];

        // Namespaces declarados na raiz (torznab, newznab, etc.). O namespace
        // padrão (string vazia) é incluído para o caso de indexadores que
        // emitem `<attr>` sem prefixo.
        $namespaces = $item->getDocNamespaces(true);
        $namespaces[''] = '';

        foreach ($namespaces as $uri) {
            foreach ($item->children($uri) as $filho) {
                if ($filho->getName() !== 'attr') {
                    continue;
                }

                $nome = (string) ($filho->attributes()->name ?? '');
                $valor = (string) ($filho->attributes()->value ?? '');

                if ($nome !== '') {
                    $atributos[$nome] = $valor;
                }
            }
        }

        return $atributos;
    }

    private function url(): string
    {
        return rtrim((string) config('services.torrents.torznab_url', ''), '/');
    }

    private function chave(): string
    {
        return (string) config('services.torrents.torznab_key', '');
    }
}
