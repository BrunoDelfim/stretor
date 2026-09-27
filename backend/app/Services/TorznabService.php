<?php

namespace App\Services;

use App\Services\Torrents\TrackersPublicos;
use App\Support\MensagensTorrent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
 * atributos `seeders`/`peers` e o `magneturl` (ou `link`). A API interna do
 * Prowlarr responde o mesmo acervo em JSON (`seeders`/`leechers`/`magnetUrl`).
 * A normalização para o contrato do frontend fica no TorrentService — aqui só
 * traduzimos o retorno, seja XML ou JSON.
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
     * Rota preferida de cada indexador nesta requisição.
     *
     * Descobrir a rota certa custa uma ida e volta; descoberta uma vez, ela vai
     * na frente das demais consultas da mesma requisição. O mapa é por id
     * porque a rota carrega o id no próprio caminho (`/3/api`): guardar uma só
     * para todos fazia o indexador 1 ser consultado pelo caminho do indexador 3
     * e receber o acervo errado.
     *
     * @var array<int, string>
     */
    private array $rotaPreferida = [];

    /**
     * Rotas que responderam erro nesta requisição.
     *
     * @var array<string, true>
     */
    private array $rotasMortas = [];

    /**
     * Rotas que responderam com sucesso nesta requisição, ainda que vazias.
     *
     * Separa "rota errada" de "acervo sem o release": só o primeiro caso merece
     * aviso no log, senão todo título sem resultado viraria alarme.
     *
     * @var array<string, true>
     */
    private array $rotasVivas = [];

    /**
     * Evita repetir o aviso de rotas esgotadas a cada termo consultado.
     */
    private bool $avisouRotas = false;

    /**
     * Teto de cada pedido ao Prowlarr, em segundos.
     */
    private const TEMPO_LIMITE_PEDIDO = 20;

    /**
     * Marca de tempo absoluta em que o degrau inteiro precisa parar.
     *
     * O total deste degrau é o produto de três variáveis que não paramos de
     * aumentar: os termos do episódio, as duas consultas por termo e os
     * indexadores cadastrados no Prowlarr. Como a varredura é sequencial e cada
     * indexador pode gastar quatro tentativas só para descobrir a rota, o tempo
     * crescia sem teto. Enquanto havia um indexador isso cabia no orçamento de
     * paciência do navegador; ao entrarem o ThePirateBay e o TorrentGalaxy — este
     * último atrás do CloudFlare, respondendo pelo FlareSolverr com vários
     * segundos por consulta — o `/fontes` de um episódio passou a estourar o
     * tempo e a requisição era cancelada antes de a interface receber a lista.
     * O prazo devolve o controle: ao estourar, paramos de perguntar e
     * entregamos o que já foi recolhido, em vez de não entregar nada.
     */
    private ?float $prazo = null;

    /**
     * Evita repetir o aviso de prazo estourado a cada indexador.
     */
    private bool $avisouPrazo = false;

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
     * Marca de tempo em que o degrau precisa parar.
     *
     * O prazo é ancorado na primeira consulta, e não na construção do serviço:
     * o Laravel resolve o container antes de qualquer trabalho, e medir a partir
     * dali descontaria o tempo gasto na autenticação e na montagem da resposta.
     */
    private function prazo(): float
    {
        return $this->prazo ??= microtime(true)
            + max(1, (int) config('services.torrents.torznab_orcamento', 12));
    }

    /** O tempo reservado para o degrau acabou? */
    private function orcamentoEsgotado(): bool
    {
        return microtime(true) >= $this->prazo();
    }

    /**
     * Quanto sobra do orçamento, em segundos, para limitar um pedido isolado.
     *
     * O piso é 1: `timeout(0)` no Laravel significa "sem limite", e disparar um
     * pedido já vencido seria justamente o que o orçamento veio evitar.
     */
    private function tempoRestante(): int
    {
        return max(1, (int) ceil($this->prazo() - microtime(true)));
    }

    /**
     * Registra por que a varredura parou, uma vez por requisição.
     *
     * Sem este aviso, um resultado menor que o esperado fica indistinguível de
     * "o acervo não tinha o release" — que é o sintoma enganoso que este degrau
     * já produziu antes.
     */
    private function avisarPrazo(): void
    {
        if ($this->avisouPrazo) {
            return;
        }

        $this->avisouPrazo = true;

        Log::warning('Orçamento do degrau Torznab esgotado; devolvendo o que foi recolhido.', [
            'orcamento' => (int) config('services.torrents.torznab_orcamento', 12),
        ]);
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
     * Executa a consulta ao indexador e traduz o retorno.
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
            if ($this->orcamentoEsgotado()) {
                $this->avisarPrazo();

                break;
            }

            $itens = array_merge($itens, $this->consultarIndexador($id, $termo, $categoria));
        }

        return $itens;
    }

    /**
     * Consulta um indexador pelas rotas conhecidas do Prowlarr.
     *
     * A rota é a parte frágil desta integração. O Prowlarr publica o **mesmo**
     * acervo por caminhos diferentes — o proxy Torznab por indexador
     * (`/{id}/api`, o mesmo que o painel usa), a variação Newznab de algumas
     * versões (`/api/v1/indexer/{id}/newznab`) e a API interna
     * (`/api/v1/indexer/{id}/search`, que responde **JSON**, não RSS).
     *
     * Errar a rota não gera erro visível: a resposta volta vazia e o sintoma é
     * "não há fonte", mesmo com o release aparecendo no painel. Foi assim que
     * este degrau passou a devolver zero para um título que o painel encontra.
     *
     * Por isso tentamos as rotas até uma responder sem falha. A que respondeu
     * fica memorizada para o seu indexador e passa a ser a primeira das
     * consultas seguintes; as que responderam erro saem da lista, para não
     * custar uma ida e volta por termo.
     *
     * @return array<int, array<string, mixed>>
     */
    private function consultarIndexador(int $id, string $termo, ?string $categoria): array
    {
        foreach ($this->rotas($id) as $rota) {
            // Desistir da varredura de rotas deste indexador é preferível a
            // gastar o resto do orçamento num caminho que talvez nem responda.
            if ($this->orcamentoEsgotado()) {
                $this->avisarPrazo();

                break;
            }

            $itens = $this->pedir($rota, $termo, $categoria);

            if ($itens !== []) {
                $this->rotaPreferida[$id] = $rota;

                Log::debug('Rota Torznab vencedora.', [
                    'indexador' => $id,
                    'rota' => $rota,
                    'termo' => $termo,
                    'itens' => count($itens),
                ]);

                return $itens;
            }

            /*
             * A rota respondeu sem falha, ainda que vazia: ela é a via legítima
             * deste indexador e as outras candidatas publicam o mesmo acervo por
             * outro caminho. Um corpo vazio aqui significa "este indexador não
             * tem o release", não "tentei o caminho errado" — insistir nas
             * demais repetiria a mesma pergunta. Era assim que um único termo de
             * episódio virava seis idas e voltas e a busca a frio passava dos
             * 40 s.
             */
            if (isset($this->rotasVivas[$rota])) {
                $this->rotaPreferida[$id] = $rota;

                return [];
            }
        }

        $this->avisarRotasEsgotadas($id, $termo);

        return [];
    }

    /**
     * Rotas candidatas do Prowlarr, da mais provável para a mais improvável.
     *
     * A lista termina na rota agregada (`/api`), que busca em todos os
     * indexadores de uma vez: se o caminho por indexador não servir, ela ainda
     * pode trazer o release.
     *
     * @return list<string>
     */
    private function rotas(int $id): array
    {
        $candidatas = [
            sprintf('/%d/api', $id),
            sprintf('/api/v1/indexer/%d/newznab', $id),
            sprintf('/api/v1/indexer/%d/search', $id),
            '/api',
        ];

        $preferida = $this->rotaPreferida[$id] ?? null;

        if ($preferida !== null) {
            $candidatas = array_values(array_diff($candidatas, [$preferida]));
            array_unshift($candidatas, $preferida);
        }

        return array_values(array_filter(
            $candidatas,
            fn (string $rota) => ! isset($this->rotasMortas[$rota]),
        ));
    }

    /**
     * Faz o pedido de uma rota e devolve os itens no contrato interno.
     *
     * O mesmo termo vai com dois nomes (`q` e `query`) porque cada caminho usa
     * um: o Torznab procura em `q`, a API JSON em `query`. Mandar os dois é
     * inofensivo e evita repetir a tentativa só para trocar o nome do campo.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pedir(string $rota, string $termo, ?string $categoria): array
    {
        $parametros = [
            'apikey' => $this->chave(),
            't' => 'search',
            'cat' => $categoria ?? (string) config('services.torrents.torznab_categoria', '2000'),
            'q' => $termo,
            'query' => $termo,
        ];

        try {
            $resposta = Http::baseUrl($this->url())
                ->timeout(min(self::TEMPO_LIMITE_PEDIDO, $this->tempoRestante()))
                ->get($rota, $parametros);
        } catch (\Throwable $excecao) {
            // Um indexador indisponível não pode derrubar a busca inteira: o
            // outro pode ter o release. Só registramos e seguimos.
            report($excecao);
            $this->rotasMortas[$rota] = true;

            return [];
        }

        if ($resposta->failed()) {
            $this->rotasMortas[$rota] = true;

            return [];
        }

        $corpo = (string) $resposta->body();
        $itens = $this->itensDoCorpo($corpo);

        /*
         * O Newznab recusa a consulta com HTTP 200 e um `<error>` no corpo
         * (chave inválida, indexador bloqueado). Isso não é "acervo sem o
         * release": o caminho está errado e as outras rotas ainda podem servir.
         * O aviso com código e descrição sai do parser, que é quem lê o corpo.
         */
        if (str_contains($corpo, '<error')) {
            $this->rotasMortas[$rota] = true;

            return [];
        }

        $this->rotasVivas[$rota] = true;

        if ($itens === []) {
            // Rota viva devolvendo zero é o sintoma mais enganoso desta
            // integração: não há exceção nem erro HTTP para seguir, e o vazio se
            // confunde com "não existe fonte". Guardamos o começo do corpo para
            // distinguir corpo vazio, XML sem itens e formato inesperado.
            Log::debug('Rota Torznab respondeu sem itens.', [
                'rota' => $rota,
                'termo' => $termo,
                'status' => $resposta->status(),
                'corpo' => mb_substr($corpo, 0, 600),
            ]);
        }

        return $itens;
    }

    /**
     * Decide entre XML e JSON pelo próprio corpo da resposta.
     *
     * Ler o formato em vez de confiar na rota deixa o serviço de pé quando o
     * Prowlarr muda o caminho de uma versão para outra — que é justamente o que
     * aconteceu aqui.
     *
     * @return array<int, array<string, mixed>>
     */
    private function itensDoCorpo(string $corpo): array
    {
        $inicio = ltrim($corpo);

        if (str_starts_with($inicio, '[') || str_starts_with($inicio, '{')) {
            return $this->itensDoJson($corpo);
        }

        return $this->interpretarXml($corpo);
    }

    /**
     * Traduz a resposta JSON do Prowlarr.
     *
     * É o `ReleaseResource` da API interna: `title`, `size`, `seeders`,
     * `leechers`, `magnetUrl` e `infoHash`. Os `leechers` ocupam o lugar dos
     * `peers` do Torznab — são os mesmos números com outro nome.
     *
     * @return array<int, array<string, mixed>>
     */
    private function itensDoJson(string $corpo): array
    {
        $lista = json_decode($corpo, true);

        if (! is_array($lista)) {
            return [];
        }

        $itens = [];

        foreach ($lista as $item) {
            if (! is_array($item)) {
                continue;
            }

            $titulo = trim((string) ($item['title'] ?? ''));

            if ($titulo === '') {
                continue;
            }

            $hash = strtolower(trim((string) ($item['infoHash'] ?? '')));
            $magnet = trim((string) ($item['magnetUrl'] ?? ''));

            // Alguns itens chegam só com o infohash. Sem magnet não há como
            // reproduzir, então completamos com os anunciadores do projeto.
            if ($magnet === '' && $hash !== '') {
                $magnet = $this->magnetDoInfohash($hash, $titulo);
            }

            $itens[] = [
                'titulo' => $titulo,
                'tamanho_bytes' => (int) ($item['size'] ?? 0),
                'seeds' => (int) ($item['seeders'] ?? 0),
                'peers' => (int) ($item['leechers'] ?? 0),
                'magnet' => $magnet,
                'infohash' => $hash,
                'idioma' => trim((string) ($item['language'] ?? '')),
            ];
        }

        return $itens;
    }

    /**
     * Monta um magnet a partir do infohash, com os anunciadores do projeto.
     */
    private function magnetDoInfohash(string $hash, string $titulo): string
    {
        $anunciadores = implode('', array_map(
            fn (string $anunciador) => '&tr='.rawurlencode($anunciador),
            TrackersPublicos::LISTA,
        ));

        return 'magnet:?xt=urn:btih:'.$hash.'&dn='.rawurlencode($titulo).$anunciadores;
    }

    /**
     * Denuncia, uma vez por requisição, que nenhuma rota respondeu com sucesso.
     *
     * Erro de rota e acervo sem o release produzem a mesma lista vazia, mas só o
     * primeiro é defeito. Rota viva com lista vazia é resposta legítima do
     * indexador e não gera aviso; sem essa separação, ou o log ficaria mudo no
     * caso que importa, ou viraria alarme a cada título sem resultado.
     */
    private function avisarRotasEsgotadas(int $id, string $termo): void
    {
        if ($this->avisouRotas || $this->rotasVivas !== []) {
            return;
        }

        $this->avisouRotas = true;

        Log::warning('Nenhuma rota Torznab do Prowlarr respondeu com sucesso.', [
            'indexador' => $id,
            'termo' => $termo,
            'rotas_tentadas' => array_keys($this->rotasMortas),
        ]);
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
                ->timeout(min(self::TEMPO_LIMITE_PEDIDO, $this->tempoRestante()))
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

            if ($id <= 0) {
                continue;
            }

            /*
             * Indexador desabilitado sai da varredura. O provisionamento grava
             * como desabilitado justamente o que não passou no teste de busca —
             * o caso dos protegidos pelo CloudFlare quando o FlareSolverr não
             * está de pé. Consultá-los assim mesmo custava quatro tentativas de
             * vinte segundos por termo para receber silêncio, e é boa parte do
             * que fazia a busca de episódio estourar o tempo. A ausência do
             * campo num Prowlarr antigo é tratada como habilitado, para não
             * esvaziar a varredura por uma diferença de versão.
             */
            if (! ($indexador['enable'] ?? true)) {
                continue;
            }

            $ids[] = $id;
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

            /*
             * O protocolo Newznab devolve erro com HTTP 200 e um `<error>` no
             * corpo (chave recusada, indexador bloqueado). Sem ler o corpo, isso
             * vira "nenhuma fonte" silencioso — o mesmo sintoma de rota errada.
             */
            $erro = $xml->error ?? null;

            if ($erro !== null) {
                Log::warning('Indexador Torznab recusou a consulta.', [
                    'codigo' => (string) ($erro['code'] ?? ''),
                    'descricao' => (string) ($erro['description'] ?? ''),
                ]);

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
