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
     * Busca torrents de filme pelo título.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buscar(string $titulo, ?int $ano = null): array
    {
        if (! $this->configurado()) {
            return [];
        }

        /*
         * O termo inclui o ano quando disponível: o indexador agrega trackers
         * que misturam remakes, e o ano reduz falsos positivos.
         */
        $termo = $ano ? "{$titulo} {$ano}" : $titulo;

        $parametros = [
            'apikey' => $this->chave(),
            't' => 'search',
            'cat' => (string) config('services.torrents.torznab_categoria', '2000'),
            'q' => $termo,
        ];

        try {
            $resposta = Http::baseUrl($this->url())
                ->timeout(20)
                ->get('/api/v1/search', $parametros);
        } catch (\Throwable $excecao) {
            throw new RuntimeException(MensagensTorrent::FALHA_PROVEDOR, previous: $excecao);
        }

        if ($resposta->failed()) {
            throw new RuntimeException(
                MensagensTorrent::FALHA_PROVEDOR.' (HTTP '.$resposta->status().')'
            );
        }

        return $this->interpretarXml($resposta->body());
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
