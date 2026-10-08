<?php

namespace App\Http\Controllers\Api;

use App\Enums\IdiomaFonte;
use App\Enums\TipoMidia;
use App\Http\Controllers\Controller;
use App\Services\FontesReproduzidas;
use App\Services\TmdbService;
use App\Services\TorrentService;
use App\Support\MensagensTorrent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        private readonly FontesReproduzidas $reproduzidas,
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
     * Registra a fonte que reproduziu o título inteiro.
     *
     * Quem afirma isso é o player, não a busca: só ele sabe que o arquivo
     * atravessou o filme (ou o episódio) sem travar. O relato vira histórico, e a
     * próxima busca devolve a mesma fonte na frente da fila — é o que evita pagar
     * de novo todo o tentativa-e-erro num título que já tinha solução.
     *
     * O controller não julga a fonte — não há como conferir de trás para frente
     * que o vídeo tocou. O que ele confere é a **forma** do relato: um magnet que
     * não é magnet ou um stream que não é URL envenenaria o histórico com uma
     * entrada que nunca conecta, e a fonte comprovada inválida acabaria na frente
     * de todas, atrasando a reprodução de quem abriu o título.
     */
    public function fonteReproduzida(Request $requisicao, int $id): JsonResponse
    {
        $dados = $requisicao->validate([
            // O id da fonte é o infohash do magnet ou o md5 da URL (fonte direta):
            // só caracteres de hash entram, para a chave do índice ser previsível.
            'fonte_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9]{16,40}$/'],
            'tipo' => ['required', 'string', Rule::in(['torrent', 'direto'])],
            'titulo' => ['nullable', 'string', 'max:255'],
            'qualidade' => ['nullable', 'string', 'max:30'],
            'tamanho' => ['nullable', 'string', 'max:30'],
            // Sem idioma reconhecido a fonte seria exibida como "idioma original",
            // então vale recusar o que não é idioma do nosso vocabulário.
            'idioma' => ['nullable', 'string', Rule::in(array_column(IdiomaFonte::cases(), 'value'))],
            'provedor' => ['nullable', 'string', 'max:60'],
            'provedor_rotulo' => ['nullable', 'string', 'max:120'],
            'seeds' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'peers' => ['nullable', 'integer', 'min:0', 'max:100000'],
            // O torrent precisa vir com o magnet que o media-service vai abrir; a
            // regex exige o `btih` porque é dele que sai o infohash.
            'magnet' => [
                'nullable', 'string', 'max:2000', 'required_if:tipo,torrent',
                'regex:/^magnet:\?xt=urn:btih:[A-Za-z0-9]{32,40}/i',
            ],
            'stream' => ['nullable', 'string', 'max:2000', 'required_if:tipo,direto', 'url:http,https'],
            // Legendas com que a fonte tocou. São elas que permitem reoferecer uma
            // fonte legendada sem tocar o áudio original em silêncio.
            'legendas' => ['nullable', 'array', 'max:8'],
            'legendas.*.url' => ['required', 'string', 'max:1000', 'url:http,https'],
            'legendas.*.srclang' => ['required', 'string', 'max:12'],
            'legendas.*.label' => ['nullable', 'string', 'max:60'],
            'temporada' => ['nullable', 'integer', 'min:1', 'max:999', 'required_with:episodio'],
            'episodio' => ['nullable', 'integer', 'min:1', 'max:9999', 'required_with:temporada'],
        ]);

        $temporada = (int) ($dados['temporada'] ?? 0);
        $episodio = (int) ($dados['episodio'] ?? 0);

        // A numeração é o que distingue um episódio de um filme, como no resto do
        // fluxo: sem ela, a comprovada deste episódio não seria achada de volta.
        $tipo = $temporada > 0 ? TipoMidia::SERIE : TipoMidia::FILME;

        $registrada = $this->reproduzidas->registrar($dados, $tipo, $id, $temporada, $episodio);

        return response()->json([
            'data' => [
                'registrada' => $registrada !== null,
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
