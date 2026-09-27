<?php

namespace App\Services\Torrents;

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
    private const VERSAO_CACHE = 12;

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
    ) {
        $this->primarios = [$trackersBr, $apibay, $knaben, $bt4g];
        $this->porIdentificador = [$torrentio, $addonStremio];
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

        /*
         * Os provedores por identificador (Torrentio) são consultados uma única
         * vez, com o termo puro. O termo não muda a resposta deles — só o
         * `imdb_id` importa —, então repetir a chamada para cada variação dublada
         * seria gastar o provedor mais lento da cascata à toa.
         *
         * O que ele devolve entra na coleta e conta para o orçamento de fontes
         * PT-BR. Antes, esta fonte era a que encerrava o laço no primeiro termo —
         * o Torrentio respondia uma única fonte rotulada "Dublado" (S01E01 720p
         * PORTUGUÊS BR) e as variações dubladas nunca eram perguntadas. O
         * orçamento muda isso: ele olha o **acumulado** contra uma meta, então
         * uma fonte boa não cala a busca, só a aproxima do fim.
         */
        $fontes = $this->mesclar(
            $fontes,
            $this->buscarGrupo($this->porIdentificador, $titulos[0] ?? '', $ano, $imdbId, $temporada, $episodio)
        );

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
         */
        foreach ($titulos as $titulo) {
            $termoDePack = $this->eTermoDePack($titulo, $temporada, $episodio);
            $termoDeSerie = $this->eTermoDeSerie($titulo, $temporada, $episodio);

            $desteTermo = $this->buscarGrupo($this->primarios, $titulo, $ano, $imdbId, $temporada, $episodio, $termoDePack, $termoDeSerie);
            $fontes = $this->mesclar($fontes, $desteTermo);

            $this->registrarEtapa('nativos', $titulo, $desteTermo, $termoDeSerie, $this->barradasPeloGate);

            if ($this->coletaSuficiente($fontes)) {
                return $fontes;
            }
        }

        // Degrau 2: indexador. Só é consultado se o degrau nativo não juntou a meta
        // de fontes PT-BR — e para assim que a meta é alcançada, sem varrer todos
        // os termos restantes.
        foreach ($titulos as $titulo) {
            $termoDePack = $this->eTermoDePack($titulo, $temporada, $episodio);
            $termoDeSerie = $this->eTermoDeSerie($titulo, $temporada, $episodio);

            $desteTermo = $this->buscarGrupo([$this->torznab], $titulo, $ano, $imdbId, $temporada, $episodio, $termoDePack, $termoDeSerie);
            $fontes = $this->mesclar($fontes, $desteTermo);

            $this->registrarEtapa('indexador', $titulo, $desteTermo, $termoDeSerie, $this->barradasPeloGate);

            if ($this->coletaSuficiente($fontes)) {
                return $fontes;
            }
        }

        // Degrau 3: reserva em inglês. Entra sempre que nada dublado apareceu.
        // Em episódio o YTS se abstém sozinho (é catálogo só de filmes), então a
        // chamada é inofensiva e mantém a cascata com um formato único.
        $fontes = $this->mesclar(
            $fontes,
            $this->buscarGrupo([$this->yts], $titulos[0] ?? '', $ano, $imdbId, $temporada, $episodio)
        );

        return $fontes;
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
    ): array {
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
            if (! $provedor->disponivel()) {
                /*
                 * Regra da credencial ausente: pular o provedor e deixar a
                 * cascata seguir. Nada de erro bloqueante — a falta de uma chave
                 * reduz o alcance, não impede a busca.
                 */
                Log::info('Provedor de torrents pulado por falta de configuração.', [
                    'provedor' => $provedor->identificador(),
                ]);

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

        return $this->aproveitaveis($fontes, $temporada, $episodio, $termoDeSerie);
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
     * Um pack marcado e sem PT-BR provado segue para `confirmarIdiomaDoPack()`,
     * que tenta provar o dublado pelo conteúdo.
     *
     * @param  array<int, array<string, mixed>>  $fontes
     * @return array<int, array<string, mixed>>
     */
    private function marcarPacks(array $fontes, ?int $temporada, ?int $episodio, bool $termoDePack): array
    {
        if ($temporada === null || $episodio === null) {
            return $fontes;
        }

        return array_map(function (array $fonte) use ($temporada, $termoDePack): array {
            $nome = (string) ($fonte['release'] ?? $fonte['titulo'] ?? '');

            if ($nome === '' || TermosBusca::numeracaoDoTitulo($nome) !== null) {
                return $fonte;
            }

            $ePack = TermosBusca::temporadaNoRelease($nome, $temporada)
                || ($termoDePack && TermosBusca::temporadaDoTitulo($nome) === null);

            if (! $ePack) {
                return $fonte;
            }

            $fonte['pack'] = true;

            return $this->confirmarIdiomaDoPack($fonte);
        }, $fontes);
    }

    /**
     * Confirma, pelo conteúdo, se um pack sem idioma no nome é dublado.
     *
     * O nome do pack é a primeira prova de idioma, mas muitos packs nacionais
     * não a trazem: o "Dublado" mora na pasta de dentro ("Temporada 1 Dublado/")
     * ou no nome de cada episódio. Quando o nome deixa o idioma em aberto — nem
     * dublado, nem dual —, abrimos os metadados pelo media-service e lemos os
     * caminhos. Se algum prova PT-BR, o idioma do pack é promovido a dublado e
     * ele passa a contar como fonte boa na lista.
     *
     * O esforço é limitado de propósito: cada abertura é uma conexão e uma
     * espera, e a busca não pode virar uma varredura de dezenas de packs. O teto
     * (`inspecao_packs_limite`) e o conjunto de ids já vistos valem por busca.
     *
     * Falha de leitura não rebaixa nada: o pack fica como estava (reserva) e a
     * próxima busca tenta de novo — o cache só guarda veredito definitivo.
     *
     * @param  array<string, mixed>  $fonte
     * @return array<string, mixed>
     */
    private function confirmarIdiomaDoPack(array $fonte): array
    {
        $idioma = (string) ($fonte['idioma'] ?? '');

        // O nome já provou PT-BR: não há o que inspecionar.
        if (in_array($idioma, [IdiomaFonte::DUBLADO->value, IdiomaFonte::DUAL_AUDIO->value], true)) {
            return $fonte;
        }

        $magnet = (string) ($fonte['magnet'] ?? '');
        $id = (string) ($fonte['id'] ?? '');
        $limite = (int) config('services.torrents.inspecao_packs_limite', 6);

        if ($magnet === '' || $id === ''
            || isset($this->packsInspecionados[$id])
            || $this->inspecoes >= $limite) {
            return $fonte;
        }

        $this->packsInspecionados[$id] = true;
        $this->inspecoes++;

        if ($this->inspecao->apurar($magnet, $id) !== true) {
            return $fonte;
        }

        $fonte['idioma'] = IdiomaFonte::DUBLADO->value;
        $fonte['idioma_rotulo'] = IdiomaFonte::DUBLADO->rotulo();
        $fonte['idioma_por_inspecao'] = true;

        return $fonte;
    }

    /**
     * Descarta, ainda dentro da cascata, o que não serve para o pedido.
     *
     * São dois cortes de elegibilidade e uma etiqueta:
     *
     * 1. **Numeração** — releases que declaram uma temporada/episódio diferente
     *    da pedida. Releases sem numeração passam, para não apagar packs e nomes
     *    nacionais legítimos.
     * 2. **Temporada (só no termo de série)** — quando a fonte veio de um termo
     *    de série, o silêncio deixa de ser inocente: sem numeração que a
     *    localize, ela precisa declarar a temporada pedida ou é descartada. É o
     *    gate que a largueza do termo de série torna obrigatório.
     * 3. **Etiqueta de idioma** — cada fonte que passa recebe `pt_br` conforme o
     *    idioma deduzido (dublado e dual valem). O idioma **não** descarta mais
     *    nada aqui: o que não é PT-BR vira reserva, e a montagem final decide
     *    quanto dela entra para completar o mínimo de fontes. Antes, o descarte
     *    acontecia cedo e a lista ficava sem com o que preencher.
     *
     * @param  array<int, array<string, mixed>>  $fontes
     * @return array<int, array<string, mixed>>
     */
    private function aproveitaveis(array $fontes, ?int $temporada, ?int $episodio, bool $termoDeSerie = false): array
    {
        $resultado = [];

        foreach ($fontes as $fonte) {
            if ($temporada !== null && $episodio !== null
                && ! TermosBusca::correspondeAoEpisodio((string) ($fonte['titulo'] ?? ''), $temporada, $episodio)) {
                continue;
            }

            /*
             * Gate de temporada para fontes vindas de um termo de série.
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
             * Releases com numeração seguem julgados pelo
             * `correspondeAoEpisodio()` acima: o gate só olha o que não tem
             * número para provar a temporada.
             */
            if ($termoDeSerie && $temporada !== null && $episodio !== null) {
                $nome = (string) ($fonte['release'] ?? $fonte['titulo'] ?? '');

                if (TermosBusca::numeracaoDoTitulo($nome) === null
                    && ! TermosBusca::temporadaNoRelease($nome, $temporada)) {
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
     * A resposta vazia também é cacheada: um site que não tem o filme hoje (ou
     * está fora do ar) seria martelado a cada abertura do player sem que houvesse
     * chance de mudar de resposta dentro do TTL.
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
        $chave = 'torrent:provedor:'.self::VERSAO_CACHE.':'.$provedor->identificador().':'
            .md5(mb_strtolower($titulo).'|'.$ano.'|'.$imdbId.'|'.$temporada.'|'.$episodio);

        $ttl = (int) config('services.torrents.cache_ttl', 1800);

        return Cache::remember($chave, $ttl, function () use ($provedor, $titulo, $ano, $imdbId, $temporada, $episodio) {
            try {
                $fontes = $provedor->buscar($titulo, $ano, $imdbId, $temporada, $episodio);

                Log::debug('Provedor de torrents respondeu.', [
                    'provedor' => $provedor->identificador(),
                    'titulo' => $titulo,
                    'temporada' => $temporada,
                    'episodio' => $episodio,
                    'fontes' => count($fontes),
                ]);

                return $fontes;
            } catch (\Throwable $excecao) {
                report($excecao);

                return [];
            }
        });
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
