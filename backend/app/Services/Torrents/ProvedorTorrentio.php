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
        $timeout = (int) config('services.torrents.tempo_limite', 15);

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
        $caminho = ($temporada !== null && $episodio !== null)
            ? "{$configuracao}stream/series/{$imdbId}:{$temporada}:{$episodio}.json"
            : "{$configuracao}stream/movie/{$imdbId}.json";

        try {
            /*
             * A URL é montada inteira em vez de usar `baseUrl()` + caminho: o
             * `baseUrl()` do Laravel descarta o caminho do host quando o caminho
             * passado começa com "/", e o segmento de configuração
             * (`language=portuguese/`) é justamente parte do caminho. Montando a
             * URL completa garantimos que o filtro de idioma chegue ao Torrentio.
             */
            $resposta = Http::acceptJson()
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

            $fontes[] = $fonte;
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

        /*
         * O nome do release vive na primeira linha do rótulo, não no campo
         * `name` do stream. O `name` traz apenas o provedor e a qualidade
         * ("Torrentio\n720p"), então usá-lo como título apagava a numeração
         * ("S01E01") e as tags de idioma ("PORTUGUÊS BR", "DUAL") — era por isso
         * que a validação de episódio e a classificação de idioma falhavam e
         * todas as fontes do Torrentio eram descartadas.
         *
         * O `filename` do `behaviorHints`, quando existe, é o nome do arquivo
         * dentro do torrent e é a fonte mais confiável; o rótulo cobre os casos
         * em que ele não vem (a maioria dos streams nacionais).
         */
        $nome = (string) ($stream['behaviorHints']['filename'] ?? '')
            ?: $this->nomeDoRotulo($rotulo)
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
            'idioma' => $this->idiomaDoNome($nome),
        ], $this->identificador(), $this->rotulo());
    }

    /**
     * Extrai o nome do release da primeira linha do rótulo do Torrentio.
     *
     * O rótulo vem em várias linhas: a primeira é o nome do release, a segunda
     * traz os metadados ("👤 0 💾 1.62 GB ⚙️ ThePirateBay") e as seguintes as
     * faixas de áudio e bandeiras. Devolve vazio quando a primeira linha é só o
     * nome do provedor (ex.: "Torrentio"), para não repetir o que o `name` já dá.
     */
    private function nomeDoRotulo(string $rotulo): string
    {
        $linhas = preg_split('/\r\n|\r|\n/', trim($rotulo)) ?: [];

        $primeira = trim((string) ($linhas[0] ?? ''));

        if ($primeira === '' || stripos($primeira, 'torrentio') === 0) {
            return '';
        }

        return $primeira;
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
     * O piso é 1, nunca 0. O Torrentio usa `👤 0` tanto para "não há peers" quanto
     * para "não consegui medir" — e o segundo caso é o mais comum em releases
     * nacionais, que ele indexa sem passar pelo rastreador de peers. Como o
     * catálogo descarta toda fonte com 0 seeds, tratar esse 0 como definitivo
     * apagava dubladas legítimas: foi o que aconteceu com o release
     * "Dual Áudio 720p By-LuanHarper" de "Grey's Anatomy", que vinha com `👤 0` e
     * era removido antes de chegar à interface. Quem confirma se a fonte vive é o
     * media-service, que mede os peers na prática antes de abrir a reprodução.
     */
    private function seedsDoRotulo(string $rotulo): int
    {
        if (preg_match('/👤\s*(\d+)/u', $rotulo, $achados)) {
            return max(1, (int) $achados[1]);
        }

        if (preg_match('/(\d+)\s*(?:seeds|seeders)/i', $rotulo, $achados)) {
            return max(1, (int) $achados[1]);
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
     * Deduz o idioma pelo **nome do release**, nunca pelo rótulo do Torrentio.
     *
     * A busca vai configurada com `language=portuguese` para o Torrentio incluir
     * os provedores nacionais, mas esse filtro faz o indexador anotar a resposta
     * inteira com a bandeira de português. Ler o rótulo completo classificava
     * *todas* as fontes como "Dublado" — inclusive um release americano do EZTV
     * ou um WEB-DL "ENG/ITA" — e, como o idioma do provedor tem precedência no
     * `NormalizaFonte::montarFonte()`, a tag do próprio nome do arquivo nunca era
     * consultada. O player então tocava em inglês uma fonte prometida como
     * dublada, que foi exatamente o caso do "Lanterns".
     *
     * A bandeira e o texto do rótulo são promessa do indexador; o nome do release
     * é o que a fonte de fato declara. Por isso só olhamos para ele aqui — e o
     * que ele não provar, o `IdiomaFonte::deduzirDoTitulo()` decide a partir das
     * tags do próprio nome ("DUAL", "DUBLADO", "PT-BR", "NACIONAL").
     *
     * O valor devolvido é o código que `IdiomaFonte::deduzirDoIdioma()` entende
     * ("portuguese"); devolver vazio deixa a classificação para a tag do nome.
     */
    private function idiomaDoNome(string $nome): string
    {
        if (stripos($nome, 'brazil') !== false
            || stripos($nome, 'portuguese') !== false
            || stripos($nome, 'português') !== false
            || stripos($nome, 'portugues') !== false) {
            return 'portuguese';
        }

        return '';
    }
}
