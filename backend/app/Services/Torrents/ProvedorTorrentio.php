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
    use NormalizaFonte;
    use LeituraStreamStremio;

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
}
