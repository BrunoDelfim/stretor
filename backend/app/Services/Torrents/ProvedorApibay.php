<?php

namespace App\Services\Torrents;

use App\Contracts\ProvedorTorrents;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Provedor APIBay — a API JSON pública do The Pirate Bay.
 *
 * É o provedor nativo mais simples de todos: sem chave, sem HTML e sem
 * Cloudflare pela frente. O `q.php` responde um array JSON com nome, infohash,
 * seeds e categoria de cada torrent, o que dispensa raspagem de página.
 *
 * O acervo é mundial (predominantemente em inglês), então ele não é a aposta
 * principal para o dublado — mas existe uma quantidade relevante de releases
 * brasileiras com "Dublado" no nome, e o custo de consultá-lo é quase zero. É o
 * complemento da busca nativa, não o seu cérebro.
 *
 * Nota sobre a categoria: a API devolve o id numérico da subcategoria (201, 207,
 * 209...) e não aceita o filtro de filmes de forma confiável. Em vez de confiar
 * no parâmetro `cat`, filtramos localmente pela faixa de vídeo (200–299),
 * descartando TV (205) e handheld (206).
 */
class ProvedorApibay implements ProvedorTorrents
{
    use ConsultaComOrcamento;
    use NormalizaFonte;

    public function __construct(
        private readonly ClienteHttp $cliente,
        private readonly OrcamentoBusca $orcamento,
    ) {}

    /**
     * Subcategorias de vídeo que não são filme: TV (205) e dispositivos
     * portáteis (206). Sem esse corte, uma série homônima entraria na lista como
     * se fosse o filme procurado.
     */
    private const CATEGORIAS_IGNORADAS = [205, 206];

    public function identificador(): string
    {
        return 'apibay';
    }

    public function rotulo(): string
    {
        return 'APIBay';
    }

    /** Provedor público: sempre disponível, sem credencial. */
    public function disponivel(): bool
    {
        return true;
    }

    public function buscar(
        string $titulo,
        ?int $ano = null,
        ?string $imdbId = null,
        ?int $temporada = null,
        ?int $episodio = null,
    ): array {
        // Em episódio o ano sai do termo: o release traz o ano de exibição do
        // episódio, não o da série, e o filtro derrubaria o resultado. O título
        // já chega com a numeração "S01E01" montada pelo TorrentService.
        $episodioDeSerie = $temporada !== null && $episodio !== null;

        /*
         * Em episódio o TorrentService já entrega as variações dubladas prontas
         * ("... S01E01 dublado"). Reanexar a tag aqui geraria "dublado dublado",
         * que não casa com release nenhum — por isso só completamos o termo
         * quando ele ainda não traz a tag.
         */
        if ($episodioDeSerie) {
            $termos = [$titulo];

            if (! TermosBusca::jaEDublado($titulo)) {
                $termos[] = "{$titulo} dublado";
                $termos[] = "{$titulo} dual áudio";
            }
        } else {
            $termos = array_merge(
                [TermosBusca::base($titulo, $ano)],
                // Duas variações de dublagem bastam aqui: o acervo é mundial e o
                // termo extra só existe para puxar as releases brasileiras que já
                // estão indexadas com a tag no nome.
                array_slice(TermosBusca::paraDublado($titulo, $ano), 0, 2),
            );
        }

        $respostas = $this->consultar($termos);

        $itens = [];

        foreach ($respostas as $corpo) {
            foreach ($corpo as $item) {
                if ($this->aproveitavel($item, $imdbId, $episodioDeSerie)) {
                    $itens[$item['info_hash']] = $item;
                }
            }
        }

        $fontes = [];

        foreach ($itens as $item) {
            $nome = $this->limparTexto((string) ($item['name'] ?? ''));
            $hash = strtolower((string) $item['info_hash']);

            /*
             * O APIBay indexa o acervo mundial e a busca por nome devolve
             * temporadas vizinhas junto do episódio pedido. Sem esta peneira, um
             * release "S10E01" poderia entrar na lista de um pedido "S01E01" e,
             * se fosse dublado, subir para o topo da ordenação — o player tentaria
             * a temporada errada antes de chegar à certa.
             */
            if ($episodioDeSerie
                && ! TermosBusca::correspondeAoEpisodio($nome, $temporada, $episodio)) {
                continue;
            }

            /*
             * O APIBay informa seeds reais — o `seeders` vem do rastreador, não de
             * um cache de indexador. Por isso ele **não** usa o piso
             * `SEEDS_NAO_MEDIDOS` que o Knaben, o BT4G e o TrackersBr aplicam: ali
             * o número pode ser impreciso e o piso evita apagar uma fonte viva que
             * só não foi medida. Aqui, zero é zero — a fonte está morta e o corte
             * de seeds da montagem final faz bem em descartá-la. Forçar o piso
             * encheria a lista de releases sem peer nenhum.
             */
            $fontes[] = $this->montarFonte([
                'id' => $hash,
                'titulo' => $nome,
                'magnet' => $this->magnetDoHash($hash, $nome),
                'tamanho_bytes' => (int) ($item['size'] ?? 0),
                'seeds' => (int) ($item['seeders'] ?? 0),
                'peers' => (int) ($item['leechers'] ?? 0),
            ], $this->identificador(), $this->rotulo());
        }

        return $fontes;
    }

    /**
     * Dispara as consultas em paralelo e devolve só os corpos válidos.
     *
     * A busca por título é a etapa mais lenta do fluxo de reprodução; encadear
     * três termos somaria três tempos de rede. O pool resolve as três ao mesmo
     * tempo e cada falha é absorvida individualmente — um termo que dá timeout
     * não derruba os outros.
     *
     * @param  array<int, string>  $termos
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function consultar(array $termos): array
    {
        $base = rtrim((string) config('services.torrents.apibay_url', 'https://apibay.org'), '/');

        /*
         * O teto do pool é o que sobra do orçamento global, limitado ao teto
         * curto do acervo mundial. Sem a leitura do orçamento, o APIBay abria
         * todas as conexões com os 15 s cheios mesmo a segundos do fim da busca —
         * gastava o que restava e voltava para uma rodada já encerrada. O teto
         * curto existe pelo mesmo motivo do BT4G: os dois varrem o acervo mundial
         * e não podem segurar a rodada de abertura sozinhos, senão os provedores
         * por identificador ficam de fora do orçamento.
         */
        $timeout = $this->tempoDeConsulta(
            (int) config('services.torrents.acervo_mundial_tempo_limite', 8)
        );

        if ($timeout <= 0) {
            return [];
        }

        /*
         * O `connectTimeout` é o que faz o teto valer de verdade. O `timeout()`
         * sozinho limita a resposta, mas não a fase de conexão: quando o host
         * está bloqueado, o TCP fica pendurado no handshake e o Guzzle espera
         * muito além do teto. Com o corte na conexão, um host morto falha rápido
         * e a vez volta para quem ainda tem tempo.
         */
        $conexao = max(1, min(3, $timeout));

        $respostas = Http::pool(fn ($pool) => array_map(
            fn (string $termo) => $pool->as(md5($termo))
                ->baseUrl($base)
                ->acceptJson()
                ->connectTimeout($conexao)
                ->timeout($timeout)
                ->get('/q.php', ['q' => $termo]),
            $termos
        ));

        $corpos = [];

        foreach ($termos as $termo) {
            $resposta = $respostas[md5($termo)] ?? null;

            // O pool devolve a exceção no lugar da resposta quando a conexão
            // falha; só seguimos com respostas HTTP de fato.
            if (! $resposta instanceof Response || $resposta->failed()) {
                continue;
            }

            $corpo = $resposta->json();

            if (is_array($corpo)) {
                $corpos[] = $corpo;
            }
        }

        /*
         * O pool inteiro voltou vazio: o APIBay pode estar bloqueado (ele também
         * aparece atrás do Cloudflare em alguns provedores de hospedagem). O
         * socorro é termo a termo pelo FlareSolverr, e só entra quando o caminho
         * direto não trouxe nada — o APIBay costuma responder direto, então o
         * custo do navegador fica reservado para quando ele realmente falha.
         */
        if ($corpos === [] && $this->cliente->proxyDisponivel()) {
            foreach ($termos as $termo) {
                $resposta = $this->cliente->get(
                    $base.'/q.php',
                    ['q' => $termo],
                    null,
                    $timeout,
                );

                if ($resposta === null || $resposta->failed()) {
                    continue;
                }

                $corpo = $resposta->json();

                if (is_array($corpo)) {
                    $corpos[] = $corpo;
                }
            }
        }

        return $corpos;
    }

    /**
     * Decide se o item cru serve como fonte.
     *
     * O APIBay sinaliza "nada encontrado" com um item de nome "No results
     * returned" em vez de um array vazio, e mistura categorias no resultado.
     *
     * A categoria TV (205) é descartada na busca de filme — senão uma série
     * homônima entraria como se fosse o filme. Na busca de episódio é o
     * contrário: a 205 é justamente onde os episódios ficam, então ela passa a
     * ser a única categoria aceita.
     *
     * @param  array<string, mixed>  $item
     */
    private function aproveitavel(array $item, ?string $imdbId, bool $episodioDeSerie = false): bool
    {
        $hash = (string) ($item['info_hash'] ?? '');

        // Só hashes de 40 caracteres (SHA-1) — o resto é lixo de índice.
        if (! preg_match('/^[a-fA-F0-9]{40}$/', $hash)) {
            return false;
        }

        $nome = strtolower((string) ($item['name'] ?? ''));

        if ($nome === '' || str_contains($nome, 'no results returned')) {
            return false;
        }

        $categoria = (int) ($item['category'] ?? 0);

        if ($episodioDeSerie) {
            // Na busca de episódio só a categoria de TV (205) serve. A checagem
            // anterior usava `|| $categoria !== 205`, que é verdadeiro para toda
            // categoria diferente de 205 — inclusive as de vídeo válidas — e
            // acabava aceitando apenas itens com categoria 0. O efeito era o
            // oposto do pretendido: os episódios legítimos (205) eram descartados
            // e sobrava só o que a API devolvia sem categoria preenchida.
            if ($categoria !== 0 && $categoria !== 205) {
                return false;
            }
        } elseif ($categoria !== 0 && ($categoria < 200 || $categoria > 299 || in_array($categoria, self::CATEGORIAS_IGNORADAS, true))) {
            return false;
        }

        /*
         * A API informa o imdb_id do release em alguns casos. Quando temos o
         * nosso e o item traz um diferente, é remake homônimo — descartamos.
         */
        $imdbDoItem = (string) ($item['imdb'] ?? '');

        return ! ($imdbId && $imdbDoItem !== '' && $imdbDoItem !== $imdbId);
    }
}
