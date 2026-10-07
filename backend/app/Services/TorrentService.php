<?php

namespace App\Services;

use App\Services\Torrents\BuscaLegendas;
use App\Services\Torrents\CatalogoProvedores;
use App\Services\Torrents\OrcamentoBusca;
use App\Services\Torrents\TermosBusca;
use App\Support\MensagensTorrent;
use App\Enums\IdiomaFonte;
use Illuminate\Support\Facades\Log;

/**
 * Busca de fontes para um filme ou episódio de série.
 *
 * Este serviço é a fachada do subsistema de fontes: o controller conhece apenas
 * `fontes()` e o contrato normalizado que ele devolve (título, qualidade,
 * idioma, tamanho, seeds, magnet ou stream). Quem consulta o quê, em que ordem e
 * com qual cache é responsabilidade do [`CatalogoProvedores`].
 *
 * A busca usa **dois métodos de indexação**, e os dois rodam sempre, nesta
 * ordem:
 *
 * 1. **Stream direto** — o scraper web que troca o magnet por uma URL tocável
 *    (MP4/HLS). Roda primeiro porque não depende de malha: quando o acervo web
 *    tem o título, o player toca sem esperar o tracker.
 * 2. **Torrents** — a cascata de provedores por nome e por identificador. Roda
 *    depois e soma o que os trackers têm, cobrindo o que o scraper não achou.
 *
 * A divisão é intencional:
 *
 * - **CatalogoProvedores** cuida da infraestrutura — o registro dos provedores, a
 *   cascata de fallback, o cache por provedor e a tolerância a falha.
 * - **TorrentService** cuida da regra de negócio — quais títulos buscar, como
 *   ordenar, o que descartar e o que registrar no log.
 *
 * Assim, acrescentar um provedor novo não encosta na regra de ordenação, e mudar
 * a política de idioma não encosta em HTTP.
 */
class TorrentService
{
    /**
     * Reserva em idioma não-provado da última passagem pelos torrents.
     *
     * `ordenar()` separa a lista em PT-BR provado e reserva (o original e o
     * legendado). Com o corte duro de idioma ligado, a reserva é descartada da
     * resposta — mas ela é a matéria-prima do fallback legendado: quando não há
     * PT-BR nenhum, é dessa reserva (mais as fontes diretas de idioma original)
     * que sai a lista devolvida ao player, agora com as legendas anexadas.
     * Zerada no começo de cada busca para não carregar resíduo da anterior.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $reservaDaBusca = [];

    public function __construct(
        private readonly CatalogoProvedores $catalogo,
        private readonly OrcamentoBusca $orcamento,
        /*
         * O provedor de legendas é opcional pelo mesmo motivo do passe no
         * [`ClienteHttp`]: os testes constroem o serviço só com o catálogo e o
         * orçamento, e sem legendas injetadas o fallback legendado não roda —
         * comportamento antigo (lista vazia quando não há PT-BR). Em produção o
         * container injeta a implementação real, e o fallback vale.
         */
        private readonly ?BuscaLegendas $legendas = null,
    ) {
    }

    /**
     * Fontes disponíveis para um título, já ordenadas por prioridade.
     *
     * Tenta o stream direto primeiro. Quando ele acha, a busca **termina ali**:
     * a fonte direta toca sem esperar malha e é devolvida sozinha. Sem fonte
     * direta, roda a cascata de torrents e devolve o que ela juntar.
     *
     * Dentro de cada método a ordenação coloca o dublado em PT-BR primeiro e,
     * dentro do mesmo idioma, as fontes com mais seeds. Fontes de torrent sem
     * seeds são descartadas: não têm como servir dados e só fariam o frontend
     * perder tempo tentando.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fontes(
        string $titulo,
        ?int $ano = null,
        ?string $imdbId = null,
        ?string $tituloOriginal = null,
        ?int $temporada = null,
        ?int $episodio = null,
        ?string $tmdbId = null,
    ): array {
        // Quando temporada e episódio vêm preenchidos, a busca é de um episódio
        // de série: o termo passa a ser "Titulo S01E02" em vez do título solto.
        // Sem eles, o fluxo de filme segue exatamente como antes.
        $episodioDeSerie = $temporada !== null && $episodio !== null;

        /*
         * O stream direto roda primeiro e, quando acha, **encerra a busca**: a
         * fonte que ele devolve toca sem esperar malha, então não há razão para
         * gastar mais tempo perguntando aos trackers — a cascata de torrents só
         * entra quando a web não tem o título. A ordem é a prioridade do player,
         * que tenta as fontes na ordem da lista.
         *
         * Antes, a idade do título decidia por qual método começar e, às vezes,
         * qual único método rodar. Era uma aposta sobre onde o conteúdo estaria,
         * e errava nos dois sentidos: um título recente que só um agregador web
         * tinha esperava o orçamento inteiro dos torrents, e um título antigo com
         * pack vivo nos trackers nunca via o indexador. Agora a ordem é fixa e o
         * primeiro método que entrega responde pela busca.
         *
         * O relógio é um só, com teto global, e continua valendo nos dois
         * cenários: quando a cascata é dispensada, ele apenas é fechado cedo;
         * quando os dois métodos rodam (o direto voltou vazio), ele garante que a
         * soma das esperas não passe do que o frontend tolera.
         */
        $this->orcamento->definirTetoGlobal(
            (int) config('services.torrents.tempo_total_busca', 55)
        );

        $titulos = [];
        // Resíduo da busca anterior não pode contaminar o fallback desta.
        $this->reservaDaBusca = [];

        // Guardamos TODAS as fontes diretas (inclusive as de idioma original):
        // as PT-BR respondem pela busca; as demais viram matéria-prima do
        // fallback legendado, quando nenhum provedor tiver áudio em português.
        $todasDiretas = $this->buscarPeloStreamDireto($titulo, $ano, $imdbId, $tituloOriginal, $temporada, $episodio, $tmdbId, $titulos);

        /*
         * O corte de idioma roda **na busca**, e não só na montagem final.
         *
         * A lista direta não passa por `ordenar()` — ela substitui o resultado
         * antes disso —, então sem esta barreira uma fonte direta de idioma não
         * declarado (o `original` que a página não etiquetou) entrava como
         * resposta, dispensava a cascata e mandava para o player um áudio que o
         * usuário não pediu. O idioma só aparecia como problema na preparação da
         * exibição, quando o estrago já estava feito: a cascata de torrents nunca
         * era ouvida naquele título.
         *
         * Com o filtro aqui, o critério é o mesmo da cascata — só áudio PT-BR
         * provado (dublado ou dual) responde pela busca. Uma fonte direta sem
         * PT-BR declarado é descartada, e a busca segue para o próximo provedor,
         * exatamente como se o direto tivesse voltado vazio. É o que permite ao
         * fallback de torrents achar o dublado que o acervo web não tinha.
         */
        $diretas = array_values(array_filter(
            $todasDiretas,
            fn (array $fonte): bool => $this->ePtBr($fonte)
        ));

        /*
         * Com a fonte direta em PT-BR na mão, a cascata é dispensada — e o censo
         * dos provedores de torrent é zerado junto, pela mesma razão que o
         * relatório existe: sem isso ele mostraria os números e as fontes da
         * busca anterior como se fossem desta, e a cobertura diria que os
         * trackers entregaram o que foi, na verdade, achado no acervo web.
         */
        if ($diretas !== []) {
            $this->catalogo->dispensarCascata();

            $fontes = $diretas;
        } else {
            $fontes = $this->buscarPelosTorrents($titulo, $ano, $imdbId, $tituloOriginal, $temporada, $episodio, $episodioDeSerie, $titulos);
        }

        /*
         * Nenhum provedor entregou áudio PT-BR. Com o fallback legendado ligado,
         * em vez de devolver a lista vazia ("ainda não disponível em português")
         * a busca oferece as fontes de idioma ORIGINAL — as diretas e a reserva
         * dos torrents — com as legendas (PT-BR quando existir, senão inglês)
         * anexadas a cada uma. O player toca o áudio original e o usuário lê a
         * legenda. Sem legenda utilizável, o método devolve vazio e o
         * comportamento antigo permanece.
         */
        if ($fontes === []) {
            $fontes = $this->montarFallbackLegendado($todasDiretas, $imdbId, $temporada, $episodio);
        }

        /*
         * O censo é contado dentro de cada método, antes da montagem final. Aqui
         * ele é reconciliado com a lista que de fato saiu, para o relatório não
         * dizer `com_fonte` de um provedor cujas fontes foram todas descartadas
         * depois — era o caso do APIBay, que aparecia como `com_fonte` sem ter
         * nada na lista. A leitura acontece com o censo dos dois métodos ainda de
         * pé: o do stream direto foi preservado quando a cascata de torrents
         * reiniciou o próprio censo.
         */
        $this->catalogo->reconciliarCenso($fontes);

        /*
         * O orçamento é fechado aqui, e não dentro de cada método. Ele é
         * compartilhado entre o stream direto e a cascata de torrents — os dois
         * rodam na mesma busca e o teto global cobre a soma deles. Fechá-lo ao fim
         * de um método deixaria o outro sem prazo e reabriria um relógio novo do
         * zero, que é justamente como os orçamentos somavam e estouravam o tempo
         * do frontend. Este é o único ponto por onde todos os desfechos passam,
         * então é aqui que a busca inteira encerra o relógio.
         */
        $this->catalogo->fecharOrcamento();

        $this->registrar($fontes, $titulos, $ano, $imdbId);

        return $fontes;
    }

    /**
     * Cascata de torrents: as duas fases de título, a ordenação e o corte.
     *
     * A busca acontece em duas fases, e não numa lista única de títulos. A
     * primeira pergunta pelo título traduzido — é o que os trackers brasileiros
     * publicam, e é a aposta certa na maioria dos casos. A segunda, pelo título
     * original, só entra quando a primeira não juntou PT-BR suficiente: aí a
     * tradução falhou em achar o release nacional e vale tentar o nome
     * internacional. Quem decide a segunda fase é `valeSegundaTentativa()`.
     *
     * A cascata roda mesmo quando a lista de títulos vem vazia. Antes havia um
     * `return []` preventivo, e o usuário recebia "nenhuma fonte encontrada" sem
     * que o Torrentio ou os indexadores tivessem sido ouvidos. Os provedores por
     * identificador (Torrentio, addons Stremio) não dependem do termo — respondem
     * pelo `imdb_id` —, então lista vazia não é motivo para não perguntar.
     *
     * A lista de títulos usados é acumulada em `$titulos` por referência, porque
     * o registro final precisa dela — o mesmo registro que o stream direto
     * alimenta, já que os dois métodos compõem a busca.
     *
     * @param  array<int, string>  $titulos
     * @return array<int, array<string, mixed>>
     */
    private function buscarPelosTorrents(
        string $titulo,
        ?int $ano,
        ?string $imdbId,
        ?string $tituloOriginal,
        ?int $temporada,
        ?int $episodio,
        bool $episodioDeSerie,
        array &$titulos,
    ): array {
        $fasePtBr = $episodioDeSerie
            ? $this->titulosDeEpisodio($titulo, $temporada, $episodio)
            : $this->titulosDeBusca($titulo);

        $fontes = $this->catalogo->buscar($fasePtBr, $ano, $imdbId, $temporada, $episodio);
        $titulos = $fasePtBr;

        if ($this->valeSegundaTentativa($fontes, $titulo, $tituloOriginal)) {
            $faseOriginal = $episodioDeSerie
                ? $this->titulosDeEpisodio($tituloOriginal, $temporada, $episodio)
                : $this->titulosDeBusca($tituloOriginal);

            if ($faseOriginal !== []) {
                $fontes = $this->mesclarFontes(
                    $fontes,
                    $this->catalogo->buscar($faseOriginal, $ano, $imdbId, $temporada, $episodio)
                );

                $titulos = array_merge($titulos, $faseOriginal);
            }
        }

        return $this->ordenar($fontes, $temporada, $episodio);
    }

    /**
     * Método de indexação do stream direto: o scraper web que extrai o vídeo da
     * página.
     *
     * Roda **primeiro** na busca, antes da cascata de torrents. Não é gatilho nem
     * fallback: a fonte direta tem prioridade para o player porque não depende de
     * malha — quando o acervo web tem o título, ele toca na hora e a busca acaba
     * ali, sem consultar tracker nenhum. A cascata de torrents só roda quando ele
     * volta vazio.
     *
     * O scraper recebe o título traduzido e o original. Como ele roda antes da
     * segunda fase da cascata, `$titulos` ainda costuma estar vazio, então a lista
     * de termos é montada a partir do traduzido mais o original — que é justamente
     * o que funciona para o conteúdo raro ("Desperate Housewives" acha o que
     * "Donas de Casa Desesperadas" não acha).
     *
     * As fontes diretas entram já montadas e não voltam por `ordenar()`: passá-las
     * pela ordenação, que espera magnet, só as descartaria.
     *
     * @param  array<int, string>  $titulos
     * @return array<int, array<string, mixed>>
     */
    private function buscarPeloStreamDireto(
        string $titulo,
        ?int $ano,
        ?string $imdbId,
        ?string $tituloOriginal,
        ?int $temporada,
        ?int $episodio,
        ?string $tmdbId,
        array &$titulos,
    ): array {
        $titulosDoFallback = $titulos;

        if ($titulosDoFallback === []) {
            $titulosDoFallback[] = $titulo;
        }

        $original = trim((string) $tituloOriginal);

        if ($original !== '' && ! in_array($original, $titulosDoFallback, true)) {
            $titulosDoFallback[] = $original;
        }

        Log::debug('Busca de torrents: acionando o stream direto.', [
            'titulos' => $titulosDoFallback,
            'temporada' => $temporada,
            'episodio' => $episodio,
        ]);

        $fontes = $this->catalogo->buscarFallbackDireto($titulosDoFallback, $ano, $imdbId, $temporada, $episodio, $tmdbId);

        /*
         * Os termos consultados ficam registrados no acumulador da busca. Quando o
         * direto acha, a cascata não roda e ninguém mais escreveria nele — e o log
         * final sairia com a lista de termos vazia justamente no caminho em que a
         * fonte veio.
         */
        $titulos = $titulosDoFallback;

        Log::debug('Busca de torrents: stream direto devolveu.', [
            'fontes' => count($fontes),
        ]);

        return $fontes;
    }

    /**
     * Confirma se existe algum provedor utilizável na configuração atual.
     *
     * O controller usa isto para escolher a mensagem do aviso: "nenhuma fonte
     * encontrada" (o sistema procurou e não achou) é diferente de "nenhum
     * provedor pôde ser consultado" (falta configuração).
     */
    public function temProvedorDisponivel(): bool
    {
        return $this->catalogo->algumDisponivel();
    }

    /**
     * Relatório de cobertura da última busca: o que cada provedor respondeu.
     *
     * A fachada expõe isto porque o controller só conhece `fontes()` e este
     * relatório — assim "perguntamos a todos?" se responde sem abrir o catálogo.
     * Precisa ser lido logo após `fontes()`, enquanto o censo daquela busca ainda
     * está de pé: a próxima busca zera os contadores.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cobertura(): array
    {
        return $this->catalogo->cobertura();
    }

    /**
     * Diz se vale disparar a segunda fase, pelo título original.
     *
     * A segunda fase existe para o caso em que a tradução não acha o release
     * nacional — "Homem-Aranha" devolve o desenho, "Spider-Man" devolve o filme.
     * Mas ela custa orçamento e traz releases em inglês, então só entra quando a
     * primeira fase ficou abaixo da meta PT-BR. Três portas fecham a passagem:
     * a chave desligada, a ausência de um título original distinto e a coleta já
     * suficiente. A leitura da suficiência é do catálogo, que conhece a meta.
     *
     * @param  array<int, array<string, mixed>>  $fontes
     */
    private function valeSegundaTentativa(array $fontes, string $titulo, ?string $tituloOriginal): bool
    {
        if (! config('services.torrents.titulo_original_segunda_tentativa', true)) {
            return false;
        }

        $original = trim((string) $tituloOriginal);

        if ($original === '' || $original === trim($titulo)) {
            return false;
        }

        return ! $this->catalogo->ptBrSuficiente($fontes);
    }

    /**
     * Junta as fontes das duas fases, sem repetir.
     *
     * A mesma fonte pode voltar nas duas buscas — um release nacional que casa
     * tanto o título traduzido quanto o original. A chave é o `id` (o infohash),
     * que é o que identifica o torrent de verdade; comparar por título deixaria
     * passar duplicata com nome ligeiramente diferente. A primeira fase fica na
     * frente: ela é a aposta PT-BR e deve manter a prioridade na ordenação.
     *
     * @param  array<int, array<string, mixed>>  $primeira
     * @param  array<int, array<string, mixed>>  $segunda
     * @return array<int, array<string, mixed>>
     */
    private function mesclarFontes(array $primeira, array $segunda): array
    {
        $vistos = [];

        foreach ($primeira as $fonte) {
            $vistos[(string) ($fonte['id'] ?? '')] = true;
        }

        foreach ($segunda as $fonte) {
            $chave = (string) ($fonte['id'] ?? '');

            if ($chave !== '' && isset($vistos[$chave])) {
                continue;
            }

            $vistos[$chave] = true;
            $primeira[] = $fonte;
        }

        return $primeira;
    }

    /**
     * Monta a lista de títulos a tentar, sem repetir.
     *
     * Recebe um título só porque a busca virou duas fases: o traduzido e o
     * original são consultados em momentos diferentes, não numa lista única. O
     * TMDB é consultado em PT-BR, então o título traduzido é o que os trackers
     * brasileiros publicam; o original é a segunda tentativa, disparada por
     * `valeSegundaTentativa()` quando a primeira não basta.
     *
     * @return array<int, string>
     */
    private function titulosDeBusca(string $titulo): array
    {
        $titulo = trim($titulo);

        return $titulo !== '' ? [$titulo] : [];
    }

    /**
     * Monta os termos de busca de um episódio, sem repetir.
     *
     * Cada episódio é um release próprio, então o termo precisa da numeração
     * "SxxExx". Recebe um título só porque a busca virou duas fases: o traduzido
     * e o original são consultados em momentos diferentes, não numa lista única.
     * O tracker nacional publica pelo nome em PT-BR e os indexadores
     * internacionais pelo original — cada fase pergunta pelo seu.
     *
     * Além do termo puro, o título rende as variações dubladas
     * ("... S01E01 dublado", "... S01E01 dual áudio"). Sem elas a cascata nunca
     * pergunta pelo release nacional: o termo puro devolve dezenas de lançamentos
     * em inglês e o dublado fica fora da primeira página dos provedores por nome.
     * Era por isso que uma série só trazia fontes "Idioma original" mesmo com o
     * indexador PT-BR configurado.
     *
     * A ordem importa: o termo puro vem primeiro porque é o que o Torrentio (busca
     * por identificador) ignora e os provedores por nome usam como base; as
     * variações dubladas entram logo depois para puxar o release nacional.
     *
     * @return array<int, string>
     */
    private function titulosDeEpisodio(
        string $titulo,
        int $temporada,
        int $episodio,
    ): array {
        $titulos = [];

        $candidato = trim($titulo);

        if ($candidato !== '') {
            $termo = TermosBusca::episodio($candidato, $temporada, $episodio);

            if (! in_array($termo, $titulos, true)) {
                $titulos[] = $termo;
            }

            foreach (TermosBusca::episodioDublado($candidato, $temporada, $episodio) as $dublado) {
                if (! in_array($dublado, $titulos, true)) {
                    $titulos[] = $dublado;
                }
            }
        }

        /*
         * Os packs entram por último, e a posição é a decisão que protege os
         * episódios que hoje funcionam: a cascata para no primeiro termo que
         * devolve dublado, então uma série recente continua sendo resolvida só
         * com os termos de episódio. O pack só é consultado quando nenhum deles
         * achou nada — que é exatamente o caso das séries antigas, cujos
         * episódios isolados já não têm seeds.
         */
        if (config('services.torrents.packs_habilitados', true) && $candidato !== '') {
            $packs = array_merge(
                TermosBusca::packTemporada($candidato, $temporada),
                TermosBusca::packTemporadaDublado($candidato, $temporada),
            );

            foreach ($packs as $pack) {
                if (! in_array($pack, $titulos, true)) {
                    $titulos[] = $pack;
                }
            }
        }

        /*
         * Os termos de série entram por último — depois até dos packs —, e a
         * posição é o que protege o que hoje funciona: a cascata para no primeiro
         * termo que devolve dublado, então uma série recente é resolvida muito
         * antes de chegar aqui. Esta frente existe para o caso extremo, a série
         * antiga em que nem o episódio nem o "S01 completa" acham nada: os
         * buscadores por nome casam todas as palavras do termo, e "S01E01" e
         * "completa" são ruído suficiente para zerar o recall justamente onde os
         * packs nacionais vivem. Sem numeração e sem "completa", o termo pergunta
         * pela série pelo nome, que é como os packs multi-temporada aparecem
         * ("1ª 2ª 3ª Temporadas Dublado e Legendado"). Quem descarta o que não
         * cobrir a temporada pedida é o gate do [`CatalogoProvedores`].
         *
         * A esta altura entram também os termos amplos ([`TermosBusca::serieAmpla()`]):
         * nome da série com a temporada e, por fim, só o nome, sem tag de áudio.
         * É a pergunta mais larga que existe, e é de propósito — o Knaben casa
         * todas as palavras do termo, então tirar "dublado" e a numeração de
         * episódio é o que deixa o pack nacional aparecer na busca em lote. O
         * parser interno valida o episódio e classifica o idioma depois.
         */
        if (config('services.torrents.termos_serie_habilitados', true) && $candidato !== '') {
            foreach (TermosBusca::serieDublado($candidato, $temporada) as $serie) {
                if (! in_array($serie, $titulos, true)) {
                    $titulos[] = $serie;
                }
            }

            foreach (TermosBusca::serieAmpla($candidato, $temporada) as $ampla) {
                if (! in_array($ampla, $titulos, true)) {
                    $titulos[] = $ampla;
                }
            }
        }

        return $titulos;
    }

    /**
     * Filtra e monta a lista final.
     *
     * O filtro de seeds é a primeira barreira contra fontes mortas: fonte sem
     * seed ou sem magnet não entra, porque não tem como servir dados e só faria o
     * player perder tempo. Depois, a montagem é por mérito de áudio, em duas
     * chaves: **o idioma manda** e, dentro do mesmo idioma, **o episódio vem antes
     * do pack**. Assim o dublado de episódio é o primeiro tentado, o pack dublado
     * vem logo atrás, e o pack nunca passa à frente de um episódio de áudio igual
     * ou melhor — era o que fazia o pack do Torrentio encobrir os episódios do
     * Knaben, do TPB+ e do APIBay.
     *
     * A lista final é **só áudio PT-BR provado** — dublado e dual. Quando as
     * PT-BR acabam, a lista para: não há preenchimento com reserva. O usuário quer
     * ouvir em português, e uma lista curta de dublado vale mais que uma lista
     * cheia de original que ele não vai tentar. A reserva só volta quando não há
     * **nenhuma** PT-BR: aí a lista vazia seria o player sem nada para tentar, e
     * entregar o que existe — mesmo legendado ou original — é melhor que nada.
     *
     * @param  array<int, array<string, mixed>>  $fontes
     * @param  int|null  $temporada  Temporada pedida, quando a busca é de série
     * @param  int|null  $episodio   Episódio pedido, quando a busca é de série
     * @return array<int, array<string, mixed>>
     */
    private function ordenar(array $fontes, ?int $temporada = null, ?int $episodio = null): array
    {
        $ordemProvedores = $this->ordemDeProvedores();

        $somentePtBr = (bool) config('services.torrents.somente_pt_br_ou_legendado', true);
        $packsQualquerIdioma = (bool) config('services.torrents.packs_qualquer_idioma', true);

        $fontes = array_values(array_filter($fontes, function (array $fonte) use ($packsQualquerIdioma, $temporada, $episodio): bool {
            /*
             * A fonte direta (MP4/HLS) não tem magnet nem malha para medir: o
             * filtro de torrent não se aplica a ela. O que a valida é ter uma URL
             * de vídeo — sem ela, não há o que o media-service possa tocar.
             */
            if (($fonte['tipo'] ?? 'torrent') === 'direto') {
                return ($fonte['stream'] ?? '') !== '';
            }

            if (($fonte['seeds'] ?? 0) <= 0 || ($fonte['magnet'] ?? '') === '') {
                return false;
            }

            /*
             * Confronto de temporada do pack na montagem final.
             *
             * O gate da cascata já reprova o pack da temporada errada, mas ele
             * julga pelo nome do release e pode ser enganado por um nome que não
             * declara a temporada. Aqui a checagem é a última linha de defesa: um
             * pack que declara uma temporada diferente da pedida não entra na
             * lista, mesmo sendo dual — era justamente o pack dual da 2ª
             * temporada, com mais seeds, que subia ao topo de uma busca da 1ª e
             * fazia o player abrir o episódio errado.
             *
             * Só vale para pack: um episódio já foi julgado por numeração na
             * cascata e não deve ser descartado aqui.
             */
            if ($temporada !== null && $episodio !== null && ! empty($fonte['pack'])
                && $this->packDaTemporadaErrada($fonte, $temporada)) {
                return false;
            }

            /*
             * O pack de idioma não provado é o socorro da série antiga e quase
             * nunca vem marcado como dublado. Ele só entra na lista pela exceção de
             * `packs_qualquer_idioma`; desligada, vale o corte de idioma normal e
             * ele é descartado. O pack com PT-BR provado não depende da exceção.
             */
            if (! empty($fonte['pack']) && ! $this->ePtBr($fonte)) {
                return $packsQualquerIdioma;
            }

            return true;
        }));

        $porMerito = fn (array $a, array $b): int
            => $this->chaveDeOrdem($a, $ordemProvedores) <=> $this->chaveDeOrdem($b, $ordemProvedores);

        $ptBr = [];
        $reserva = [];

        foreach ($fontes as $fonte) {
            if ($this->ePtBr($fonte)) {
                $ptBr[] = $fonte;
            } else {
                $reserva[] = $fonte;
            }
        }

        usort($ptBr, $porMerito);
        usort($reserva, $porMerito);

        $limite = MensagensTorrent::LIMITE_FONTES;

        /*
         * Corte duro de idioma: com `somente_pt_br_ou_legendado` ligado, a lista
         * final é só o áudio PT-BR provado — dublado e dual. O original em inglês
         * e o legendado (áudio original com legenda PT-BR) saem de vez, porque o
         * usuário quer ouvir em português, não ler.
         *
         * Não há preenchimento com reserva: quando as PT-BR acabam, a lista para.
         * O teto de fontes é um corte (`array_slice`), nunca uma cota a preencher
         * — se houver menos que ele, a lista sai menor e está correta assim.
         *
         * Sem nenhuma fonte PT-BR, a lista sai **vazia** — e não com a reserva.
         * Antes, o corte era revertido e o original voltava para o player "não
         * ficar sem nada"; o efeito colateral era o fallback de stream direto
         * nunca disparar, porque a lista nunca ficava vazia. A reserva em inglês
         * não é resposta para quem pediu português: o que resolve o conteúdo raro
         * é o stream direto, e é o `TorrentService::fontes()` que o aciona logo
         * depois, ao ver a lista vazia. A reserva só volta quando o corte está
         * desligado (`somente_pt_br_ou_legendado = false`), aí sim o usuário
         * aceita qualquer idioma.
         */
        // A reserva fica guardada para o fallback legendado: é o original que a
        // resposta descarta quando não há PT-BR, e já vem filtrado e ordenado —
        // matéria-prima pronta para quando nenhum áudio em português aparecer.
        $this->reservaDaBusca = array_slice($reserva, 0, $limite);

        if ($somentePtBr) {
            return array_slice($ptBr, 0, $limite);
        }

        return array_slice(array_merge($ptBr, $reserva), 0, $limite);
    }

    /**
     * Ordem canônica dos provedores, do mais forte para o mais fraco.
     *
     * É a mesma ordem em que a cascata os consulta — os por identificador primeiro
     * (Torrentio e o addon Stremio), depois os nativos, o indexador e o YTS. Como o
     * idioma já decide a faixa principal, esta chave só desempata dentro da mesma
     * faixa, mantendo cada provedor em bloco em vez de intercalá-los por seeds.
     *
     * @return array<string, int>
     */
    private function ordemDeProvedores(): array
    {
        return [
            'torrentio' => 0,
            'addon_stremio' => 1,
            'trackers_br' => 2,
            'apibay' => 3,
            'knaben' => 4,
            'bt4g' => 5,
            'torznab' => 6,
            'yts' => 7,
            /*
             * A fonte direta não chega aqui pelo nome: o `provedor` dela carrega o
             * site de origem (o agregador onde o vídeo foi achado), e é o `tipo`
             * que a identifica. O degrau serve para `chaveDeOrdem()` posicioná-la
             * depois de todos os torrents quando ela entra pela ordenação — na
             * lista final ela já vem à frente, concatenada antes dos torrents.
             */
            'stream_direto' => 8,
        ];
    }

    /**
     * Chave de ordenação de uma fonte: idioma, episódio antes de pack, provedor e
     * seeds. Comparada em bloco, ela entrega a ordem final sem nenhuma regra
     * escondida dentro do comparador.
     *
     * @param  array<string, mixed>  $fonte
     * @param  array<string, int>  $ordemProvedores
     * @return array<int, int>
     */
    private function chaveDeOrdem(array $fonte, array $ordemProvedores): array
    {
        $idioma = IdiomaFonte::tryFrom((string) ($fonte['idioma'] ?? ''))?->prioridade() ?? 99;
        $pack = ! empty($fonte['pack']) ? 1 : 0;

        /*
         * A fonte direta se identifica pelo `tipo`: o `provedor` dela é o site de
         * origem, que não tem degrau na tabela. Sem este desvio, ela cairia no
         * `99` genérico e ficaria atrás até do que a tabela marca como último —
         * o desempate deixaria de ser o combinado.
         */
        $provedor = ($fonte['tipo'] ?? '') === 'direto'
            ? ($ordemProvedores['stream_direto'] ?? 99)
            : ($ordemProvedores[$fonte['provedor'] ?? ''] ?? 99);

        return [$idioma, $pack, $provedor, -(int) ($fonte['seeds'] ?? 0)];
    }

    /**
     * Diz se a fonte serve para a pilha boa da montagem.
     *
     * A etiqueta `pt_br` da cascata vem primeiro — ela já embute a promoção dos
     * packs cujo conteúdo provou o dublado. O idioma cru cobre a chamada fora da
     * cascata. Ficam de fora os packs de idioma não provado: eles não são áudio
     * PT-BR, são o socorro da série antiga. Vão para a reserva e, por serem pack,
     * só aparecem depois dos episódios de qualquer idioma — é o que mantém o pack
     * do Torrentio atrás dos episódios do Knaben, do TPB+ e do APIBay.
     *
     * @param  array<string, mixed>  $fonte
     */
    private function ePtBr(array $fonte): bool
    {
        if (($fonte['pt_br'] ?? null) === true) {
            return true;
        }

        /*
         * A fonte direta não passa pelo gate da cascata, então não carrega a
         * etiqueta `pt_br`. O idioma dela é deduzido do rótulo do agregador e
         * basta para a montagem: sem esta leitura, um link direto dublado cairia
         * na reserva e o corte de idioma o descartaria — justamente a fonte que o
         * fallback achou para o conteúdo raro.
         */
        return in_array(
            $fonte['idioma'] ?? '',
            [IdiomaFonte::DUBLADO->value, IdiomaFonte::DUAL_AUDIO->value],
            true
        );
    }

    /**
     * Diz se um pack declara uma temporada diferente da pedida.
     *
     * É a última linha de defesa contra o pack da temporada errada. O gate da
     * cascata julga pelo nome do release, mas pode ser enganado por um nome que
     * não declara a temporada; aqui a leitura é a mesma, só que aplicada depois de
     * toda a coleta, quando não há mais como o pack escapar.
     *
     * A regra é conservadora: só reprova quando o nome **declara** uma temporada
     * que não cobre a pedida. Um pack sem número nenhum passa — é o caso dos
     * nomes nacionais legítimos, e descartá-los apagaria o socorro da série
     * antiga. A leitura usa [`TermosBusca::temporadaNoRelease()`], que aceita
     * faixas ("S01-S05", "1ª 2ª 3ª Temporadas") e por isso não reprova o pack
     * multi-temporada que inclui a pedida.
     *
     * @param  array<string, mixed>  $fonte
     */
    private function packDaTemporadaErrada(array $fonte, int $temporada): bool
    {
        /*
         * A leitura olha os dois nomes da fonte. O `release` é o nome do torrent
         * quando o provedor o informa, mas em alguns provedores ele acaba sendo
         * o nome do arquivo interno ("2x13 - Madness Ends"): aí a numeração de
         * episódio esconderia a temporada que o `titulo` declara, e o pack da 2ª
         * passaria por esta última linha de defesa.
         */
        $nomes = array_values(array_unique(array_filter([
            (string) ($fonte['release'] ?? ''),
            (string) ($fonte['titulo'] ?? ''),
        ], fn (string $valor): bool => $valor !== '')));

        if ($nomes === []) {
            return false;
        }

        $declarada = TermosBusca::temporadaDosNomes($nomes);

        /*
         * Um pack que não declara temporada em nome nenhum não é pack de
         * temporada — é um episódio mal marcado. Deixamos passar: quem julga
         * episódio é a numeração, e reprovar aqui apagaria uma fonte que a
         * cascata aprovou.
         */
        if ($declarada === null) {
            return false;
        }

        /*
         * A temporada declarada pode ser uma faixa (o pack cobre várias). Nesse
         * caso `temporadaNoRelease()` é quem decide: se a pedida está dentro da
         * faixa, o pack serve. Só quando a leitura simples aponta uma temporada
         * única e diferente da pedida é que reprovamos.
         */
        if (TermosBusca::algumNomeCobreTemporada($nomes, $temporada)) {
            return false;
        }

        return $declarada !== $temporada;
    }

    /**
     * Monta o fallback legendado: fontes de idioma original + legendas.
     *
     * É o caminho de quando a busca inteira — acervo web e trackers — ficou sem
     * áudio PT-BR. Em vez do aviso "ainda não disponível em português", o usuário
     * recebe as fontes de idioma original que existem, com as legendas anexadas
     * para poder acompanhar. A ordem põe as diretas primeiro: elas tocam sem
     * esperar malha, então são a melhor experiência quando existem.
     *
     * A oferta só se concretiza com **pelo menos uma legenda**. O áudio original
     * sem legenda não serve ao usuário brasileiro — a regra existe justamente
     * para tornar o original assistível —, e nesse caso é mais honesto manter o
     * aviso de indisponibilidade do que servir um vídeo que ele não entenderia.
     *
     * @param  array<int, array<string, mixed>>  $todasDiretas  Fontes diretas cruas da busca
     * @return array<int, array<string, mixed>>
     */
    private function montarFallbackLegendado(
        array $todasDiretas,
        ?string $imdbId,
        ?int $temporada,
        ?int $episodio,
    ): array {
        if (! config('services.torrents.legendas_fallback', true) || $this->legendas === null) {
            return [];
        }

        // Das diretas, só as de idioma original interessam aqui — as PT-BR, se
        // houvesse alguma, já teriam respondido pela busca.
        $originais = array_values(array_filter(
            $todasDiretas,
            fn (array $fonte): bool => ! $this->ePtBr($fonte)
        ));

        $candidatas = $this->mesclarFontes($originais, $this->reservaDaBusca);

        if ($candidatas === []) {
            return [];
        }

        $tipo = ($temporada !== null && $episodio !== null) ? 'tv' : 'movie';
        $legendas = $this->legendas->buscar($imdbId, $tipo, $temporada, $episodio);

        if ($legendas === []) {
            return [];
        }

        $candidatas = array_slice($candidatas, 0, MensagensTorrent::LIMITE_FONTES);

        foreach ($candidatas as &$fonte) {
            $fonte['legendas'] = $legendas;
        }

        unset($fonte);

        Log::info('Fallback legendado: sem áudio PT-BR, servindo o original com legendas.', [
            'imdb_id' => $imdbId,
            'temporada' => $temporada,
            'episodio' => $episodio,
            'fontes' => count($candidatas),
            'legendas' => array_column($legendas, 'srclang'),
        ]);

        return array_values($candidatas);
    }

    /**
     * Registra no log por que a lista veio como veio.
     *
     * O log é a única forma de distinguir "não existe release dublado" de "a
     * classificação de idioma falhou": se há fontes e nenhuma dublada, o índice
     * funciona e a escassez é real; se não há fontes nenhuma, o problema é de
     * rede, de provedor fora do ar ou de configuração.
     *
     * @param  array<int, array<string, mixed>>  $fontes
     * @param  array<int, string>  $titulos
     */
    private function registrar(array $fontes, array $titulos, ?int $ano, ?string $imdbId): void
    {
        $ptBr = count(array_filter($fontes, fn (array $fonte) => $this->ePtBr($fonte)));

        $contexto = [
            'titulos' => $titulos,
            'ano' => $ano,
            'imdb_id' => $imdbId,
            'fontes' => count($fontes),
            // O par que conta a montagem: quantas PT-BR vieram no topo e quanta
            // reserva foi necessária para chegar ao mínimo. Sem ele, "a lista veio
            // cheia de original" fica indistinguível de "não havia dublado".
            'pt_br' => $ptBr,
            'reserva' => count($fontes) - $ptBr,
        ];

        if (empty($fontes)) {
            Log::warning('Nenhuma fonte de torrent encontrada para o título.', $contexto);

            return;
        }

        if (! $this->catalogo->temDublado($fontes)) {
            Log::info('Há fontes, mas nenhuma dublada em PT-BR para o título.', $contexto);
        }
    }
}
