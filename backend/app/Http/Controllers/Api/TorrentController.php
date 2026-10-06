<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TmdbService;
use App\Services\TorrentService;
use App\Support\MensagensTorrent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Orquestra a busca de fontes de torrent para um filme.
 *
 * O controller só junta as pontas: pega os metadados do filme no catálogo
 * (título, título original, ano e imdb_id) e delega a busca ao TorrentService.
 * Nenhuma regra de negócio mora aqui.
 */
class TorrentController extends Controller
{
    public function __construct(
        private readonly TmdbService $tmdb,
        private readonly TorrentService $torrents,
    ) {
    }

    /**
     * Fontes de torrent disponíveis para o filme informado.
     *
     * O frontend usa esta lista para percorrer as fontes em ordem de prioridade
     * até encontrar uma que conecte — por isso a resposta já vem ordenada, com o
     * dublado em PT-BR na frente.
     *
     * A query string é o plano B de quando o catálogo está fora do ar ou sem
     * chave: o frontend já tem a ficha do filme em mãos e manda título, ano e
     * imdb_id junto, então a busca de fontes continua funcionando sem o TMDB
     * responder. Sem catálogo e sem esses dados não há como procurar nada, e o
     * aviso passa a apontar para a chave.
     */
    public function fontes(Request $requisicao, int $id): JsonResponse
    {
        $titulo = $this->texto($requisicao->query('titulo'));
        $tituloOriginal = $this->texto($requisicao->query('titulo_original'));
        $imdbId = $this->texto($requisicao->query('imdb_id'));
        $ano = $requisicao->query('ano');
        // Quando presentes, indicam que a busca é de um episódio de série. O
        // fluxo de filme simplesmente não envia esses parâmetros.
        $temporada = $requisicao->query('temporada');
        $episodio = $requisicao->query('episodio');

        $aviso = null;

        try {
            /*
             * A ficha precisa vir do catálogo certo. O TMDB reaproveita o mesmo
             * `imdb_id` para uma série e para um filme homônimo, então pedir
             * `/movie/{id}` com o id de uma série devolve o filme errado — foi
             * assim que "American Horror Story" (1413) chegou aqui como
             * "M. Butterfly". Quando a busca é de episódio, os metadados vêm de
             * `/tv/{id}`; só o fluxo de filme usa `/movie/{id}`.
             */
            $filme = ($temporada !== null && $episodio !== null)
                ? $this->tmdb->detalhesSerie($id)
                : $this->tmdb->detalhes($id);

            $titulo ??= $this->texto($filme['titulo'] ?? null);
            $tituloOriginal ??= $this->texto($filme['titulo_original'] ?? null);
            $imdbId ??= $this->texto($filme['imdb_id'] ?? null);
            $ano ??= $filme['ano'] ?? null;
        } catch (RuntimeException $excecao) {
            if ($titulo === null && $imdbId === null) {
                return response()->json([
                    'message' => MensagensTorrent::AVISO_SEM_CATALOGO,
                ], 502);
            }

            /*
             * Catálogo indisponível, mas com dados suficientes vindos da query:
             * segue a busca e devolve o aviso junto do resultado, para o frontend
             * poder explicar por que a ficha veio incompleta. A exceção continua
             * indo para o log — o usuário não precisa dela, o desenvolvedor sim.
             */
            $aviso = MensagensTorrent::AVISO_SEM_CATALOGO;

            report($excecao);
        }

        $ano = is_numeric($ano) ? (int) $ano : null;
        $temporada = is_numeric($temporada) ? (int) $temporada : null;
        $episodio = is_numeric($episodio) ? (int) $episodio : null;

        $fontes = $this->torrents->fontes(
            titulo: (string) ($titulo ?? ''),
            ano: $ano,
            imdbId: $imdbId,
            tituloOriginal: $tituloOriginal,
            temporada: $temporada,
            episodio: $episodio,
            // O id da rota é o do TMDB: além de puxar a ficha, ele alimenta a
            // fonte endereçável por id (o passo zero do stream direto), que
            // dispensa a busca por título.
            tmdbId: (string) $id,
        );

        return response()->json([
            'data' => [
                'filme_id' => $id,
                'titulo' => $titulo,
                'titulo_original' => $tituloOriginal,
                'ano' => $ano,
                'temporada' => $temporada,
                'episodio' => $episodio,
                'fontes' => $fontes,
                // O censo é lido aqui, ainda na mesma busca que acabou de rodar,
                // para provar quais provedores foram consultados e o que cada um
                // devolveu — inclusive os que a cascata nem chegou a tocar.
                'cobertura' => $this->torrents->cobertura(),
                'mensagem' => $aviso ?? $this->mensagemDeListaVazia($fontes),
            ],
        ]);
    }

    /**
     * Escolhe o aviso quando a lista de fontes veio vazia.
     *
     * Com o corte duro de idioma ligado, a lista vazia quase sempre diz uma coisa
     * só: o título existe, mas não em português — o acervo web devolveu link sem
     * áudio PT-BR ou nada, os trackers não tinham release dublado, e o corte
     * descartou o resto. É o texto que o usuário lê no overlay depois de a busca
     * ter percorrido os dois canais, e ele é escrito para ele, não para o log.
     *
     * A outra mensagem (provedor indisponível) é diagnóstico de configuração
     * (chave faltando, serviço fora do ar) e pede uma ação bem diferente de
     * "espere sair a dublagem". Quem decide entre as duas é o censo: sem provedor
     * disponível, culpar o idioma seria mentir sobre o motivo.
     *
     * @param  array<int, array<string, mixed>>  $fontes
     */
    private function mensagemDeListaVazia(array $fontes): ?string
    {
        if ($fontes !== []) {
            return null;
        }

        return $this->torrents->temProvedorDisponivel()
            ? MensagensTorrent::SEM_FONTES
            : MensagensTorrent::AVISO_SEM_PROVEDOR;
    }

    /** Normaliza um parâmetro de texto opcional, devolvendo null quando vazio. */
    private function texto(mixed $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
}
