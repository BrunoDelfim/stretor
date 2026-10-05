<?php

namespace App\Services\Torrents;

use App\Contracts\ProvedorPorLote;
use App\Contracts\ProvedorTorrents;
use App\Enums\IdiomaFonte;
use App\Services\Torrents\TermosBusca;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Registro e cascata dos provedores de torrent.
 *
 * O sistema tem vários caminhos para achar um filme, em três degraus:
 *
 * 1. **Busca nativa no backend** (principal) — [`ProvedorTrackersBr`],
 *    [`ProvedorApibay`], [`ProvedorKnaben`] e [`ProvedorBt4g`] por nome, mais
 *    [`ProvedorTorrentio`] e [`ProvedorAddonStremio`] por identificador. É aqui
 *    que o sistema faz por conta própria o que antes era delegado ao Prowlarr.
 * 2. **Indexador Torznab/Prowlarr** ([`ProvedorTorznab`]) — o socorro quando a
 *    busca nativa não devolveu fonte dublada. Ele agrega os mesmos trackers, com
 *    um raspador mantido por terceiros, o que cobre o caso de o nosso HTML
 *    parser ficar para trás.
 * 3. **YTS** ([`ProvedorYts`]) — a rede de segurança em inglês, quando nem o
 *    indexador achou algo em PT-BR.
 *
 * A cascata só avança de degrau quando o degrau atual **não juntou fontes PT-BR
 * suficientes** (`meta_pt_br_coleta`). Um filme que tem release nacional nunca
 * chega a consultar o YTS, e o contrário também vale: sem chave do indexador, a
 * busca nativa segue funcionando e o fluxo não para.
 *
 * Cada provedor é chamado isolado e cacheado individualmente. O cache é por
 * provedor (e não do resultado final) para que a queda de um site não invalide o
 * trabalho dos outros, e para que acrescentar um provedor novo não exija mexer
 * na chave de cache de ninguém.
 */
class CatalogoProvedores
{
    /**
     * Sal de versão da chave de cache dos provedores.
     *
     * Sem ele, "corrigi o código" e "não fiz nada" ficam indistinguíveis por até
     * um TTL inteiro: a chave é a mesma antes e depois da correção, então o
     * resultado antigo — inclusive os zeros falsos que a rota errada do Torznab
     * produzia — continua sendo servido. Suba este número junto com qualquer
     * mudança de comportamento de um provedor para a reconsulta valer já na
     * próxima busca, sem depender de limpar o Redis à mão.
     */
    private const VERSAO_CACHE = 13;

    /**
     * Provedores do primeiro degrau que buscam **por nome**.
     *
     * A ordem dentro do degrau é a ordem de consulta: o tracker PT-BR vem
     * primeiro porque é o único que existe *por causa* do dublado; o APIBay
     * amplia o alcance; o Knaben varre os indexadores que o APIBay não cobre (é
     * onde costumam aparecer os packs nacionais das séries antigas); o BT4G fecha
     * com o acervo de DHT.
     *
     * Ficam de fora desta lista, de propósito, os provedores por identificador
     * (imdb_id) — [`ProvedorTorrentio`] e [`ProvedorAddonStremio`]: eles ignoram
     * o termo de busca, então consultá-los por variação de termo seriam quatro
     * requisições idênticas (o termo de episódio rende o puro + três dubladas),
     * todas devolvendo o mesmo resultado. Eles vivem em `$porIdentificador` e são
     * chamados uma única vez.
     *
     * @var array<int, ProvedorTorrents>
     */
    private array $primarios;

    /**
     * Provedores que buscam por identificador (imdb_id).
     *
     * São consultados uma única vez, com o termo puro, porque o termo não
     * influencia a resposta — só o identificador importa. Reúne o
     * [`ProvedorTorrentio`] e o [`ProvedorAddonStremio`] (os demais addons
     * Stremio hospedados).
     *
     * @var array<int, ProvedorTorrents>
     */
    private array $porIdentificador;

    /**
     * Quantas fontes o gate de temporada barrou na última consulta a um grupo.
     *
     * O gate é o único ponto que descarta uma fonte sem que ela apareça em
     * lugar nenhum. Sem este contador, "0 fontes" fica indistinguível entre "o
     * provedor não tinha nada" e "o termo de série trouxe, mas nada provou a
     * temporada". Zerado a cada `buscarGrupo()` e lido pelo `registrarEtapa()`.
     */
    private int $barradasPeloGate = 0;

    /**
     * Quantas inspeções de conteúdo de pack a busca atual já disparou.
     *
     * Cada inspeção é uma conexão e uma espera no media-service, então a busca
     * não pode abrir todos os packs que encontrar. O teto (`inspecao_packs_limite`)
     * vale por busca e é zerado no início de `buscar()`.
     */
    private int $inspecoes = 0;

    /**
     * Ids (infohash) dos packs já inspecionados nesta busca.
     *
     * O mesmo pack reaparece em termos e degraus diferentes (o Torrentio e o
     * indexador costumam devolver os mesmos lançamentos). Sem este conjunto, o
     * orçamento de inspeções seria gasto reabrindo o que já foi julgado.
     *
     * @var array<string, bool>
     */
    private array $packsInspecionados = [];

    /**
     * Todos os provedores registrados, na ordem em que a cascata os consulta.
     *
     * O relatório de cobertura precisa listar o registro **completo**, e não só o
     * que foi tocado: um provedor nunca consultado só aparece como ausência se
     * houver uma lista de referência para comparar. É esta lista que transforma
     * "não veio nada do Prowlarr" em "o Prowlarr não foi perguntado".
     *
     * @var array<int, ProvedorTorrents>
     */
    private array $registro;

    /**
     * Censo da busca atual: o que cada provedor respondeu.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $censo = [];

    /**
     * Ids de fonte já contabilizados no censo.
     *
     * O mesmo torrent reaparece em vários termos e degraus; sem este conjunto, um
     * provedor que respondeu uma vez apareceria contando quatro.
     *
     * @var array<string, bool>
     */
    private array $fontesNoCenso = [];

    /**
     * Provedores de lote que já responderam o degrau inteiro nesta passagem.
     *
     * Um provedor que aceita vários termos numa rodada é consultado uma única vez,
     * com todos os termos do degrau, e depois sai do laço: repetir a chamada nos
     * termos seguintes seria perguntar de novo o que ele já respondeu. Zerado a
     * cada degrau, porque o lote é por degrau — o indexador não herda o do nativo.
     *
     * @var array<string, bool>
     */
    private array $loteConsumido = [];

    /**
     * Evita repetir o aviso de prazo estourado a cada termo.
     */
    private bool $avisouPrazo = false;

    /**
     * Diz se foi este canal que abriu o orçamento da busca.
     *
     * O orçamento é um singleton compartilhado entre os canais, mas só quem o
     * abriu deve fechá-lo. Sem esta posse explícita, o stream direto — que roda
     * primeiro e abre o canal dele — fecharia o relógio ao terminar e deixaria a
     * cascata de torrents, que entra logo depois, sem prazo nenhum.
     */
    private bool $orcamentoAbertoAqui = false;

    /**
     * Provedor de stream direto — o scraper web, primeiro método da busca.
     *
     * Fica fora dos degraus de propósito: não é consultado junto com os torrents,
     * e sim acionado à parte, antes da cascata. Guardado como propriedade (e não
     * só no registro) porque o método de indexação precisa chamá-lo diretamente,
     * sem passar pelo laço de grupos.
     */
    private ProvedorStreamDireto $streamDireto;

    public function __construct(
        ProvedorTrackersBr $trackersBr,
        ProvedorApibay $apibay,
        ProvedorKnaben $knaben,
        ProvedorTorrentio $torrentio,
        ProvedorAddonStremio $addonStremio,
        ProvedorBt4g $bt4g,
        private readonly ProvedorTorznab $torznab,
        private readonly ProvedorYts $yts,
        private readonly InspecaoPack $inspecao,
        private readonly OrcamentoBusca $orcamento,
        ProvedorStreamDireto $streamDireto,
    ) {
        $this->primarios = [$trackersBr, $apibay, $knaben, $bt4g];
        $this->porIdentificador = [$torrentio, $addonStremio];
        $this->streamDireto = $streamDireto;

        // A ordem do relatório é a ordem da cascata: os por identificador abrem, os
        // por nome seguem, o indexador com o YTS fecham os degraus e o stream direto
        // fica por último. Ele não é um degrau: é o outro método de indexação, que
        // roda à parte — sempre, e antes da cascata — e por isso fecha o relatório,
        // com o `agregadores` detalhando por baixo cada site consultado.
        $this->registro = [
            ...$this->porIdentificador,
            ...$this->primarios,
            $this->torznab,
            $this->yts,
            $this->streamDireto,
        ];

        $this->reiniciarCenso();
    }

    /**
     * Percorre a cascata e devolve todas as fontes encontradas, sem repetir.
     *
     * @param  array<int, string>  $titulos  Títulos candidatos, em ordem de
     *                                       preferência (traduzido e original)
     * @param  int|null  $temporada  Temporada do episódio, quando a busca é de série
     * @param  int|null  $episodio   Episódio procurado, quando a busca é de série
     * @return array<int, array<string, mixed>>
     */
    public function buscar(
        array $titulos,
        ?int $ano,
        ?string $imdbId,
        ?int $temporada = null,
        ?int $episodio = null,
    ): array {
        $fontes = [];

        /*
         * Contadores da busca atual. O de inspeções limita quantos packs abrimos
         * (cada abertura é uma conexão e uma espera) e o conjunto evita reabrir o
         * mesmo pack quando ele reaparece em outro termo ou em outro degrau.
         */
        $this->inspecoes = 0;
        $this->packsInspecionados = [];
        $this->reiniciarCenso();

        /*
         * O orçamento da busca inteira é aberto aqui, no primeiro passo. Cada
         * provedor tem o seu teto, mas a soma deles não tinha: um tracker
         * bloqueado gasta o `tempo_limite` na tentativa direta e mais o
         * `proxy_nativo_timeout` no FlareSolverr, e isso se repete por termo. Com
         * o orçamento global, a cascata para de perguntar quando o prazo acaba e
         * entrega o que já recolheu — em vez de estourar o tempo do frontend e
         * não entregar nada.
         *
         * O relógio é compartilhado com o [`ClienteHttp`] (mesmo singleton), para
         * que o socorro pelo FlareSolverr também encolha junto com o prazo.
         *
         * O canal é fixado em `torrents` antes de abrir: a cascata roda depois do
         * stream direto, na mesma busca, e sem esta fixação ela leria o prazo do
         * canal errado — o do stream direto, dimensionado para o FlareSolverr.
         */
        $this->orcamento->usarCanal(OrcamentoBusca::CANAL_PADRAO);

        $this->orcamentoAbertoAqui = $this->orcamento->abrirSeFechado(
            (int) config('services.torrents.orcamento_busca', 45),
            OrcamentoBusca::CANAL_PADRAO
        );
        $this->avisouPrazo = false;

        /*
         * Provedores que aceitam vários termos numa rodada são consultados uma
         * única vez, com o degrau inteiro, e depois saem do laço. Sem isto, o
         * Knaben pagava um POST por termo e a soma dominava a espera.
         */
        $this->loteConsumido = [];

        /*
         * O primeiro termo é a rodada de abertura, e ela é especial: nela os
         * primários por nome (APIBay, Knaben, BT4G) e os provedores por
         * identificador (Torrentio, addons Stremio) são consultados **juntos**,
         * antes de qualquer corte.
         *
         * Antes, os por identificador vinham sozinhos e o `coletaSuficiente()`
         * podia encerrar a busca logo depois: o Torrentio respondia rápido com
         * fontes PT-BR e o Knaben — que é justamente quem enxerga os packs
         * nacionais das séries antigas — nunca era perguntado. O sintoma era "só
         * veio do Torrentio".
         *
         * A ordem aqui não é acidental: os primários por nome vêm **primeiro**.
         * Eles são os únicos que respondem aos termos de pack/série, e o
         * `jaTemEpisodioAproveitado()` lê o censo inteiro — se o Torrentio
         * rodasse antes e marcasse uma fonte, os termos de socorro dos primários
         * seriam pulados na mesma rodada. Rodando os primários primeiro, o Knaben
         * recebe o degrau completo antes de qualquer contagem. A dispensa
         * (`$dispensarSocorro`) cobre o caso inverso: mesmo que um primário já
         * tenha achado episódio, os demais ainda recebem os termos de socorro
         * nesta rodada, porque é a única chance que eles têm de achar o pack.
         */
        $primeiroTitulo = $titulos[0] ?? '';

        if ($primeiroTitulo !== '' && ! $this->orcamentoEsgotado()) {
            $termoDePack = $this->eTermoDePack($primeiroTitulo, $temporada, $episodio);
            $termoDeSerie = $this->eTermoDeSerie($primeiroTitulo, $temporada, $episodio);

            $porNome = $this->buscarGrupo(
                $this->primarios,
                $primeiroTitulo,
                $ano,
                $imdbId,
                $temporada,
                $episodio,
                $termoDePack,
                $termoDeSerie,
                $titulos,
                true
            );

            /*
             * O contador do gate é lido aqui, e não depois: a chamada seguinte
             * (`$porIdentificador`) zera `$this->barradasPeloGate` no seu próprio
             * início, e o número que interessa ao relatório é o dos primários —
             * é neles que os termos de pack/série rodam e é o gate deles que
             * explica uma lista curta.
             */
            $barradasDosPrimarios = $this->barradasPeloGate;

            $porIdentificador = $this->buscarGrupo(
                $this->porIdentificador,
                $primeiroTitulo,
                $ano,
                $imdbId,
                $temporada,
                $episodio
            );

            $fontes = $this->mesclar($fontes, $porNome, $porIdentificador);

            $this->registrarEtapa('nativos', $primeiroTitulo, $porNome, $termoDeSerie, $barradasDosPrimarios);
        }

        /*
         * O título traduzido é o que os trackers brasileiros usam, mas o título
         * original ajuda quando a tradução abreviou demais ("Homem-Aranha" versus
         * "Spider-Man"). Tentamos os dois no degrau nativo antes de descer para o
         * indexador — é mais barato insistir no caminho principal do que delegar.
         *
         * Cada título chega com as variações dubladas montadas pelo
         * TorrentService, e a cascata **não para mais na primeira fonte PT-BR**:
         * ela segue somando até juntar a meta de coleta ou esgotar os termos.
         * Parar no primeiro resultado era o que enchia o resto da lista com
         * originais; agora o corte de idioma nem descarta mais nada — só etiqueta
         * — e quem decide quanto de reserva entra é a montagem final, no
         * TorrentService.
         *
         * O laço começa no **segundo** termo: o primeiro já foi coberto pela
         * rodada de abertura acima, com todos os provedores. Aqui só restam as
         * variações dubladas, que interessam aos provedores por nome — os por
         * identificador ignoram o termo e não são repetidos.
         */
        foreach (array_slice($titulos, 1) as $titulo) {
            /*
             * O prazo global é checado antes de cada termo: um termo novo é uma
             * nova rodada de requisições em todos os provedores, e começar uma
             * rodada que já não cabe no orçamento só serviria para estourar o
             * tempo do frontend. O que já foi recolhido segue para a montagem.
             */
            if ($this->orcamentoEsgotado()) {
                $this->avisarPrazo('nativos');

                break;
            }

            $termoDePack = $this->eTermoDePack($titulo, $temporada, $episodio);
            $termoDeSerie = $this->eTermoDeSerie($titulo, $temporada, $episodio);

            $desteTermo = $this->buscarGrupo($this->primarios, $titulo, $ano, $imdbId, $temporada, $episodio, $termoDePack, $termoDeSerie, $titulos);
            $fontes = $this->mesclar($fontes, $desteTermo);

            $this->registrarEtapa('nativos', $titulo, $desteTermo, $termoDeSerie, $this->barradasPeloGate);

            if ($this->coletaSuficiente($fontes)) {
                /*
                 * Com `buscar_todos` ligado, a meta não encerra a busca: ela só
                 * diz que não vale mais insistir nos termos restantes deste
                 * degrau. Os provedores que ainda não renderam o suficiente já
                 * foram visitados na rodada de abertura, então parar aqui não
                 * deixa ninguém de fora — e o degrau seguinte continua.
                 */
                if ($this->buscarTodos() || $this->coberturaCompleta()) {
                    break;
                }

                return $this->encerrar($fontes, $titulos, $temporada, $episodio);
            }
        }

        /*
         * A rodada de abertura já perguntou a todos os provedores por nome. Se ela
         * sozinha juntou a meta de fontes PT-BR, não há por que descer ao
         * indexador: o corte que antes acontecia dentro do laço passa a valer
         * aqui, depois de todos terem respondido.
         *
         * Com `buscar_todos` ligado o corte não vale: a busca desce ao indexador
         * mesmo com a meta batida, porque o objetivo é somar o que cada degrau
         * oferece — e o indexador é um provedor como os outros.
         */
        if ($this->coletaSuficiente($fontes) && ! $this->coberturaCompleta() && ! $this->buscarTodos()) {
            return $this->encerrar($fontes, $titulos, $temporada, $episodio);
        }

        /*
         * Degrau 2: indexador. Sem `buscar_todos`, só é consultado se o degrau
         * nativo não juntou a meta de fontes PT-BR. Com ele ligado, o indexador é
         * visitado de qualquer forma — é um provedor como os outros e o objetivo é
         * somar o que cada degrau oferece. Em ambos os casos, a meta encerra a
         * repetição de termos, não a busca.
         */
        $this->loteConsumido = [];

        foreach ($titulos as $titulo) {
            /*
             * O indexador é o degrau mais caro: cada termo rende duas consultas
             * (pura e dublada) e cada consulta varre todos os indexadores do
             * Prowlarr. Ele tem o próprio orçamento interno, mas ainda assim não
             * vale começar um termo quando o prazo global já acabou.
             */
            if ($this->orcamentoEsgotado()) {
                $this->avisarPrazo('indexador');

                break;
            }

            $termoDePack = $this->eTermoDePack($titulo, $temporada, $episodio);
            $termoDeSerie = $this->eTermoDeSerie($titulo, $temporada, $episodio);

            $desteTermo = $this->buscarGrupo([$this->torznab], $titulo, $ano, $imdbId, $temporada, $episodio, $termoDePack, $termoDeSerie, $titulos);
            $fontes = $this->mesclar($fontes, $desteTermo);

            $this->registrarEtapa('indexador', $titulo, $desteTermo, $termoDeSerie, $this->barradasPeloGate);

            if ($this->coletaSuficiente($fontes)) {
                /*
                 * Mesma regra do degrau nativo: com `buscar_todos` ligado a meta
                 * só encerra a repetição de termos, não a busca. O YTS, que é o
                 * último degrau, ainda precisa ser consultado.
                 */
                if ($this->buscarTodos() || $this->coberturaCompleta()) {
                    break;
                }

                return $this->encerrar($fontes, $titulos, $temporada, $episodio);
            }
        }

        /*
         * Degrau 3: reserva em inglês. Entra sempre que nada dublado apareceu.
         * Em episódio o YTS se abstém sozinho (é catálogo só de filmes), então a
         * chamada é inofensiva e mantém a cascata com um formato único.
         *
         * É a última rede: se o prazo global já estourou, nem ela é tentada — o
         * que foi recolhido até aqui é o que o usuário recebe, e é melhor uma
         * lista parcial agora do que uma lista completa que nunca chega.
         */
        if (! $this->orcamentoEsgotado()) {
            $fontes = $this->mesclar(
                $fontes,
                $this->buscarGrupo([$this->yts], $titulos[0] ?? '', $ano, $imdbId, $temporada, $episodio)
            );
        } else {
            $this->avisarPrazo('yts');
        }

        return $this->encerrar($fontes, $titulos, $temporada, $episodio);
    }

    /**
     * Aciona o provedor de stream direto e devolve o que ele achar.
     *
     * É o **primeiro** método de indexação da busca, e ele não mora dentro da
     * cascata: os torrents são consultados à parte, logo depois, e quem costura os
     * dois é o [`TorrentService`]. A ordem é do interesse da resposta — a fonte
     * direta toca sem malha, então ela tem de estar na mão antes das raspagens
     * lentas. O nome com "fallback" ficou do tempo em que ele só rodava quando a
     * cascata voltava vazia, e é mantido aqui porque os logs e os testes o
     * conhecem por ele.
     *
     * Roda com orçamento próprio: o canal `torrents` tem o dele e este tem o seu,
     * dimensionado para o custo do FlareSolverr (10 a 15 s por página). Como os
     * dois canais sempre rodam, a soma é contida pelo teto global da busca
     * (`tempo_total_busca`), aplicado em [`OrcamentoBusca::abrir()`].
     *
     * O censo do provedor é preenchido à mão porque ele não passa pelo
     * `buscarGrupo()`: sem isto, o relatório o mostraria como `nao_consultado`
     * mesmo tendo sido consultado.
     *
     * @param  array<int, string>  $titulos
     * @return array<int, array<string, mixed>>
     */
    public function buscarFallbackDireto(
        array $titulos,
        ?int $ano,
        ?string $imdbId,
        ?int $temporada,
        ?int $episodio,
        ?string $tmdbId = null,
    ): array {
        $id = $this->streamDireto->identificador();

        /*
         * O rastreio começa aqui, antes de qualquer desistência. Sem este log, um
         * fallback que não roda é indistinguível de um fallback que rodou e não
         * achou nada: os dois terminam com a lista vazia e o mesmo aviso no
         * frontend. Registrar a entrada e cada motivo de saída antecipada é o que
         * permite responder "por que o stream direto não foi consultado?".
         */
        Log::debug('Stream direto: fallback acionado.', [
            'provedor' => $id,
            'titulos' => $titulos,
            'ano' => $ano,
            'imdb_id' => $imdbId,
            'tmdb_id' => $tmdbId,
            'temporada' => $temporada,
            'episodio' => $episodio,
        ]);

        if (! $this->streamDireto->disponivel()) {
            $this->censo[$id]['disponivel'] = false;

            Log::info('Stream direto: fallback desligado, provedor não consultado.', [
                'provedor' => $id,
                'chave' => 'TORRENTS_STREAM_DIRETO_HABILITADO',
            ]);

            return [];
        }

        $titulo = $titulos[0] ?? '';

        if (trim($titulo) === '') {
            Log::info('Stream direto: fallback sem título para buscar, provedor não consultado.', [
                'provedor' => $id,
            ]);

            return [];
        }

        $inicio = microtime(true);

        /*
         * O stream direto tem o **próprio relógio**, e não o da cascata. O custo
         * dos dois canais é de ordens diferentes: um provedor de torrent responde
         * em milissegundos, enquanto o stream direto paga uma renderização de
         * navegador (FlareSolverr) por página, de 10 a 15 s cada, e precisa de
         * duas páginas no mínimo — a listagem da série e a do episódio. Com o
         * prazo único de 45 s, a cascata de torrents consumia o orçamento inteiro
         * e o stream direto, que rodava depois na mesma busca, nascia sem tempo:
         * o FlareSolverr respondia 200 com a página do episódio quando chamado à
         * mão, mas o provedor desistia antes de chamá-lo, porque `restante()` já
         * devolvia zero.
         *
         * A troca de canal é o que separa os dois relógios. A partir daqui, o
         * [`ClienteHttp`] e o trait [`ConsultaComOrcamento`] leem o prazo do canal
         * `stream_direto` — dimensionado para o custo real do FlareSolverr —, e
         * não o da cascata. Como os dois métodos sempre rodam, estes orçamentos
         * **somam** no tempo da resposta; quem impede a soma de estourar o que o
         * frontend espera é o teto global da busca (`tempo_total_busca`), que
         * corta os dois canais em [`OrcamentoBusca::abrir()`].
         *
         * O `abrirSeFechado()` continua idempotente dentro do canal: se o canal
         * `stream_direto` já estiver de pé (por uma chamada anterior da mesma
         * busca), o relógio não é reiniciado.
         */
        $this->orcamento->usarCanal(OrcamentoBusca::CANAL_STREAM_DIRETO);

        $this->orcamentoAbertoAqui = $this->orcamento->abrirSeFechado(
            (int) config('services.torrents.stream_direto_orcamento', 45),
            OrcamentoBusca::CANAL_STREAM_DIRETO
        );

        try {
            /*
             * Passamos a lista inteira de títulos, e não só o primeiro: o scraper
             * usa as variações (tipicamente o título original) como rede de
             * segurança quando o acervo PT-BR não tem página nenhuma.
             */
            $fontes = $this->streamDireto->buscarComTitulos($titulos, $ano, $imdbId, $temporada, $episodio, $tmdbId);
        } catch (\Throwable $excecao) {
            $this->censo[$id]['erros']++;
            $this->censo[$id]['consultas']++;

            Log::warning('Falha no provedor de stream direto.', [
                'provedor' => $id,
                'erro' => $excecao->getMessage(),
            ]);

            $fontes = [];
        } finally {
            /*
             * O relógio do stream direto **não** é fechado aqui, e o canal ativo
             * volta para o padrão. Fechá-lo deixaria a cascata de torrents — que
             * entra logo depois, na mesma busca — sem prazo nenhum, reabrindo um
             * orçamento novo do zero. Quem fecha os dois canais é o
             * [`TorrentService`], no fim da busca inteira, via `fecharOrcamento()`.
             *
             * A volta do canal é obrigatória: sem ela, a cascata de torrents, que
             * roda logo depois na mesma busca, leria o prazo do stream direto em
             * vez do próprio, e o orçamento global perderia o sentido.
             */
            $this->orcamento->usarCanal(OrcamentoBusca::CANAL_PADRAO);
        }

        $this->censo[$id]['consultas']++;
        $this->censo[$id]['ms'] += (int) round((microtime(true) - $inicio) * 1000);
        $this->censo[$id]['brutas'] += count($fontes);

        /*
         * O censo dos agregadores viaja junto da linha do provedor. A linha diz
         * que o stream direto foi consultado, quanto tempo levou e o que devolveu
         * — mas não **a quem** ele perguntou, e é isso que o usuário precisa saber
         * quando o fallback volta vazio: o superflix chegou a ser procurado? A
         * resposta dele foi a tela de verificação? Ele devolveu a ficha em vez da
         * página do episódio?
         */
        $this->censo[$id]['agregadores'] = $this->streamDireto->censoDosAgregadores();

        Log::debug('Stream direto: fallback concluído.', [
            'provedor' => $id,
            'fontes' => count($fontes),
            'ms' => (int) round((microtime(true) - $inicio) * 1000),
        ]);

        /*
         * As fontes diretas não passam pelo gate de temporada — não têm numeração
         * de release para provar nada. A marcação de `pt_br` é feita aqui, pelo
         * idioma deduzido, para a montagem final tratá-las como qualquer outra.
         */
        foreach ($fontes as &$fonte) {
            $fonte['pt_br'] = in_array(
                $fonte['idioma'] ?? '',
                [IdiomaFonte::DUBLADO->value, IdiomaFonte::DUAL_AUDIO->value],
                true
            );
        }

        unset($fonte);

        $this->contabilizarAproveitadas($fontes);

        return $fontes;
    }

    /**
     * Diz se o orçamento de tempo da busca inteira acabou.
     *
     * O prazo é ancorado no início de `buscar()` e vale para a cascata toda —
     * termos e degraus. Sem ele, cada provedor respeitava o próprio teto, mas a
     * soma não tinha limite: era essa soma que estourava os 60 s do frontend e
     * fazia a requisição ser cancelada antes de a lista chegar à tela.
     */
    private function orcamentoEsgotado(): bool
    {
        return $this->orcamento->esgotado();
    }

    /**
     * Registra, uma vez por busca, que o orçamento acabou e onde.
     *
     * O `degrau` no log é o que separa "a busca terminou sozinha" de "a busca foi
     * cortada no meio": sem ele, uma lista menor que o esperado fica
     * indistinguível de "o acervo não tinha o release".
     */
    private function avisarPrazo(string $degrau): void
    {
        if ($this->avisouPrazo) {
            return;
        }

        $this->avisouPrazo = true;

        Log::warning('Orçamento da busca de torrents esgotado; devolvendo o que foi recolhido.', [
            'degrau' => $degrau,
            'orcamento' => (int) config('services.torrents.orcamento_busca', 45),
        ]);
    }

    /**
     * Confirma se existe algum provedor utilizável na configuração atual.
     *
     * O controller usa isto para distinguir "nenhuma fonte encontrada" de
     * "nenhum provedor pôde ser consultado" — a segunda mensagem aponta para
     * configuração, não para o filme.
     */
    public function algumDisponivel(): bool
    {
        foreach (array_merge($this->primarios, [$this->torznab, $this->yts]) as $provedor) {
            if ($provedor->disponivel()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fecha o orçamento compartilhado ao fim da busca inteira.
     *
     * O relógio é aberto pelo primeiro canal que chega (via `abrirSeFechado()`) e
     * precisa continuar de pé enquanto houver um canal por rodar — a cascata de
     * torrents e o stream direto são duas metades da mesma busca, e cada um pode
     * ser o fallback do outro. Por isso nenhum canal fecha o orçamento sozinho: o
     * [`TorrentService`] chama este método uma única vez, quando a busca acabou de
     * verdade. Sem o fechamento, a próxima busca herdaria um relógio já vencido
     * caso alguma chamada acontecesse antes do próximo `abrir()`.
     */
    public function fecharOrcamento(): void
    {
        /*
         * Fecha os dois canais de uma vez. A cascata de torrents e o stream direto
         * têm relógios separados, e cada um pode ter sido o canal da busca — ou os
         * dois, quando um foi o fallback do outro. Fechar só o canal ativo deixaria
         * o outro de pé, e a próxima busca herdaria um prazo vencido se alguma
         * chamada acontecesse antes do próximo `abrir()`.
         */
        $this->orcamento->fecharTudo();
        $this->orcamento->usarCanal(OrcamentoBusca::CANAL_PADRAO);
    }

    /**
     * Relatório da cobertura da última busca: o que cada provedor respondeu.
     *
     * É a resposta verificável a "perguntamos a todos?". Cada provedor do registro
     * aparece, mesmo o que nunca foi tocado, com a situação que explica o silêncio:
     * `sem_credencial` (chave faltando), `nao_consultado` (a cascata parou antes de
     * chegar nele), `erro`, `sem_resultado`, `barrado_no_filtro` (respondeu, mas
     * nada sobreviveu ao gate) ou `com_fonte`. Sem o relatório, "o indexador não
     * trouxe nada" e "o indexador nunca foi perguntado" viram a mesma linha de log.
     *
     * O stream direto é o único provedor com um **nível abaixo**: a chave
     * `agregadores` repete o relatório para cada agregador de vídeo consultado por
     * ele. A linha dele sozinha não distingue "o superflix não tem o episódio" de
     * "o superflix nunca foi perguntado" — e é exatamente essa dúvida que aparece
     * quando o fallback volta vazio.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cobertura(): array
    {
        $relatorio = [];

        foreach ($this->censo as $id => $censo) {
            $relatorio[] = [
                'provedor' => $id,
                'rotulo' => $censo['rotulo'],
                'situacao' => $this->situacaoDoCenso($censo),
                'consultas' => $censo['consultas'],
                'do_cache' => $censo['do_cache'],
                'erros' => $censo['erros'],
                // Tempo de parede gasto neste provedor na busca inteira. É o que
                // separa "não trouxe nada" de "trouxe nada devagar": quando a lista
                // demora e o frontend aborta, é este número que denuncia o degrau.
                'ms' => $censo['ms'],
                'fontes' => $censo['brutas'],
                'aproveitadas' => $censo['aproveitadas'],
                'pt_br' => $censo['pt_br'],
                'packs' => $censo['packs'],
                // Quantas fontes deste provedor sobreviveram à montagem final.
                // Sem este número, `aproveitadas` conta o que passou pelo gate da
                // cascata e o censo mente: um provedor que só trouxe original fica
                // `com_fonte` mesmo sem nenhuma fonte na lista que o usuário vê.
                'na_lista' => $censo['na_lista'],
                /*
                 * O único provedor que tem um nível abaixo é o stream direto: ele
                 * não consulta um site, consulta uma lista de agregadores de vídeo
                 * (e, depois, o motor web). A linha dele sozinha não diz a quem —
                 * e "0 fontes" com "1 consulta" é indistinguível de "nem chegou a
                 * perguntar ao superflix". Os demais provedores devolvem lista
                 * vazia aqui: o censo por agregador só existe onde há agregadores.
                 */
                'agregadores' => $censo['agregadores'] ?? [],
            ];
        }

        return $relatorio;
    }

    /**
     * Reconcilia o censo com a lista final que o usuário recebe.
     *
     * O censo é contado dentro da cascata, antes da montagem final. Isso fazia o
     * relatório dizer `com_fonte` para um provedor cujas fontes foram todas
     * descartadas depois — o caso do APIBay, que teve aproveitadas no gate mas
     * nenhuma sobreviveu ao corte de idioma. Aqui o número `na_lista` é gravado a
     * partir da lista que de fato saiu, e a situação do provedor é reescrita para
     * `descartado_na_montagem` quando ele teve aproveitadas mas nenhuma chegou ao
     * fim. Assim o censo deixa de mentir: `com_fonte` passa a significar "tem
     * fonte na lista", não "passou pelo gate".
     *
     * @param  array<int, array<string, mixed>>  $fontes  Lista final já montada
     */
    public function reconciliarCenso(array $fontes): void
    {
        foreach ($this->censo as $id => $censo) {
            $this->censo[$id]['na_lista'] = 0;
        }

        foreach ($fontes as $fonte) {
            /*
             * A fonte direta carrega em `provedor` o site de origem, e não o nome
             * do método: quem faz o casamento com a linha certa do censo é
             * [`linhaDoCenso()`].
             */
            $provedor = $this->linhaDoCenso($fonte);

            if ($provedor !== '' && isset($this->censo[$provedor])) {
                $this->censo[$provedor]['na_lista']++;
            }
        }
    }

    /**
     * Diz a qual linha do censo uma fonte pertence.
     *
     * A fonte de torrent traz o identificador do provedor em `provedor` e o
     * casamento é direto. A fonte direta, não: o `provedor` dela carrega o **site
     * de origem** — o agregador onde o vídeo foi achado, que é o que o usuário
     * reconhece na lista —, e o censo tem uma linha só para o método
     * (`stream_direto`). Sem este desvio, as duas contas que dependem do
     * casamento (`aproveitadas` e `na_lista`) ficariam zeradas para o stream
     * direto, e a cobertura diria `barrado_no_filtro` ou
     * `descartado_na_montagem` de fontes que estão na lista.
     *
     * @param  array<string, mixed>  $fonte
     */
    private function linhaDoCenso(array $fonte): string
    {
        if (($fonte['tipo'] ?? '') === 'direto') {
            return $this->streamDireto->identificador();
        }

        return (string) ($fonte['provedor'] ?? '');
    }

    /**
     * Zera o censo, mantendo o registro de provedores do relatório.
     *
     * Roda também no construtor: assim uma busca que nunca chegou a consultar nada
     * ainda devolve o registro completo, com todo mundo em `nao_consultado`, em vez
     * de um relatório vazio que não distingue nada.
     *
     * O stream direto é a exceção. Ele não passa pela cascata: é acionado à parte,
     * antes dela, e o seu censo é preenchido à mão em [`buscarFallbackDireto()`].
     * Como ele sempre roda primeiro e a cascata sempre vem depois, `buscar()`
     * zeraria o censo logo em seguida e apagaria o registro do stream direto — o
     * relatório diria `nao_consultado` de um provedor que rodou, que é justamente
     * a mentira que o censo existe para evitar. Preservar o registro dele aqui
     * mantém a contagem da consulta que já aconteceu.
     */
    private function reiniciarCenso(): void
    {
        $streamDireto = $this->censo[$this->streamDireto->identificador()] ?? null;

        $this->censo = [];
        $this->fontesNoCenso = [];

        foreach ($this->registro as $provedor) {
            $this->censo[$provedor->identificador()] = [
                'rotulo' => $provedor->rotulo(),
                'disponivel' => true,
                'consultas' => 0,
                'do_cache' => 0,
                'erros' => 0,
                'ms' => 0,
                'brutas' => 0,
                'aproveitadas' => 0,
                'pt_br' => 0,
                'packs' => 0,
                // Preenchido só na reconciliação, depois da montagem final. Nasce
                // zerado para o relatório de uma busca que nunca chegou a montar
                // não devolver a chave ausente.
                'na_lista' => 0,
            ];
        }

        if ($streamDireto !== null) {
            $this->censo[$this->streamDireto->identificador()] = $streamDireto;
        }
    }

    /**
     * Contabiliza, por provedor, o que sobreviveu ao gate da cascata.
     *
     * O número que interessa não é quantas fontes o provedor devolveu, e sim
     * quantas passaram pelo filtro de numeração/idioma: um provedor que responde
     * vinte releases da temporada errada fica com `barrado_no_filtro`, e é isso que
     * explica uma lista curta sem culpar o provedor nem esconder o gate. O corte de
     * `LIMITE_FONTES` é posterior e não entra nesta conta — `aproveitadas` pode ser
     * maior que a lista final.
     *
     * @param  array<int, array<string, mixed>>  $fontes
     */
    private function contabilizarAproveitadas(array $fontes): void
    {
        foreach ($fontes as $fonte) {
            $id = (string) ($fonte['id'] ?? '');

            if ($id === '' || isset($this->fontesNoCenso[$id])) {
                continue;
            }

            $this->fontesNoCenso[$id] = true;

            $provedor = $this->linhaDoCenso($fonte);

            if ($provedor === '' || ! isset($this->censo[$provedor])) {
                continue;
            }

            $this->censo[$provedor]['aproveitadas']++;

            if (($fonte['pt_br'] ?? false) === true) {
                $this->censo[$provedor]['pt_br']++;
            }

            if (! empty($fonte['pack'])) {
                $this->censo[$provedor]['packs']++;
            }
        }
    }

    /**
     * Traduz os contadores de um provedor na situação que o relatório mostra.
     *
     * @param  array<string, mixed>  $censo
     */
    private function situacaoDoCenso(array $censo): string
    {
        if (! $censo['disponivel']) {
            return 'sem_credencial';
        }

        if ($censo['consultas'] === 0) {
            return 'nao_consultado';
        }

        if ($censo['brutas'] === 0) {
            return $censo['erros'] > 0 ? 'erro' : 'sem_resultado';
        }

        if ($censo['aproveitadas'] === 0) {
            return 'barrado_no_filtro';
        }

        /*
         * Passou pelo gate da cascata, mas nenhuma fonte chegou à lista final.
         * É o caso do provedor que só trouxe original quando havia dublado: o
         * corte de idioma da montagem o descartou inteiro. Sem esta situação, o
         * relatório diria `com_fonte` e o usuário procuraria na lista uma fonte
         * que não está lá.
         */
        return $censo['na_lista'] > 0 ? 'com_fonte' : 'descartado_na_montagem';
    }

    /**
     * Fecha a busca registrando a cobertura e devolvendo as fontes.
     *
     * O log sai junto do retorno porque este é o único ponto por onde todos os
     * desfechos passam — inclusive os que param cedo por meta atingida, que são
     * justamente os que deixam provedor sem consulta.
     *
     * @param  array<int, array<string, mixed>>  $fontes
     * @param  array<int, string>  $titulos
     * @return array<int, array<string, mixed>>
     */
    private function encerrar(array $fontes, array $titulos, ?int $temporada, ?int $episodio): array
    {
        /*
         * O orçamento **não** é fechado aqui. Ele é compartilhado entre os canais
         * e precisa continuar de pé quando a cascata é só a segunda metade da
         * busca — o stream direto já rodou antes dela. Quem fecha é o
         * [`TorrentService`], no fim da busca inteira, via `fecharOrcamento()`.
         */

        $cobertura = $this->cobertura();

        $naoConsultados = array_values(array_map(
            fn (array $item): string => (string) $item['provedor'],
            array_filter($cobertura, fn (array $item): bool => $item['situacao'] === 'nao_consultado')
        ));

        Log::info('Cobertura da busca de torrents.', [
            'titulos' => $titulos,
            'temporada' => $temporada,
            'episodio' => $episodio,
            'fontes' => count($fontes),
            'provedores_consultados' => count(array_filter(
                $cobertura,
                fn (array $item): bool => $item['consultas'] > 0
            )),
            'nao_consultados' => $naoConsultados,
            'cobertura' => $cobertura,
        ]);

        return $fontes;
    }

    /**
     * Diz se a lista contém alguma fonte dublada ou em dual áudio.
     *
     * Deixou de ser o critério de parada da cascata: quem decide a parada agora é
     * o **orçamento de coleta** (`coletaSuficiente()`), que conta o acumulado de
     * fontes PT-BR. Este método continua útil como leitura rápida da lista: o
     * TorrentService o usa no log para separar "não existe release dublado" de
     * "a classificação de idioma falhou".
     *
     * @param  array<int, array<string, mixed>>  $fontes
     */
    public function temDublado(array $fontes): bool
    {
        foreach ($fontes as $fonte) {
            $idioma = $fonte['idioma'] ?? '';

            if ($idioma === IdiomaFonte::DUBLADO->value || $idioma === IdiomaFonte::DUAL_AUDIO->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Diz se a lista já juntou PT-BR suficiente para dispensar a segunda fase.
     *
     * É a leitura pública do mesmo critério que encerra a cascata
     * (`coletaSuficiente()`). O [`TorrentService`] a usa para decidir se vale
     * repetir a busca pelo título original: quando a primeira fase já bateu a
     * meta PT-BR, a segunda só gastaria orçamento e traria releases em inglês.
     *
     * @param  array<int, array<string, mixed>>  $fontes
     */
    public function ptBrSuficiente(array $fontes): bool
    {
        return $this->coletaSuficiente($fontes);
    }

    /**
     * Conta quantas fontes da lista são PT-BR (dublado ou dual áudio).
     *
     * A leitura é pela etiqueta `pt_br`, gravada em `aproveitaveis()` a partir do
     * idioma — e promovida a dublado quando a inspeção do conteúdo de um pack
     * prova o PT-BR. Contar pelo idioma cru perderia justamente esses packs.
     *
     * @param  array<int, array<string, mixed>>  $fontes
     */
    private function ptBrAcumulado(array $fontes): int
    {
        return count(array_filter(
            $fontes,
            fn (array $fonte) => ($fonte['pt_br'] ?? false) === true
        ));
    }

    /**
     * Diz se a busca deve seguir até o último degrau mesmo com a meta já batida.
     *
     * Desligada (padrão), a meta PT-BR encerra a cascata assim que alcançada — a
     * busca economiza tempo e os degraus de baixo ficam `nao_consultado` no
     * relatório. Ligada, a meta apenas encerra a repetição de termos do degrau
     * atual: a cascata desce até o último degrau, para que todo provedor do
     * registro seja perguntado ao menos uma vez e o relatório prove isso. O custo
     * é a latência do indexador; ligue quando precisar de certeza, não no fluxo
     * comum.
     */
    private function coberturaCompleta(): bool
    {
        return (bool) config('services.torrents.cobertura_completa', false);
    }

    /**
     * Diz se a busca deve percorrer todos os provedores, sem encerrar na meta.
     *
     * É o comportamento padrão. Ligada, a meta PT-BR deixa de encerrar a busca:
     * ela só marca o ponto em que não vale mais insistir nos termos restantes do
     * degrau atual. Cada provedor é visitado até render
     * `fontes_suficientes_por_provedor` fontes — e então é deixado de lado, não
     * por ter falhado, mas por já ter dado o que tinha —, e a busca segue para o
     * próximo. Quem não tem fonte ou está indisponível é pulado na hora.
     *
     * O efeito é uma lista montada com o que **cada** provedor oferece, em vez de
     * parar no primeiro que bate a meta. O preço é a latência: como a busca não
     * encerra cedo, o orçamento global passa a ser o único freio.
     */
    private function buscarTodos(): bool
    {
        return (bool) config('services.torrents.buscar_todos', true);
    }

    /**
     * Diz se a **cascata** já rendeu alguma fonte aproveitável de episódio.
     *
     * É o gatilho que dispensa os termos de socorro (pack e série): eles só fazem
     * sentido quando os termos de episódio não acharam nada. A conta olha o censo
     * inteiro, e não a lista corrente, porque a fonte pode ter vindo de qualquer
     * provedor já consultado — o que importa é que o episódio tem resposta.
     *
     * O stream direto fica **fora** da conta, e a exclusão é a razão de o laço
     * comparar o id. Desde que os dois métodos de indexação passaram a rodar
     * sempre, o stream direto roda antes e o seu censo sobrevive ao
     * [`reiniciarCenso()`] da cascata — então uma única URL tocável achada no
     * acervo web faria o `aproveitadas` dele dispensar os termos de pack e de
     * série. O efeito seria o sintoma que o censo existe para evitar: "só veio do
     * superflix". A pergunta aqui é sobre a malha — se os trackers acharam o
     * episódio —, e quem responde por ela são os provedores da cascata.
     */
    private function jaTemEpisodioAproveitado(): bool
    {
        $streamDireto = $this->streamDireto->identificador();

        foreach ($this->censo as $id => $censo) {
            if ($id === $streamDireto) {
                continue;
            }

            if ($censo['aproveitadas'] > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Diz se a coleta já juntou fontes PT-BR suficientes para parar de buscar.
     *
     * A meta (`meta_pt_br_coleta`) é deliberadamente menor que o mínimo da lista
     * final: o objetivo é garantir que o dublado venha primeiro, não catalogar
     * todas as fontes. Assim que há base PT-BR, a cascata para e não faz o
     * usuário esperar pelos degraus restantes — a reserva, se faltar, é montada
     * depois com o que já foi recolhido.
     *
     * @param  array<int, array<string, mixed>>  $fontes
     */
    private function coletaSuficiente(array $fontes): bool
    {
        $meta = (int) config('services.torrents.meta_pt_br_coleta', 6);

        return $this->ptBrAcumulado($fontes) >= $meta;
    }

    /**
     * Diz se o provedor já rendeu o suficiente e pode ser deixado de lado nos
     * termos seguintes.
     *
     * A conta usa as fontes **aproveitadas** (as que passaram pelo gate de
     * numeração e idioma), e não as brutas: um provedor que devolve vinte releases
     * da temporada errada não ganhou nada com isso, e continuar perguntando ainda
     * faz sentido. Sem este corte, um episódio de série nova monta dezenas de
     * termos e cada degrau é martelado uma vez por termo — foi assim que a busca
     * de "Lanternas S01E01" passou de um minuto e o navegador abortou. O corte não
     * encosta na ordenação final, que roda depois e ignora a ordem de coleta; ele
     * só decide quando parar de repetir a mesma pergunta ao mesmo provedor.
     *
     * Com `buscar_todos` ligado, este é o mecanismo que faz a busca **avançar**:
     * um provedor que já entregou o suficiente não é mais martelado, e a vez passa
     * ao próximo — que é justamente o que garante que todos sejam visitados sem
     * que nenhum consuma o orçamento inteiro.
     *
     * Em `0` (ou negativo) o corte fica desligado e a busca volta a varrer todos
     * os termos em todos os degraus.
     */
    private function suficientePorProvedor(string $id): bool
    {
        $limite = (int) config('services.torrents.fontes_suficientes_por_provedor', 4);

        return $limite > 0 && ($this->censo[$id]['aproveitadas'] ?? 0) >= $limite;
    }

    /**
     * Registra o que cada etapa da cascata devolveu, com quantas fontes PT-BR.
     *
     * Sem este retrato, "só veio uma fonte" fica indistinguível de "a etapa não
     * rodou". O `pt_br` e a `reserva` são o par que conta a história nova: o
     * primeiro diz quanto desta etapa serve direto, o segundo quanto estaria
     * disponível para completar a lista se faltasse dublado.
     *
     * @param  array<int, array<string, mixed>>  $fontes
     */
    private function registrarEtapa(
        string $degrau,
        string $titulo,
        array $fontes,
        bool $termoDeSerie = false,
        int $barradasPeloGate = 0,
    ): void {
        $ptBr = $this->ptBrAcumulado($fontes);

        Log::info('Etapa da cascata de torrents concluída.', [
            'degrau' => $degrau,
            'titulo' => $titulo,
            'fontes' => count($fontes),
            'pt_br' => $ptBr,
            'reserva' => count($fontes) - $ptBr,
            // Quantos packs entraram nesta etapa. Sem este número, um pack que
            // entra pela inspeção some no total de "fontes" e não dá para saber
            // se a exceção do pack funcionou.
            'packs' => count(array_filter(
                $fontes,
                fn (array $fonte) => ($fonte['pack'] ?? false) === true
            )),
            // O termo de série é o último recurso e o único cujas fontes passam
            // pelo gate. Os dois campos respondem "a frente nova rodou e por que
            // veio vazia": `termo_serie` diz que o termo entrou, e
            // `barradas_gate` diz quanto o gate descartou por falta de temporada.
            'termo_serie' => $termoDeSerie,
            'barradas_gate' => $barradasPeloGate,
        ]);
    }

    /**
     * Consulta um conjunto de provedores para um título, somando o que vier.
     *
     * @param  array<int, ProvedorTorrents>  $provedores
     * @return array<int, array<string, mixed>>
     */
    private function buscarGrupo(
        array $provedores,
        string $titulo,
        ?int $ano,
        ?string $imdbId,
        ?int $temporada = null,
        ?int $episodio = null,
        bool $termoDePack = false,
        bool $termoDeSerie = false,
        array $lote = [],
        bool $dispensarSocorro = false,
    ): array {
        /*
         * Termo de socorro com o episódio já resolvido: não pergunta.
         *
         * Os termos de pack e de série existem para a série antiga, cujo episódio
         * isolado já não tem seeds — são o último recurso, e por isso vêm no fim
         * da lista de termos. Quando a busca já juntou fontes aproveitáveis nos
         * termos de episódio, insistir neles é o que multiplica as requisições: no
         * "Lanternas S01E01" são nove termos de socorro por título, e cada um
         * varre todos os provedores por nome. O corte não muda o resultado — quem
         * tem episódio vivo não precisa do pack — e derruba a espera.
         *
         * A exceção é a rodada de abertura (`$dispensarSocorro`): nela os
         * provedores por identificador e os primários por nome correm juntos, e o
         * que o Torrentio achar não pode calar o Knaben. Sem esta dispensa, o
         * Torrentio respondia primeiro, marcava `aproveitadas` no censo e o
         * `jaTemEpisodioAproveitado()` — que lê o censo inteiro — fazia os termos
         * de pack/série dos primários serem pulados na mesma rodada. Era esse o
         * "só veio do Torrentio": o Knaben, que é quem enxerga os packs nacionais
         * das séries antigas, nunca chegava a ser perguntado.
         */
        if (! $dispensarSocorro && ($termoDePack || $termoDeSerie) && $this->jaTemEpisodioAproveitado()) {
            return [];
        }

        /*
         * O contador do gate começa zerado a cada grupo: ele é lido logo depois,
         * pelo `registrarEtapa()`, e precisa refletir só esta consulta — não o
         * acumulado dos termos anteriores.
         */
        $this->barradasPeloGate = 0;

        if (trim($titulo) === '') {
            return [];
        }

        $fontes = [];

        foreach ($provedores as $provedor) {
            /*
             * O prazo global é reavaliado a cada provedor, e não só entre termos.
             * Sem esta checagem, uma rodada que começou a segundos do fim ainda
             * percorria todos os provedores restantes — cada um com o próprio
             * `tempo_limite` e, se bloqueado, mais o `proxy_nativo_timeout` do
             * FlareSolverr. Era essa soma dentro de uma única rodada que estourava
             * os 60 s do frontend mesmo com o orçamento global em 45 s: o relógio
             * só era lido no termo seguinte, tarde demais. Parar aqui devolve o que
             * já foi recolhido em vez de perder a lista inteira.
             */
            if ($this->orcamentoEsgotado()) {
                $this->avisarPrazo('nativos');

                break;
            }

            if (! $provedor->disponivel()) {
                /*
                 * Regra da credencial ausente: pular o provedor e deixar a
                 * cascata seguir. Nada de erro bloqueante — a falta de uma chave
                 * reduz o alcance, não impede a busca. A dispensa entra no censo
                 * para o relatório separar "não perguntamos" de "não havia como
                 * perguntar".
                 */
                $this->censo[$provedor->identificador()]['disponivel'] = false;

                Log::info('Provedor de torrents pulado por falta de configuração.', [
                    'provedor' => $provedor->identificador(),
                ]);

                continue;
            }

            /*
             * Provedor já suficiente: repetir os termos restantes só gastaria
             * requisições com quem já disse o que tinha. Ele continua no censo com
             * o que rendeu — o que muda é o custo da busca, não o relatório.
             */
            if ($this->suficientePorProvedor($provedor->identificador())) {
                continue;
            }

            /*
             * Provedor de lote: pergunta o degrau inteiro de uma vez e sai do
             * laço. A chamada só acontece no primeiro termo — nos seguintes ele já
             * está marcado como consumido, senão repetiria a mesma varredura a
             * cada termo, que é justamente o custo que o lote veio eliminar.
             */
            if ($provedor instanceof ProvedorPorLote && $lote !== []) {
                if (isset($this->loteConsumido[$provedor->identificador()])) {
                    continue;
                }

                $this->loteConsumido[$provedor->identificador()] = true;

                $fontes = $this->mesclar(
                    $fontes,
                    $this->buscarLoteComCache($provedor, $lote, $ano, $imdbId, $temporada, $episodio)
                );

                continue;
            }

            $fontes = $this->mesclar(
                $fontes,
                $this->buscarComCache($provedor, $titulo, $ano, $imdbId, $temporada, $episodio)
            );
        }

        /*
         * A marcação de pack precisa nascer aqui, e não no chamador: é este
         * método que entrega a lista já filtrada, e a exceção de idioma do pack
         * depende de a fonte carregar a marca. Como o filtro roda logo abaixo e a
         * ordenação acontece depois, a marca é o que sobrevive aos dois cortes.
         *
         * A marca nasce do **título da própria fonte**, não do termo buscado.
         * Marcar tudo o que um termo de pack devolvia foi o que criou o falso
         * pacote: a busca por "S01 completa" só achava o arquivo S01E01 e o
         * rotulava como pacote. E o inverso era pior — um pacote de verdade
         * devolvido por um termo de episódio (ou pelo grupo por identificador)
         * nunca era marcado e o corte de idioma o descartava, que é justamente o
         * "não acha pack" das séries antigas.
         */
        $fontes = $this->marcarPacks($fontes, $temporada, $episodio, $termoDePack);
        $fontes = $this->aproveitaveis($fontes, $temporada, $episodio, $termoDePack);

        $this->contabilizarAproveitadas($fontes);

        return $fontes;
    }

    /**
     * Diz se o termo corrente é um termo de pack de temporada.
     *
     * A exceção de idioma do pack só vale quando a busca é de episódio: um filme
     * jamais é marcado como pack, mesmo que o título contenha "complete".
     */
    private function eTermoDePack(string $titulo, ?int $temporada, ?int $episodio): bool
    {
        return $temporada !== null
            && $episodio !== null
            && TermosBusca::eTermoDePack($titulo);
    }

    /**
     * Diz se o termo corrente é um termo de série (sem numeração de episódio).
     *
     * Só faz sentido no contexto de episódio: é a busca de série, com temporada e
     * episódio definidos, que monta esses termos. Fora dele, `false` — filme não
     * tem o que gatear.
     *
     * O reconhecimento é delegado ao [`TermosBusca::eTermoDeSerie()`], que é
     * quem também sabe que um termo de pack **não** é termo de série, apesar de
     * os dois carregarem a temporada: o pack tem "completa" e não pode ser
     * tratado como a frente larga, senão o gate o julgaria pelo mesmo critério
     * — que é justamente o critério que ele foi feito para satisfazer.
     */
    private function eTermoDeSerie(string $titulo, ?int $temporada, ?int $episodio): bool
    {
        return $temporada !== null
            && $episodio !== null
            && TermosBusca::eTermoDeSerie($titulo);
    }

    /**
     * Marca como pack as fontes que são, de fato, um pacote de temporada.
     *
     * A leitura é feita sobre o nome do **torrent** (`release`), nunca sobre o
     * termo buscado. Os provedores por identificador (Torrentio e afins) devolvem
     * em `titulo` o nome do arquivo do episódio, o que esconde que o torrent
     * inteiro é a temporada; só a primeira linha do rótulo revela o pacote
     * ("American Horror Story S01 1080p AMZN"). Quando não há `release`, o próprio
     * `titulo` é o nome do release — é o caso dos provedores por nome.
     *
     * - se o nome declara um episódio ("S01E01", "1x01"), é episódio e nunca
     *   pack, mesmo tendo vindo de um termo de pack;
     * - se o nome declara a temporada pedida (ou uma faixa que a inclui, como
     *   "S01-S05") e não declara episódio, é pack;
     * - quando o termo era de pack e o nome não traz temporada reconhecível,
     *   confiamos no termo: o provedor nem sempre repete a temporada no nome.
     *
     * Marcar como pack não basta: o idioma ainda pode estar em aberto no nome.
     * Um pack marcado e sem PT-BR provado tem o conteúdo inspecionado em lote
     * pelo [`InspecaoPack::apurarVarios()`], que abre todos os candidatos de uma
     * vez e promove a dublado os que provam PT-BR por dentro.
     *
     * @param  array<int, array<string, mixed>>  $fontes
     * @return array<int, array<string, mixed>>
     */
    private function marcarPacks(array $fontes, ?int $temporada, ?int $episodio, bool $termoDePack): array
    {
        if ($temporada === null || $episodio === null) {
            return $fontes;
        }

        /*
         * A marcação acontece em duas passadas, e não numa só, porque a inspeção
         * de conteúdo é a etapa mais cara da busca: cada pack aberto é uma
         * conexão e uma espera no media-service. Julgar um pack de cada vez
         * (como era antes) somava o custo de todos — seis packs com o teto de
         * 12 s passavam de 70 s só aqui, e era isso que estourava o tempo do
         * frontend mesmo com todos os provedores vindo do cache.
         *
         * A primeira passada só decide **quais** packs merecem inspeção e monta
         * o mapa infohash => magnet. A segunda dispara todos de uma vez, em
         * paralelo, e aplica os vereditos. O tempo de parede passa a ser o do
         * pack mais lento, não a soma de todos.
         */
        $candidatos = [];
        $magnetPorId = [];

        foreach ($fontes as $indice => $fonte) {
            /*
             * A decisão de pack olha os **dois** nomes da fonte, e não só um.
             *
             * O `release` é o nome do torrent quando o provedor o informa (a
             * primeira linha do rótulo, no caso dos addons Stremio). Mas há
             * provedores em que o `release` acaba sendo o nome do arquivo interno
             * ("2x13 - Madness Ends"): aí a numeração de episódio esconde a
             * temporada do pacote e o pack da 2ª passava batido pelo gate,
             * subindo ao topo de uma busca da 1ª. Lendo também o `titulo` — que
             * carrega o nome do torrent — a temporada declarada reaparece.
             */
            $nomes = array_values(array_unique(array_filter([
                (string) ($fonte['release'] ?? ''),
                (string) ($fonte['titulo'] ?? ''),
            ], fn (string $valor): bool => $valor !== '')));

            if ($nomes === []) {
                continue;
            }

            /*
             * Um nome que declara temporada prova o pack mesmo que outro traga
             * numeração de episódio. Só quando nenhum nome declara temporada é
             * que a numeração de episódio manda — e aí não é pack de temporada,
             * é um episódio mal marcado, que quem julga é a numeração.
             */
            $temporadaDeclarada = TermosBusca::temporadaDosNomes($nomes);

            /*
             * O termo de pack tem precedência sobre a numeração de episódio do
             * nome interno.
             *
             * A checagem de numeração existe para não marcar como pack um
             * episódio solto que veio de um termo largo. Mas quando o próprio
             * termo buscado é de pack ("... S01 completa"), a numeração que
             * aparece no nome é a do **arquivo interno** do torrent — o
             * Torrentio e afins devolvem "2x13 - Madness Ends" como `release`,
             * escondendo que o torrent inteiro é a temporada. Descartar o
             * candidato aqui era o que fazia o pack aprovado pelo termo nunca
             * receber a marca e, sem a marca, morrer no gate de temporada
             * adiante. Em termo de pack, portanto, a numeração de episódio não
             * desqualifica: quem confirma o pacote é o termo.
             */
            if ($temporadaDeclarada === null && ! $termoDePack) {
                $temNumeracao = false;

                foreach ($nomes as $nome) {
                    if (TermosBusca::numeracaoDoTitulo($nome) !== null) {
                        $temNumeracao = true;

                        break;
                    }
                }

                if ($temNumeracao) {
                    continue;
                }
            }

            /*
             * A terceira via de prova é o marcador textual de pacote. Os packs
             * nacionais antigos costumam não numerar a temporada no nome ("A
             * Série Completa Dublado"): nem `temporadaDosNomes()` nem a cobertura
             * o encontram, e sem esta pista ele não seria etiquetado — logo
             * morreria no gate adiante, que só perdoa o que tem número ou a
             * etiqueta de pack. Só chega aqui o nome sem numeração de episódio
             * (o corte acima já descartou quem declara episódio), então
             * "completo"/"coleção" no nome é pista de pacote, não ruído de
             * título de filme.
             */
            $temMarcador = false;

            foreach ($nomes as $nome) {
                if (TermosBusca::temMarcadorDePack($nome)) {
                    $temMarcador = true;

                    break;
                }
            }

            $ePack = TermosBusca::algumNomeCobreTemporada($nomes, $temporada)
                || $temMarcador
                || ($termoDePack && $temporadaDeclarada === null);

            if (! $ePack) {
                continue;
            }

            $fontes[$indice]['pack'] = true;

            $idioma = (string) ($fonte['idioma'] ?? '');

            // O nome já provou PT-BR: não há o que inspecionar.
            if (in_array($idioma, [IdiomaFonte::DUBLADO->value, IdiomaFonte::DUAL_AUDIO->value], true)) {
                continue;
            }

            $magnet = (string) ($fonte['magnet'] ?? '');
            $id = (string) ($fonte['id'] ?? '');

            if ($magnet === '' || $id === ''
                || isset($this->packsInspecionados[$id])
                || $this->inspecoes >= (int) config('services.torrents.inspecao_packs_limite', 6)) {
                continue;
            }

            $this->packsInspecionados[$id] = true;
            $this->inspecoes++;

            $candidatos[$id] = $indice;
            $magnetPorId[$id] = $magnet;
        }

        if ($magnetPorId === []) {
            return $fontes;
        }

        /*
         * O orçamento global manda também aqui: se o prazo já acabou, não faz
         * sentido abrir packs que não terão tempo de responder — a busca está
         * encerrando e o que já foi recolhido é o que o usuário recebe.
         */
        if ($this->orcamentoEsgotado()) {
            return $fontes;
        }

        $vereditos = $this->inspecao->apurarVarios($magnetPorId, $this->orcamento);

        foreach ($vereditos as $id => $indicio) {
            if ($indicio !== true || ! isset($candidatos[$id])) {
                continue;
            }

            $indice = $candidatos[$id];

            $fontes[$indice]['idioma'] = IdiomaFonte::DUBLADO->value;
            $fontes[$indice]['idioma_rotulo'] = IdiomaFonte::DUBLADO->rotulo();
            $fontes[$indice]['idioma_por_inspecao'] = true;
        }

        return $fontes;
    }

    /**
     * Descarta, ainda dentro da cascata, o que não serve para o pedido.
     *
     * São dois cortes de elegibilidade e uma etiqueta:
     *
     * 1. **Numeração** — releases que declaram uma temporada/episódio diferente
     *    da pedida. Releases sem numeração passam, para não apagar packs e nomes
     *    nacionais legítimos.
     * 2. **Temporada (em qualquer termo)** — quando a fonte não declara
     *    numeração de episódio, o silêncio deixa de ser inocente: ela precisa
     *    declarar a temporada pedida (ou uma faixa que a cubra) ou é descartada.
     *    O gate valia só para o termo de série, e era esse o furo: o pack da 2ª
     *    temporada chegava por um termo de **pack** ("... Temporada 2 completa"),
     *    onde o gate era pulado inteiro, e entrava numa busca da 1ª. Como o pack
     *    tem mais seeds, subia ao topo e o player abria o episódio errado.
     * 3. **Etiqueta de idioma** — cada fonte que passa recebe `pt_br` conforme o
     *    idioma deduzido (dublado e dual valem). O idioma **não** descarta mais
     *    nada aqui: o que não é PT-BR vira reserva, e a montagem final decide
     *    quanto dela entra para completar o mínimo de fontes. Antes, o descarte
     *    acontecia cedo e a lista ficava sem com o que preencher.
     *
     * @param  array<int, array<string, mixed>>  $fontes
     * @return array<int, array<string, mixed>>
     */
    private function aproveitaveis(array $fontes, ?int $temporada, ?int $episodio, bool $termoDePack = false): array
    {
        $resultado = [];

        foreach ($fontes as $fonte) {
            if ($temporada !== null && $episodio !== null
                && ! TermosBusca::correspondeAoEpisodio((string) ($fonte['titulo'] ?? ''), $temporada, $episodio)) {
                continue;
            }

            /*
             * Gate de temporada para fontes sem numeração de episódio.
             *
             * O termo de série existe para ser largo: sem "S01E01" e sem
             * "completa", ele pergunta pela série pelo nome, e é assim que os
             * packs nacionais aparecem. O preço da largueza é o provedor devolver
             * o que não é da temporada pedida — "Freak Show", que é a 4ª, não
             * declara número nenhum e passaria por qualquer corte baseado em
             * numeração. Então a prova aqui é invertida: não vale "não declarou
             * nada, deixa passar"; vale declarar a temporada, e cobrindo a pedida
             * — "1ª 2ª 3ª Temporadas" ou só a 1ª. O que nada declara morre neste
             * ponto, e é isso que separa o pack multi-temporada da temporada
             * avulsa homônima.
             *
             * O gate roda para **todo** termo, não só o de série: o pack da
             * temporada errada chega também pelos termos de pack, e era por ali
             * que ele escapava. Releases com numeração seguem julgados pelo
             * `correspondeAoEpisodio()` acima — o gate só olha o que não tem
             * número para provar a temporada.
             *
             * Um pack **etiquetado** por [`marcarPacks()`] já provou a temporada
             * por outra via (cobertura, marcador textual ou o próprio termo de
             * pack), então o gate o deixa passar. Sem esta exceção, "A Série
             * Completa Dublado" — que não declara número — era barrado apesar de
             * já ter sido reconhecido como pacote, e era esse o `na_lista: 0` dos
             * packs compactados. A defesa contra a temporada errada continua em
             * [`TorrentService::packDaTemporadaErrada()`], que ainda rejeita o
             * pack que **declara** uma temporada diferente da pedida.
             */
            if ($temporada !== null && $episodio !== null) {
                /*
                 * O gate lê os dois nomes da fonte, pelo mesmo motivo da
                 * marcação de pack: o `release` pode ser o nome do arquivo
                 * ("2x13 - Madness Ends") e esconder a temporada que o `titulo`
                 * (nome do torrent) declara. Julgar por um só deixava o pack da
                 * 2ª passar pelo gate de uma busca da 1ª.
                 */
                $nomes = array_values(array_unique(array_filter([
                    (string) ($fonte['release'] ?? ''),
                    (string) ($fonte['titulo'] ?? ''),
                ], fn (string $valor): bool => $valor !== '')));

                $temNumeracao = false;

                foreach ($nomes as $nome) {
                    if (TermosBusca::numeracaoDoTitulo($nome) !== null) {
                        $temNumeracao = true;

                        break;
                    }
                }

                /*
                 * A quarta via de prova é o próprio termo de pack.
                 *
                 * Quando a busca veio de um termo de pack ("... S01 completa"), o
                 * termo já declara a temporada pedida — é ele que a monta. Exigir
                 * que o nome do release repita a temporada era o que barrava o
                 * pack cujo `release` é o nome do arquivo interno ("2x13 -
                 * Madness Ends") ou cujo nome nacional não numera nada ("A Série
                 * Completa Dublado"). Nesses casos o termo é a única prova
                 * disponível, e confiar nele é o que faz o pack aprovado chegar
                 * à montagem. A defesa contra a temporada errada continua em
                 * [`TorrentService::packDaTemporadaErrada()`], que ainda rejeita
                 * o pack que **declara** uma temporada diferente da pedida.
                 */
                if (! $temNumeracao
                    && ! TermosBusca::algumNomeCobreTemporada($nomes, $temporada)
                    && empty($fonte['pack'])
                    && ! $termoDePack) {
                    $this->barradasPeloGate++;

                    continue;
                }
            }

            $fonte['pt_br'] = in_array(
                $fonte['idioma'] ?? '',
                [IdiomaFonte::DUBLADO->value, IdiomaFonte::DUAL_AUDIO->value],
                true
            );

            $resultado[] = $fonte;
        }

        return $resultado;
    }

    /**
     * Consulta o provedor com cache e absorve a falha.
     *
     * A resposta vazia também é cacheada — um site que não tem o filme hoje (ou
     * está fora do ar) seria martelado a cada abertura do player sem chance de
     * mudar de resposta. O prazo dela, porém, é **curto** (`cache_ttl_vazio`), e
     * essa diferença é o que separa "o lançamento não existe" de "a busca deu
     * errado". O fallback de stream direto é um encadeamento de raspagens com
     * orçamento curto: quando ele volta vazio, o motivo costuma ser o caminho —
     * um blip de rede, uma página que não respondeu, o prazo consumido por uma
     * consulta lenta. Guardar esse vazio por meia hora transformava um minuto ruim
     * em trinta minutos de "nenhuma fonte encontrada", até para quem fechasse e
     * reabrisse o episódio.
     *
     * A numeração do episódio entra na chave porque o mesmo provedor responde
     * coisas diferentes para cada episódio da série: sem ela, o resultado do
     * S01E01 seria servido para o S01E02 durante todo o TTL.
     *
     * A [`VERSAO_CACHE`] encabeça a chave para isolar as entradas gravadas pelo
     * código antigo: assim uma correção de comportamento passa a valer na próxima
     * busca, em vez de ficar presa atrás do resultado velho.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buscarComCache(
        ProvedorTorrents $provedor,
        string $titulo,
        ?int $ano,
        ?string $imdbId,
        ?int $temporada = null,
        ?int $episodio = null,
    ): array {
        $id = $provedor->identificador();

        $chave = 'torrent:provedor:'.self::VERSAO_CACHE.':'.$id.':'
            .md5(mb_strtolower($titulo).'|'.$ano.'|'.$imdbId.'|'.$temporada.'|'.$episodio);

        $ttl = (int) config('services.torrents.cache_ttl', 1800);

        // Prazo próprio do resultado vazio: ver o comentário do método.
        $ttlVazio = (int) config('services.torrents.cache_ttl_vazio', 120);

        $bypass = (bool) config('services.torrents.cache_bypass', false);

        /*
         * O `has()` antes do `remember()` é o que separa "o provedor respondeu
         * agora" de "o valor veio do cache": o censo precisa dos dois números para
         * o relatório não dizer que consultamos o indexador quando ele foi lido de
         * uma resposta de meia hora atrás. Com o bypass ligado a leitura não
         * acontece, então a consulta nunca conta como cache.
         */
        $doCache = ! $bypass && Cache::has($chave);

        /*
         * O bypass apaga a chave antes do `remember()`: sem a leitura, o provedor
         * é reconsultado e o resultado novo grava por cima. É o que libera um
         * resultado limitado que ficou preso no cache antes de uma correção, sem
         * esperar o TTL nem limpar o Redis à mão.
         */
        if ($bypass) {
            Cache::forget($chave);
        }

        /*
         * O cronômetro abraça o `remember()` inteiro — inclusive o tempo de rede do
         * provedor — porque é esse tempo de parede que o usuário espera, e é ele que
         * estoura o tempo limite do frontend. Somado por provedor, mostra onde a
         * espera foi gasta quando a lista demora a aparecer.
         */
        $inicio = hrtime(true);

        if ($doCache) {
            /*
             * Leitura direta em vez de `remember()`: o prazo já foi decidido quando
             * o valor foi gravado, e reescrevê-lo aqui renovaria indefinidamente o
             * TTL curto que uma resposta vazia recebe.
             */
            $fontes = (array) Cache::get($chave, []);
        } else {
            $fontes = $this->consultarProvedor($provedor, $id, $titulo, $ano, $imdbId, $temporada, $episodio);

            Cache::put($chave, $fontes, $fontes === [] ? $ttlVazio : $ttl);
        }

        $this->censo[$id]['consultas']++;
        $this->censo[$id]['brutas'] += count($fontes);
        $this->censo[$id]['ms'] += (int) ((hrtime(true) - $inicio) / 1_000_000);

        if ($doCache) {
            $this->censo[$id]['do_cache']++;
        }

        return $fontes;
    }

    /**
     * Chama o provedor e absorve a falha, para o cache guardar resultado e não
     * exceção. Uma fonte que levantou erro é devolvida como lista vazia — o
     * provedor seguinte da cascata continua tendo a vez.
     *
     * @return array<int, array<string, mixed>>
     */
    private function consultarProvedor(
        ProvedorTorrents $provedor,
        string $id,
        string $titulo,
        ?int $ano,
        ?string $imdbId,
        ?int $temporada,
        ?int $episodio,
    ): array {
        try {
            $fontes = $provedor->buscar($titulo, $ano, $imdbId, $temporada, $episodio);

            Log::debug('Provedor de torrents respondeu.', [
                'provedor' => $id,
                'titulo' => $titulo,
                'temporada' => $temporada,
                'episodio' => $episodio,
                'fontes' => count($fontes),
            ]);

            return $fontes;
        } catch (\Throwable $excecao) {
            $this->censo[$id]['erros']++;

            report($excecao);

            return [];
        }
    }

    /**
     * Consulta um provedor de lote com todos os termos do degrau de uma vez.
     *
     * É o mesmo contrato do `buscarComCache()`, com uma diferença: a chave de
     * cache cobre o **conjunto** de termos, não um termo. O provedor responde o
     * degrau inteiro numa rodada, então o cache tem de guardar essa rodada — e
     * invalidá-la quando a lista de termos mudar (outra temporada, outro título).
     *
     * @param  ProvedorTorrents&ProvedorPorLote  $provedor
     * @param  array<int, string>  $termos
     * @return array<int, array<string, mixed>>
     */
    private function buscarLoteComCache(
        ProvedorTorrents $provedor,
        array $termos,
        ?int $ano,
        ?string $imdbId,
        ?int $temporada = null,
        ?int $episodio = null,
    ): array {
        $id = $provedor->identificador();

        $chave = 'torrent:provedor:'.self::VERSAO_CACHE.':'.$id.':lote:'
            .md5(mb_strtolower(implode('|', $termos)).'|'.$ano.'|'.$imdbId.'|'.$temporada.'|'.$episodio);

        $ttl = (int) config('services.torrents.cache_ttl', 1800);

        $bypass = (bool) config('services.torrents.cache_bypass', false);

        $doCache = ! $bypass && Cache::has($chave);

        if ($bypass) {
            Cache::forget($chave);
        }

        $inicio = hrtime(true);

        $fontes = Cache::remember($chave, $ttl, function () use ($provedor, $id, $termos, $ano, $imdbId, $temporada, $episodio) {
            try {
                $fontes = $provedor->buscarVarios($termos, $ano, $imdbId, $temporada, $episodio);

                Log::debug('Provedor de torrents respondeu em lote.', [
                    'provedor' => $id,
                    'termos' => count($termos),
                    'fontes' => count($fontes),
                ]);

                return $fontes;
            } catch (\Throwable $excecao) {
                $this->censo[$id]['erros']++;

                report($excecao);

                return [];
            }
        });

        $this->censo[$id]['consultas']++;
        $this->censo[$id]['brutas'] += count($fontes);
        $this->censo[$id]['ms'] += (int) ((hrtime(true) - $inicio) / 1_000_000);

        if ($doCache) {
            $this->censo[$id]['do_cache']++;
        }

        return $fontes;
    }

    /**
     * Junta listas de fontes sem repetir a mesma release.
     *
     * Quando o mesmo torrent aparece em mais de uma lista, fica a leitura cujo
     * áudio é melhor — dublado antes de dual, dual antes de original —, e não a
     * do provedor que chegou primeiro. É a leitura do arquivo que classifica a
     * fonte, não o provedor: o identificador consultado no começo da cascata
     * devolvia o release como "original" só porque o rótulo do addon não trazia
     * a faixa, e essa leitura pior apagava o dublado que a busca por nome já
     * tinha provado para o mesmo infohash.
     *
     * @param  array<int, array<string, mixed>>  ...$listas
     * @return array<int, array<string, mixed>>
     */
    private function mesclar(array ...$listas): array
    {
        $fontes = [];
        $indices = [];

        foreach ($listas as $lista) {
            foreach ($lista as $fonte) {
                $chave = (string) ($fonte['id'] ?? '');

                if ($chave === '') {
                    continue;
                }

                if (! isset($indices[$chave])) {
                    $indices[$chave] = count($fontes);
                    $fontes[] = $fonte;

                    continue;
                }

                $atual = $fontes[$indices[$chave]];

                if (! $this->audioMelhor($fonte, $atual)) {
                    continue;
                }

                /*
                 * A leitura vencedora substitui a antiga, mas a marca de pack é
                 * uma propriedade do torrent — não da leitura — e não pode se
                 * perder na troca, sob pena de o pack deixar de atravessar o
                 * corte de idioma.
                 */
                if (! empty($atual['pack'])) {
                    $fonte['pack'] = true;
                }

                $fontes[$indices[$chave]] = $fonte;
            }
        }

        return $fontes;
    }

    /**
     * Diz se a nova leitura de uma fonte tem áudio melhor que a que já está na
     * lista. Empate no idioma desempata pelos seeds, para a troca não rebaixar
     * uma fonte mais saudável pela mesma classificação.
     *
     * @param  array<string, mixed>  $nova
     * @param  array<string, mixed>  $atual
     */
    private function audioMelhor(array $nova, array $atual): bool
    {
        $prioridadeNova = IdiomaFonte::tryFrom($nova['idioma'] ?? '')?->prioridade() ?? 99;
        $prioridadeAtual = IdiomaFonte::tryFrom($atual['idioma'] ?? '')?->prioridade() ?? 99;

        if ($prioridadeNova !== $prioridadeAtual) {
            return $prioridadeNova < $prioridadeAtual;
        }

        return (int) ($nova['seeds'] ?? 0) > (int) ($atual['seeds'] ?? 0);
    }
}
