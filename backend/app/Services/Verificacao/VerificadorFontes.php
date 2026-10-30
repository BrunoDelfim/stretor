<?php

namespace App\Services\Verificacao;

use App\Enums\OrigemVerificacao;
use App\Enums\TipoMidia;
use App\Models\Disponibilidade;
use App\Models\DisponibilidadeEpisodio;
use App\Services\Torrents\TesteDeFontes;
use App\Services\TorrentService;
use InvalidArgumentException;

/**
 * Prova se um item do catálogo tem fonte.
 *
 * É a menor unidade da verificação: recebe um item normalizado do TMDB, pergunta
 * à cascata de fontes se há algo tocável e grava o veredito em `disponibilidades`.
 * A inteligência de "achar" mora inteira no [`TorrentService`]; aqui só decidimos
 * o que conta como resposta e como registrar o resultado.
 *
 * Para série, a mesma pergunta é feita **por episódio**: o card responde pelo
 * primeiro da primeira temporada (a amostra mais barata), e o modal usa a linha
 * de cada episódio. As duas provas nascem da mesma busca — não há motivo para
 * perguntar duas vezes ao mesmo provedor.
 */
class VerificadorFontes
{
    /**
     * Sonda de episódio para séries.
     *
     * O stream direto — o canal que hospeda os agregadores (Pobreflix, entre
     * outros) — monta a rota do vídeo a partir da ficha da série **mais** a
     * temporada e o episódio. Faltando esses dois números, o agregador só devolve
     * a ficha, nunca a página do episódio, e a série era gravada como "sem fonte"
     * mesmo estando no acervo. Perguntar pelo primeiro episódio da primeira
     * temporada é a amostra mais barata que responde "esta série tem fonte?".
     */
    private const TEMPORADA_SONDA = 1;

    private const EPISODIO_SONDA = 1;

    public function __construct(
        private readonly TorrentService $torrents,
        private readonly ?TesteDeFontes $testeDeFontes = null,
    ) {}

    /**
     * Verifica um item e persiste o veredito.
     *
     * @param  array<string, mixed>  $item  Item no formato normalizado do TMDB.
     *
     * @throws InvalidArgumentException Quando o item não é filme nem série.
     */
    public function verificar(array $item, OrigemVerificacao $origem): Disponibilidade
    {
        $tipo = TipoMidia::doTmdb($item['tipo'] ?? null);
        $tmdbId = (int) ($item['id'] ?? 0);

        // Item sem tipo reconhecido (pessoa, item quebrado) não entra no índice:
        // sem tipo e id não há como identificá-lo de volta depois, e uma linha
        // dessas só serviria para poluir a contagem.
        if ($tipo === null || $tmdbId === 0) {
            throw new InvalidArgumentException('Item sem tipo de mídia reconhecido.');
        }

        // A série é sondada por um episódio concreto — é o que faz o agregador
        // responder; o filme não tem essa dimensão e segue só com o título.
        $sondandoEpisodio = $tipo === TipoMidia::SERIE;

        $resultado = $this->buscar(
            titulo: (string) ($item['titulo'] ?? ''),
            ano: $this->ano($item),
            imdbId: $item['imdb_id'] ?? null,
            tituloOriginal: $item['titulo_original'] ?? null,
            tmdbId: $tmdbId,
            temporada: $sondandoEpisodio ? self::TEMPORADA_SONDA : null,
            episodio: $sondandoEpisodio ? self::EPISODIO_SONDA : null,
        );

        /*
         * A prova do episódio sai do mesmo resultado: a linha do card e a
         * primeira linha do modal não podem discordar, e refazer a busca só para
         * preencher a segunda seria pagar duas vezes pelo mesmo dado. Os
         * episódios seguintes entram pelo comando ou pelo modal.
         */
        if ($tipo === TipoMidia::SERIE) {
            $this->gravarEpisodio($tmdbId, self::TEMPORADA_SONDA, self::EPISODIO_SONDA, $resultado, $origem);
        }

        return Disponibilidade::updateOrCreate(
            [
                'tipo' => $tipo->value,
                'tmdb_id' => $tmdbId,
            ],
            $this->colunasDoVeredito($resultado, $origem),
        );
    }

    /**
     * Verifica um episódio específico e persiste o veredito dele.
     *
     * É o caminho do modal: o usuário abriu a temporada e cada linha precisa
     * dizer se aquele episódio entrega vídeo. Não toca na linha do título — quem
     * responde pelo card é o primeiro episódio, e ele é gravado em `verificar()`.
     *
     * @param  array<string, mixed>  $serie  Item da série no formato do TMDB.
     */
    public function verificarEpisodio(
        array $serie,
        int $temporada,
        int $episodio,
        OrigemVerificacao $origem,
        ?int $tempoTotal = null,
    ): DisponibilidadeEpisodio {
        $tmdbId = (int) ($serie['id'] ?? 0);

        if ($tmdbId === 0 || $temporada < 1 || $episodio < 1) {
            throw new InvalidArgumentException('Episódio sem série, temporada ou numeração.');
        }

        $resultado = $this->buscar(
            titulo: (string) ($serie['titulo'] ?? ''),
            ano: $this->ano($serie),
            imdbId: $serie['imdb_id'] ?? null,
            tituloOriginal: $serie['titulo_original'] ?? null,
            tmdbId: $tmdbId,
            temporada: $temporada,
            episodio: $episodio,
            // A verificação sob demanda do modal roda dentro de uma requisição
            // HTTP, e o cliente desiste em 60 s. Um teto curto por episódio é o
            // que permite testar mais de um antes desse limite — o preço é
            // aceitar que o stream direto, quando lento, fique de fora desta
            // rodada e volte na próxima.
            tempoTotal: $tempoTotal ?? (int) config('services.verificacao.orcamento_episodio', 20),
        );

        return $this->gravarEpisodio($tmdbId, $temporada, $episodio, $resultado, $origem);
    }

    /**
     * A pergunta em si — a única parte que fala com os provedores.
     *
     * Devolve as fontes encontradas e a leitura de "não deu para concluir", que
     * depende do censo da busca, não da lista.
     *
     * @return array{fontes: array<int, array<string, mixed>>, inconclusivo: bool}
     */
    private function buscar(
        string $titulo,
        ?int $ano,
        ?string $imdbId,
        ?string $tituloOriginal,
        int $tmdbId,
        ?int $temporada,
        ?int $episodio,
        ?int $tempoTotal = null,
    ): array {
        $fontes = $this->torrents->fontes(
            titulo: $titulo,
            ano: $ano,
            imdbId: $imdbId,
            tituloOriginal: $tituloOriginal,
            temporada: $temporada,
            episodio: $episodio,
            tmdbId: (string) $tmdbId,
            // O teto cobre o custo real do stream direto — duas páginas (a ficha
            // da série e a do episódio), de 10 a 15 s cada. Um teto curto demais
            // cortava o agregador no meio e gravava "sem fonte" para um título que
            // só o acervo web tinha.
            tempoTotal: $tempoTotal ?? (int) config('services.verificacao.orcamento_titulo', 30),
        );

        /*
         * A malha já filtrou o que não conecta; esta etapa responde outra
         * pergunta — o arquivo **abre**? A prova custa segundos e tráfego real,
         * então roda só na verificação (nunca na busca do player) e só sobre as
         * primeiras fontes da lista.
         *
         * Reprovada de forma definitiva, a lista cai para vazio e "sem fonte"
         * passa a significar "nada aqui toca". Se a prova for inconclusiva
         * (timeout, 403 de anti-bot), a lista fica como está: dúvida não é
         * prova de morte.
         */
        $prova = $this->testeDeFontes?->provarMelhor($fontes, $temporada, $episodio);

        if ($prova === false) {
            $fontes = [];
        }

        /*
         * Lista vazia nem sempre é veredito. Quando nenhum provedor estava
         * utilizável, o orçamento cortou a cascata no meio ou um provedor quebrou,
         * o "sem fonte" fala da busca — não do acervo. A marca `inconclusivo`
         * registra isso: a linha continua candidata, em vez de travar com uma
         * prova que não houve. Só lemos o censo no caminho vazio; com fonte na
         * mão, não há o que relativizar.
         *
         * A reprovação pela prova de bytes é veredito, não dúvida: aí o censo
         * não entra, porque a fonte foi medida e recusou.
         */
        return [
            'fontes' => $fontes,
            'inconclusivo' => $fontes === [] && $prova !== false && $this->torrents->buscaInconclusiva(),
        ];
    }

    /**
     * As colunas comuns aos dois índices, a partir de um resultado de busca.
     *
     * @param  array{fontes: array<int, array<string, mixed>>, inconclusivo: bool}  $resultado
     * @return array<string, mixed>
     */
    private function colunasDoVeredito(array $resultado, OrigemVerificacao $origem): array
    {
        // A lista já vem ordenada, com a PT-BR na frente. Guardamos o perfil da
        // melhor fonte para o status e para entender de onde veio a prova.
        $principal = $resultado['fontes'][0] ?? null;

        return [
            'tem_fonte' => $resultado['fontes'] !== [],
            'inconclusivo' => $resultado['inconclusivo'],
            'deve_retentar' => $resultado['inconclusivo'],
            'fonte_idioma' => $principal['idioma'] ?? null,
            'fonte_tipo' => $principal['tipo'] ?? null,
            'origem' => $origem->value,
            'verificado_em' => now(),
        ];
    }

    /**
     * Grava (ou atualiza) a prova de um episódio.
     *
     * @param  array{fontes: array<int, array<string, mixed>>, inconclusivo: bool}  $resultado
     */
    private function gravarEpisodio(
        int $tmdbId,
        int $temporada,
        int $episodio,
        array $resultado,
        OrigemVerificacao $origem,
    ): DisponibilidadeEpisodio {
        return DisponibilidadeEpisodio::updateOrCreate(
            [
                'tmdb_id' => $tmdbId,
                'temporada' => $temporada,
                'episodio' => $episodio,
            ],
            $this->colunasDoVeredito($resultado, $origem),
        );
    }

    /** O ano vem como inteiro ou string, a depender de onde o item foi montado. */
    private function ano(array $item): ?int
    {
        return is_numeric($item['ano'] ?? null) ? (int) $item['ano'] : null;
    }
}
