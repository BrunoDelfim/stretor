<?php

namespace App\Services\Torrents;

use App\Contracts\ProvedorTorrents;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
 *    emojis separando os valores. A leitura desse rótulo é do protocolo Stremio,
 *    então vive na trait [`LeituraStreamStremio`], compartilhada com o provedor
 *    dos demais addons hospedados — aqui fica só o que é específico do Torrentio.
 */
class ProvedorTorrentio implements ProvedorTorrents
{
    use ConsultaComOrcamento;
    use NormalizaFonte;
    use LeituraStreamStremio;

    public function __construct(
        private readonly OrcamentoBusca $orcamento,
    ) {}

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

    public function buscar(
        string $titulo,
        ?int $ano = null,
        ?string $imdbId = null,
        ?int $temporada = null,
        ?int $episodio = null,
    ): array {
        // Sem o identificador do IMDb não há como consultar: o Torrentio não
        // aceita busca por nome.
        if (empty($imdbId) || ! str_starts_with($imdbId, 'tt')) {
            return [];
        }

        $base = rtrim((string) config('services.torrents.torrentio_url', 'https://torrentio.strem.fun'), '/');

        /*
         * O teto é o que sobra do orçamento global. O Torrentio abre a cascata,
         * então normalmente tem o orçamento inteiro à disposição — mas quando ele
         * é reconsultado (outro termo, outra abertura) o prazo pode já estar no
         * fim, e insistir com os 15 s cheios só gastaria o tempo que a montagem
         * final não espera.
         */
        $timeout = $this->tempoDeConsulta((int) config('services.torrents.tempo_limite', 15));

        if ($timeout <= 0) {
            return [];
        }

        /*
         * O Torrentio aceita uma configuração embutida na própria URL, no
         * segmento que antecede `/stream`. Sem ela, ele responde com o catálogo
         * padrão — quase todo em inglês —, e era por isso que uma série só
         * trazia fontes "Idioma original" mesmo com o resto da cascata saudável.
         *
         * Com `language=portuguese`, o Torrentio passa a incluir os provedores
         * que publicam releases nacionais (Comando, BluDV, ThePirateBay com
         * faixa PT) e devolve os lançamentos "Dublado"/"Dual Áudio"/"PORTUGUÊS
         * BR" que faltavam. A lista de idiomas fica na config para poder ser
         * ampliada sem mexer no código.
         */
        $idiomas = trim((string) config('services.torrents.torrentio_idiomas', 'portuguese'));
        $configuracao = $idiomas !== '' ? 'language='.rawurlencode($idiomas).'/' : '';

        // O Torrentio tem dois caminhos distintos, e usar o errado devolve o
        // conteúdo errado: `/stream/movie/{id}` responde pelo catálogo de filmes
        // do identificador, e `/stream/series/{id}:{temporada}:{episodio}`
        // responde pelo episódio. Como o TMDB reaproveita o mesmo `imdb_id` para
        // a série e para um filme homônimo, consultar uma série pelo caminho de
        // filme devolvia o filme — foi exatamente o que aconteceu com "American
        // Horror Story", que trouxe "M. Butterfly (1993)".
        // A rota depende do tipo: `/stream/movie/{id}` responde pelo catálogo de
        // filmes do identificador, e `/stream/series/{id}:{temporada}:{episodio}`
        // responde pelo episódio. Como o TMDB reaproveita o mesmo `imdb_id` para a
        // série e para um filme homônimo, consultar uma série pelo caminho de
        // filme devolvia o filme — foi exatamente o que aconteceu com "American
        // Horror Story", que trouxe "M. Butterfly (1993)".
        $rota = ($temporada !== null && $episodio !== null)
            ? "stream/series/{$imdbId}:{$temporada}:{$episodio}.json"
            : "stream/movie/{$imdbId}.json";

        /*
         * A URL é montada inteira em vez de usar `baseUrl()` + caminho: o
         * `baseUrl()` do Laravel descarta o caminho do host quando o caminho
         * passado começa com "/", e o segmento de configuração
         * (`language=portuguese/`) é justamente parte do caminho. Montando a URL
         * completa garantimos que o filtro de idioma chegue ao Torrentio.
         */

        // Consulta principal, com o filtro de idioma: é ela que traz os provedores
        // nacionais e mantém o comportamento de sempre, para filme e para série.
        $fontes = $this->consultar($base, $configuracao.$rota, $titulo, $temporada, $episodio, $timeout);

        /*
         * Segunda consulta, **sem** o filtro de idioma, e só para série.
         *
         * O `language=portuguese` é um corte na origem: o Torrentio só devolve o
         * stream que ele próprio já classificou como português. Para o pack de
         * temporada isso é um problema — muitos vêm rotulados de um jeito que o
         * filtro não reconhece ("multi áudio" mal grafado, "legendado") e o pack
         * some antes de o nosso parser olhar o nome do release, que é quem decide
         * o idioma de fato. Repetir a mesma rota sem o segmento de configuração
         * amplia o leque e entrega ao parser interno o que o filtro descartaria.
         *
         * Filme fica de fora: o catálogo de filme é bem servido pelo filtro e a
         * consulta extra só encheria a lista de originais em inglês. A chave na
         * config permite desligar a segunda consulta sem reverter código.
         */
        if ($temporada !== null && $episodio !== null
            && $configuracao !== ''
            && config('services.torrents.torrentio_busca_ampla', true)) {
            $restante = $this->tempoDeConsulta((int) config('services.torrents.tempo_limite', 15));

            if ($restante > 0) {
                foreach ($this->consultar($base, $rota, $titulo, $temporada, $episodio, $restante) as $id => $fonte) {
                    // A consulta filtrada tem prioridade: é a leitura com o idioma
                    // mais provável. A ampla só acrescenta o que ainda faltava.
                    $fontes[$id] ??= $fonte;
                }
            }
        }

        return array_values($fontes);
    }

    /**
     * Executa uma consulta ao Torrentio e devolve as fontes já normalizadas.
     *
     * As duas consultas da busca — a filtrada por idioma e a ampla — só divergem
     * no segmento de configuração embutido na URL; montagem, leitura do rótulo e o
     * corte de numeração são idênticos. Este helper evita duplicar o trecho.
     *
     * @return array<string, array<string, mixed>> Fontes indexadas pelo infohash.
     */
    private function consultar(
        string $base,
        string $caminho,
        string $titulo,
        ?int $temporada,
        ?int $episodio,
        int $timeout,
    ): array {
        try {
            /*
             * O `connectTimeout` corta a fase de conexão, que o `timeout()` não
             * cobre: com o host bloqueado, o TCP fica pendurado no handshake e o
             * Guzzle espera muito além do teto. Com o corte, um host morto falha
             * rápido e a vez volta para quem ainda tem tempo.
             */
            $resposta = Http::acceptJson()
                ->connectTimeout(max(1, min(3, $timeout)))
                ->timeout($timeout)
                ->get($base.'/'.$caminho);
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

            if ($fonte === null) {
                continue;
            }

            /*
             * O Torrentio responde pela série inteira e mistura temporadas na
             * mesma lista: pedir o S01E01 de "American Horror Story" devolveu um
             * release "S10E01" em dual áudio. Como o dublado sobe para o topo da
             * ordenação, essa fonte de outra temporada era a primeira tentada
             * pelo player — e só depois de ela falhar o fluxo chegava à original
             * correta. Descartamos aqui o que declara numeração diferente da
             * pedida; releases sem numeração passam, para não apagar packs e
             * nomes nacionais legítimos.
             */
            if ($temporada !== null && $episodio !== null
                && ! TermosBusca::correspondeAoEpisodio($fonte['titulo'], $temporada, $episodio)) {
                continue;
            }

            /*
             * Diagnóstico do idioma. Registra o que o nome do release declarou e
             * como ele foi classificado, para confirmar pelo log que a
             * classificação deixou de ler o rótulo global do Torrentio — o
             * release americano do EZTV tem de sair como "original", não como
             * "dublado".
             */
            if (config('app.debug')) {
                Log::debug('[torrentio] idioma da fonte', [
                    'titulo' => $fonte['titulo'],
                    'idioma' => $fonte['idioma'],
                ]);
            }

            $fontes[$fonte['id']] = $fonte;
        }

        return $fontes;
    }
}
