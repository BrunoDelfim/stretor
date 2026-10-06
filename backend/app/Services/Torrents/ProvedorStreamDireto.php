<?php

namespace App\Services\Torrents;

use App\Contracts\ProvedorTorrents;
use App\Support\FiltroConteudoAdulto;
use App\Support\IndiciosPtBr;
use Illuminate\Support\Facades\Log;

/**
 * Provedor de stream direto — o scraper web do conteúdo raro.
 *
 * A cascata inteira existe para achar um magnet com peers. Quando ela termina
 * vazia, é porque o release simplesmente não tem mais ninguém semeando: o filme
 * antigo, o episódio dublado que só um tracker morto tinha. Para esse caso, este
 * provedor troca a estratégia — em vez de procurar um torrent, ele **raspa a
 * web** atrás de um link de vídeo tocável (MP4 ou HLS).
 *
 * Ele **não** é um degrau da cascata: o [`CatalogoProvedores`] o aciona antes
 * dela e, quando ele acha, a busca **acaba ali** — a fonte direta toca sem
 * malha, e perguntar aos trackers só atrasaria a exibição. A cascata entra
 * apenas quando a web não tem o título. A fonte que ele devolve sai com
 * `tipo=direto` e o campo `stream` preenchido; o `magnet` fica vazio de
 * propósito, e é o `tipo` que diz ao player para seguir pelo media-service
 * (proxy/remux) em vez do WebTorrent.
 *
 * Por dentro, a varredura obedece à mesma ideia: o atalho por id do TMDB
 * primeiro, os agregadores na ordem declarada em seguida e, por último, o motor
 * de busca aberto — e a **primeira** fonte achada encerra o fluxo. Nenhuma
 * origem depois dela é consultada.
 *
 * O fluxo tem quatro etapas, e cada uma tem um serviço próprio:
 *
 * 1. **Termo.** [`TermosStreamDireto`] monta a query inteligente — título,
 *    numeração do episódio e intenção de streaming ("assistir online dublado").
 * 2. **Busca.** [`MotorBuscaWeb`] resolve o termo em páginas candidatas, usando
 *    o DuckDuckGo HTML/Lite (sem chave de API).
 * 3. **Extração.** [`ExtratorVideo`] abre cada página e varre o HTML atrás de
 *    `<video src>`, `.mp4`/`.m3u8` e configuração de player JS.
 * 4. **Montagem.** [`NormalizaFonte::montarFonteDireta()`] traduz o link no
 *    contrato do frontend.
 *
 * Há uma quinta etapa, que só roda quando a extração volta vazia: o
 * [`ResolvedorEmbed`] percorre o iframe de player conhecido — o `plenoflu.com`,
 * que o `verpobreflix.net` embute — até o arquivo de vídeo. Sem ela, a página
 * que só embute o player de terceiro era descartada: o iframe prova que há
 * mídia, mas não é mídia.
 *
 * A descoberta é autônoma: não há mais dependência de uma API externa estática
 * em `stream_direto_fontes`. O provedor só precisa estar ligado
 * (`stream_direto_habilitado`) para funcionar.
 *
 * Como a busca web é frágil por natureza (motor bloqueia, HTML muda, acervo não
 * cobre), o provedor registra cada etapa em `debug`/`warning`. Quando a lista sai
 * vazia, o log diz exatamente onde parou: se o motor não respondeu, se respondeu
 * sem links, ou se os links não tinham vídeo.
 */
class ProvedorStreamDireto implements ProvedorTorrents
{
    use NormalizaFonte;
    use ConsultaComOrcamento;
    use TermosStreamDireto;
    use RelevanciaTitulo;

    /**
     * Frases que denunciam o placeholder de um site morto.
     *
     * O `assistaonline.tv` respondia 200 com a página "Deployment Paused" do
     * Vercel — o projeto expirou e o domínio ficou apontando para o placeholder
     * da hospedagem. O status não denuncia, mas o corpo sim. As frases são
     * inteiras de propósito: "paused" sozinho apareceria num player pausado.
     *
     * @var array<int, string>
     */
    private const MARCAS_DE_SITE_MORTO = [
        'deployment paused',
        'this deployment has been paused',
        'site not found',
        'this site is temporarily unavailable',
        'account suspended',
        'domain is parked',
        'this domain is for sale',
    ];

    public function __construct(
        private readonly OrcamentoBusca $orcamento,
        private readonly MotorBuscaWeb $motor,
        private readonly ExtratorVideo $extrator,
        private readonly ClienteHttp $cliente,
        private readonly BuscaAgregadores $agregadores,
        private readonly ResolvedorEmbed $resolvedor,
        private readonly ResolvedorVod $vod,
    ) {
    }

    public function identificador(): string
    {
        return 'stream_direto';
    }

    public function rotulo(): string
    {
        return 'Stream direto';
    }

    /**
     * O provedor está disponível quando o fallback está ligado.
     *
     * Antes, a disponibilidade dependia de haver uma fonte configurada em
     * `stream_direto_fontes` — sem API apontada, não havia o que consultar. Com o
     * scraper autônomo, a chave liga/desliga é a única condição: o motor de busca
     * e o extrator já vêm embutidos.
     */
    public function disponivel(): bool
    {
        return (bool) config('services.torrents.stream_direto_habilitado', false);
    }

    /**
     * Censo da busca direta da última varredura, para o relatório de cobertura.
     *
     * O provedor é **um** na cobertura — a linha `stream_direto` diz que ele foi
     * consultado e quanto tempo levou —, mas quem de fato é perguntado são os
     * agregadores de vídeo. Sem esta lista, um fallback que volta vazio deixa o
     * relatório sem resposta para a única pergunta que importa ali: o superflix foi
     * procurado? O que ele devolveu?
     *
     * @return array<int, array<string, mixed>>
     */
    public function censoDosAgregadores(): array
    {
        return $this->agregadores->censo();
    }

    /**
     * Entrada do contrato: um título só.
     *
     * O catálogo chama `buscarComTitulos()` quando tem as variações do nome; este
     * método existe para respeitar o contrato e para os testes que exercitam o
     * provedor isolado.
     */
    public function buscar(
        string $titulo,
        ?int $ano = null,
        ?string $imdbId = null,
        ?int $temporada = null,
        ?int $episodio = null,
        ?string $tmdbId = null,
    ): array {
        return $this->buscarComTitulos([$titulo], $ano, $imdbId, $temporada, $episodio, $tmdbId);
    }

    /**
     * Busca usando todas as grafias do título.
     *
     * O primeiro título é o principal (PT-BR); os demais entram como rede de
     * segurança na camada genérica de termos — é o que cobre o caso em que o
     * acervo PT-BR não tem página nenhuma.
     *
     * O `$tmdbId` alimenta o **passo zero**: a fonte endereçável pelo id do
     * provedor de VOD, que dispensa a busca por título. Sem ele (ou quando o
     * provedor não tem o título), a varredura por motor e agregadores segue
     * normalmente.
     *
     * A varredura para na **primeira fonte**. Cada origem da lista — o atalho por
     * id, os agregadores na ordem declarada e, por fim, o motor de busca aberto —
     * é tentada até que uma delas renda, e quem rende primeiro responde pela
     * busca: as origens seguintes não são consultadas. É o que mantém a resposta
     * curta, porque cada consulta pode custar uma renderização de navegador; com
     * uma fonte tocável na mão, insistir só atrasaria a exibição.
     *
     * @param  array<int, string>  $titulos
     * @return array<int, array<string, mixed>>
     */
    public function buscarComTitulos(
        array $titulos,
        ?int $ano = null,
        ?string $imdbId = null,
        ?int $temporada = null,
        ?int $episodio = null,
        ?string $tmdbId = null,
    ): array {
        if (! $this->disponivel()) {
            return [];
        }

        $titulos = $this->titulosLimpos($titulos);
        $titulo = $titulos[0] ?? '';

        if ($titulo === '') {
            return [];
        }

        $alternativos = array_slice($titulos, 1);
        $termos = $this->termosDeStreaming($titulo, $temporada, $episodio, $alternativos);

        if ($termos === []) {
            return [];
        }

        Log::debug('Stream direto: iniciando busca.', [
            'titulo' => $titulo,
            'alternativos' => $alternativos,
            'temporada' => $temporada,
            'episodio' => $episodio,
            'termos' => $termos,
        ]);

        $fontes = [];
        $paginasVisitadas = [];
        $tetoPaginas = (int) config('services.torrents.stream_direto_max_paginas', 6);
        $termosComResultado = 0;

        /*
         * O teto de fontes é o limite do que o direto entrega, e não a meta que
         * ele persegue: quem encerra a varredura é o primeiro acerto, não uma
         * contagem. Antes era o contrário — o laço seguia abrindo candidatas até
         * juntar duas fontes, e essa segunda abertura era parte do tempo que o
         * usuário sentia na resposta. Agora a primeira página que rende encerra o
         * fluxo inteiro (ver `visitar()`), e o teto só corta o excesso de
         * espelhos que uma mesma página pode trazer.
         *
         * Zero ou negativo desliga o corte.
         */
        $tetoFontes = (int) config('services.torrents.stream_direto_max_fontes', 2);

        /*
         * O teto de cada consulta ao motor e a margem de segurança que impede
         * começar uma requisição que não caberia no orçamento. Sem a margem, a
         * última consulta começava a 1 s do fim, esperava o teto cheio e devolvia
         * zero — o orçamento inteiro gasto sem nada entregue.
         *
         * O stream direto tem o relógio do próprio canal (`stream_direto`), não o
         * da cascata de torrents. O laço respeita esse orçamento via
         * `temTempoParaConsulta()` e para quando o que resta não cobre uma
         * consulta; o teto global da busca, em [`OrcamentoBusca::abrir()`], limita
         * o canal por cima.
         */
        $tetoConsulta = (int) config('services.torrents.stream_direto_tempo_limite', 10);

        /*
         * Passo zero: a fonte endereçável pelo id do TMDB.
         *
         * É a única consulta que não depende de descobrir página nenhuma — o
         * provedor recebe o id e devolve o arquivo. Por isso ela vem antes de
         * tudo: quando resolve, o motor de busca e os agregadores não têm o que
         * acrescentar que valha o orçamento, e a cascata nem começa. Quando não
         * há fonte para o id (ou o id não veio), o retorno é `null` e a varredura
         * segue exatamente como era.
         */
        $peloId = $this->vod->resolver($tmdbId, $temporada, $episodio, $tetoConsulta);

        if ($peloId !== null) {
            Log::debug('Stream direto: fonte resolvida pelo id do TMDB.', [
                'tmdb_id' => $tmdbId,
                'url' => $peloId['url'],
            ]);

            return $this->fonteResolvida($peloId, $titulo, (string) ($tmdbId ?? ''));
        }

        /*
         * O censo dos agregadores é zerado aqui, no começo da varredura, e não
         * dentro do gerador: ele precisa nascer limpo mesmo quando `candidatas()`
         * devolve cedo (busca direta desligada, nenhum título) — e é esta lista
         * que a cobertura mostra dentro da linha do stream direto, para responder
         * se o superflix chegou a ser consultado.
         */
        $this->agregadores->reiniciarCenso();

        $parar = false;

        /*
         * A busca direta nos agregadores vem **antes** do motor web. O motor
         * aberto é a peça frágil da corrente: para conteúdo raro ele devolve
         * quase só plataforma legal (que a lista negra descarta) e, quando os
         * motores grandes estão suspensos, nada. O agregador sabe onde o
         * episódio mora; perguntar direto a ele é o caminho que não depende do
         * motor. As páginas que voltam daqui entram no mesmo crivo das que vêm
         * do motor — a origem do link muda, o tratamento não.
         *
         * A varredura é **degrau a degrau**: um agregador é consultado e as suas
         * candidatas são abertas em ordem até uma render. Se nenhuma render, o
         * próximo agregador é perguntado; se alguma render, a busca inteira acaba
         * ali e o motor web nem chega a rodar. É a mesma cascata dos provedores de
         * torrent, e a diferença é de custo, não de forma — abrir a página é a
         * parte caríssima do provedor, e um agregador morto não pode consumir o
         * orçamento que o seguinte precisava para achar o episódio.
         */
        foreach ($this->agregadores->candidatas($titulos, $temporada, $episodio) as $dominio => $diretas) {
            Log::debug('Stream direto: candidatas da busca direta no agregador.', [
                'dominio' => $dominio,
                'candidatas' => count($diretas),
            ]);

            $parar = $this->visitar(
                $diretas,
                $titulo,
                $temporada,
                $episodio,
                $paginasVisitadas,
                $fontes,
                $tetoFontes,
                $tetoConsulta,
            );

            if ($parar) {
                break;
            }
        }

        if (! $parar) {
            foreach ($termos as $termo) {
                if (! $this->temTempoParaConsulta($tetoConsulta)) {
                    Log::debug('Stream direto: orçamento insuficiente para o próximo termo.', [
                        'termo' => $termo,
                        'restante' => $this->orcamento->restante(),
                    ]);

                    break;
                }

                $candidatas = $this->motor->procurar($termo);

                if ($candidatas === []) {
                    continue;
                }

                $termosComResultado++;

                if ($this->visitar(
                    $candidatas,
                    $titulo,
                    $temporada,
                    $episodio,
                    $paginasVisitadas,
                    $fontes,
                    $tetoFontes,
                    $tetoConsulta,
                )) {
                    break;
                }
            }
        }

        $unicas = $this->deduplicar($fontes);

        /*
         * O rastro dos domínios que passaram pelo filtro e foram abertos. Sem
         * ele, "visitei 4 páginas" não diz se o orçamento foi para agregadores
         * de vídeo ou para catálogos que escaparam da lista negra.
         */
        Log::debug('Stream direto: domínios consultados.', [
            'dominios' => $this->dominiosDe(array_keys($paginasVisitadas)),
        ]);

        /*
         * O censo por agregador vai para o log no mesmo formato que a cobertura
         * devolve na API. É o que permite ler, de dentro do container, a quem o
         * fallback perguntou e o que cada um respondeu — sem precisar chamar o
         * `/fontes` e garimpar a resposta.
         */
        Log::debug('Stream direto: censo dos agregadores.', [
            'agregadores' => $this->agregadores->censo(),
        ]);

        Log::debug('Stream direto: busca concluída.', [
            'titulo' => $titulo,
            'termos_com_resultado' => $termosComResultado,
            'paginas_visitadas' => count($paginasVisitadas),
            'fontes' => count($unicas),
        ]);

        if ($unicas === []) {
            Log::info('Stream direto: nenhuma fonte encontrada.', [
                'titulo' => $titulo,
                'paginas_visitadas' => count($paginasVisitadas),
            ]);
        }

        return $unicas;
    }

    /**
     * Corta a lista no teto de fontes do direto.
     *
     * A ordem de chegada é preservada: os primeiros links que a página apresentou
     * são os que ficam. O corte roda **durante** a varredura porque uma única
     * página pode oferecer vários espelhos do mesmo vídeo — sem ele, a resposta
     * do direto sairia com mirrors que o usuário não distingue, um por vez, sem
     * acrescentar nada à escolha dele.
     *
     * Zero ou negativo desliga o corte: aí a lista vai inteira, como era antes.
     *
     * @param  array<int, array<string, mixed>>  $fontes
     * @return array<int, array<string, mixed>>
     */
    private function limitarFontes(array $fontes, int $teto): array
    {
        return $teto > 0 ? array_slice($fontes, 0, $teto) : $fontes;
    }

    /**
     * Percorre uma lista de páginas candidatas e devolve se a busca deve parar.
     *
     * As candidatas chegam de duas origens — a **busca direta nos agregadores**
     * e o **motor de busca web** — e as duas passam pelo mesmo crivo: barreira
     * de conteúdo impróprio, pulo de página já visitada e extração. Concentrar o
     * laço aqui evita duas cópias da mesma regra e garante que um filtro novo
     * valha para as duas origens de uma vez.
     *
     * A parada é a **primeira fonte**: assim que uma página rende, a função
     * devolve `true` e quem chamou encerra o fluxo — o próximo agregador nem é
     * perguntado e o motor web não chega a rodar. É o contrato de prioridade do
     * provedor: cada origem é tentada na ordem declarada, e a primeira que
     * entrega responde pela busca. O orçamento é a outra parada: sem tempo para
     * começar a próxima consulta, insistir só gastaria a espera que falta.
     *
     * @param  array<int, string>  $candidatas
     * @param  array<string, bool>  $paginasVisitadas
     * @param  array<int, array<string, mixed>>  $fontes
     * @return bool `true` quando o fluxo deve parar (achou fonte ou acabou o tempo)
     */
    private function visitar(
        array $candidatas,
        string $titulo,
        ?int $temporada,
        ?int $episodio,
        array &$paginasVisitadas,
        array &$fontes,
        int $tetoFontes,
        int $tetoConsulta,
    ): bool {
        foreach ($candidatas as $pagina) {
            if (! $this->temTempoParaConsulta($tetoConsulta)) {
                return true;
            }

            /*
             * A barreira de conteúdo impróprio roda antes de qualquer
             * requisição: um link adulto que escapou do motor de busca (ou veio
             * da busca do agregador) é descartado aqui, sem gastar orçamento nem
             * abrir a página.
             */
            if ($this->filtroAdultoAtivo() && FiltroConteudoAdulto::urlBloqueada($pagina)) {
                Log::warning('Stream direto: página imprópria descartada.', [
                    'pagina' => $pagina,
                ]);

                continue;
            }

            // A mesma página pode aparecer em origens diferentes; não vale
            // abri-la duas vezes.
            if (isset($paginasVisitadas[$pagina])) {
                continue;
            }

            $paginasVisitadas[$pagina] = true;

            $novas = $this->rasparPagina($pagina, $titulo, $temporada, $episodio);

            /*
             * O teto é aplicado a cada página e a ordem de chegada é preservada —
             * os primeiros links da página são os que ficam. Como o fluxo agora
             * para na primeira página que rende, este corte é o que impede um
             * agregador cheio de espelhos de encher a lista sozinho.
             */
            $fontes = $this->limitarFontes(array_merge($fontes, $novas), $tetoFontes);

            /*
             * A página rendeu: a varredura inteira acaba aqui. Seguir abrindo
             * candidatas depois de ter uma fonte tocável era o custo que fazia a
             * resposta demorar — e cada abertura pode custar uma renderização de
             * navegador.
             */
            if ($novas !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Abre uma página candidata e extrai as fontes diretas dela.
     *
     * A falha de uma página não derruba as outras: o `catch` devolve lista vazia
     * e o laço segue. É o mesmo princípio dos provedores de torrent — uma página
     * fora do ar reduz o alcance, não impede a busca.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rasparPagina(string $pagina, string $titulo, ?int $temporada = null, ?int $episodio = null): array
    {
        $teto = $this->tempoDeConsulta((int) config('services.torrents.stream_direto_tempo_limite', 10));

        if ($teto <= 0) {
            return [];
        }

        try {
            $resposta = $this->cliente->get($pagina, [], null, $teto);
        } catch (\Throwable $excecao) {
            Log::warning('Stream direto: falha ao raspar página.', [
                'pagina' => $pagina,
                'erro' => $excecao->getMessage(),
            ]);

            $resposta = null;
        }

        /*
         * O `get()` direto pode voltar vazio por dois motivos que o FlareSolverr
         * resolve: o site derruba a conexão de um cliente que não parece
         * navegador (o `verpobreflix.net` faz isso de forma intermitente), ou o
         * Cloudflare devolve o desafio. Nos dois casos, desistir aqui perdia a
         * página que o proxy buscava sem problema — foi o que fez a mesma
         * consulta achar o player numa rodada e "não responder" na seguinte.
         * O socorro só vale quando o proxy está configurado; sem ele, o
         * comportamento antigo (desistir) continua.
         */
        if ($resposta === null) {
            $renderizada = $this->cliente->getRenderizado($pagina, $teto);

            if ($renderizada !== null && ! $renderizada->failed()) {
                $resposta = $renderizada;
            } else {
                Log::debug('Stream direto: página não respondeu.', ['pagina' => $pagina]);

                return [];
            }
        }

        if ($resposta->failed()) {
            Log::debug('Stream direto: página devolveu erro HTTP.', [
                'pagina' => $pagina,
                'status' => $resposta->status(),
            ]);

            return [];
        }

        /*
         * A página bloqueada é abandonada antes de qualquer extração. Um 402
         * (paywall) ou 403 (Cloudflare) não tem player para achar: varrer o HTML
         * atrás de mídia só gastaria o orçamento que os termos seguintes
         * precisam. O descarte é registrado com o status para o log distinguir
         * "bloqueado" de "sem prova de mídia" — foi a falta dessa distinção que
         * escondeu, por várias rodadas, que os agregadores certos respondiam 402.
         */
        if ($this->cliente->bloqueada($resposta)) {
            Log::debug('Stream direto: página bloqueada descartada.', [
                'pagina' => $pagina,
                'status' => $resposta->status(),
            ]);

            return [];
        }

        $corpo = (string) $resposta->body();

        /*
         * O site morto é descartado antes de qualquer extração. O `assistaonline.tv`
         * respondia 200 com uma página "Deployment Paused" do Vercel — o status
         * não denuncia, mas o corpo sim. Sem esta checagem, a página ainda gastava
         * a prova de mídia e a renderização do FlareSolverr antes de ser recusada.
         */
        if ($this->pareceSiteMorto($corpo)) {
            Log::debug('Stream direto: página de site morto descartada.', ['pagina' => $pagina]);

            return [];
        }

        /*
         * A terceira linha de defesa: mesmo que a URL da página tenha passado
         * limpa, o **título** dela pode denunciar o conteúdo. Um agregador
         * legítimo que hospeda uma página adulta no meio do acervo familiar cai
         * aqui — o título é lido do HTML e confrontado com a lista de palavras
         * proibidas antes de qualquer extração.
         */
        $tituloPagina = $this->tituloDaPagina($corpo);

        if ($this->filtroAdultoAtivo() && $tituloPagina !== '' && FiltroConteudoAdulto::textoBloqueado($tituloPagina)) {
            Log::warning('Stream direto: página com título impróprio descartada.', [
                'pagina' => $pagina,
                'titulo' => $tituloPagina,
            ]);

            return [];
        }

        /*
         * A relevância é o segundo critério de aceitação, e vem antes da prova
         * de mídia de propósito. Ter player não basta: uma página de fandom
         * *sobre* a série embute o trailer e passa na prova de mídia, e um
         * agregador devolve um episódio qualquer de outro programa quando o
         * título pedido não está no acervo. Nos dois casos o player existe, mas
         * o vídeo não é o pedido — foi assim que "American Horror Story" abriu
         * um episódio aleatório. A página precisa provar que é sobre o título
         * antes de a extração gastar orçamento nela.
         */
        if (! $this->paginaRelevante($pagina, $tituloPagina, $titulo)) {
            Log::debug('Stream direto: página sem relação com o título descartada.', [
                'pagina' => $pagina,
                'titulo_pagina' => $tituloPagina,
                'titulo_buscado' => $titulo,
            ]);

            return [];
        }

        /*
         * A prova de mídia é o critério de aceitação. Antes de gastar a extração
         * completa, a página precisa provar que tem player: um arquivo de vídeo
         * ou um iframe de embed conhecido. É o que substitui a lista fixa de
         * domínios — um site desconhecido com player passa, um agregador famoso
         * sem player não passa.
         *
         * Quando a prova falha, ainda não é hora de descartar. Os sites de
         * streaming PT-BR (o `pobreflix.bike`, o `assistaonline.tv`) entregam um
         * HTML estático sem player nenhum: o `<video>` só nasce depois, quando o
         * JavaScript chama o `player-resolve` e injeta o iframe. O `Http::get()`
         * não roda esse script, então a página legítima parecia vazia. A segunda
         * tentativa entrega a página ao Chromium do FlareSolverr, que executa o
         * JavaScript até o player aparecer, e a prova de mídia é refeita sobre o
         * DOM já montado.
         */
        if (! $this->extrator->temMidia($corpo)) {
            $renderizado = $this->cliente->getRenderizado($pagina, $teto);

            if ($renderizado !== null && ! $renderizado->failed()) {
                $corpo = (string) $renderizado->body();
            }

            if (! $this->extrator->temMidia($corpo)) {
                /*
                 * A página pode ser a **ficha da série**, não a do episódio. O
                 * `verpobreflix.net` responde em `/series/american-horror-story`
                 * com a listagem de temporadas e episódios — sem player nenhum,
                 * porque o player mora na página do episódio
                 * (`/series/american-horror-story/temporada-1/episodio-1`). Sem
                 * esta descida, a página certa era aberta, provava ser sobre o
                 * título, e era descartada por "sem prova de mídia" — o episódio
                 * existia a um clique de distância.
                 *
                 * A descida só vale quando o episódio foi pedido e a URL atual
                 * ainda não carrega a numeração: se já é a página do episódio e
                 * não tem player, descer de novo não levaria a lugar nenhum.
                 */
                $linkEpisodio = $this->linkDoEpisodio($corpo, $pagina, $temporada, $episodio);

                if ($linkEpisodio !== null) {
                    Log::debug('Stream direto: descendo para a página do episódio.', [
                        'pagina' => $pagina,
                        'episodio' => $linkEpisodio,
                    ]);

                    return $this->rasparPagina($linkEpisodio, $titulo, $temporada, $episodio);
                }

                Log::debug('Stream direto: página sem prova de mídia descartada.', ['pagina' => $pagina]);

                return [];
            }
        }

        /*
         * Só o arquivo de vídeo vira fonte. O `extrair()` devolve exatamente os
         * links que terminam em `.mp4`, `.m3u8` e afins — o que o media-service
         * sabe ler.
         *
         * A colheita de embeds (`extrairEmbeds()`) **não** entra aqui, e a
         * distinção custou caro para aparecer. O contrato da fonte direta exige
         * uma URL MP4/HLS, e o `prepararSessaoDireta()` do media-service recusa
         * qualquer coisa fora de `EXTENSOES_DIRETAS` com `formato_desconhecido`.
         * Um iframe de player (`https://plenoflu.com/tvshow/1413/1/1`) é uma
         * **página**, não um vídeo: oferecê-lo como fonte entregava ao usuário
         * uma sessão que morria na primeira checagem. O `verpobreflix.net` foi o
         * caso que expôs isso — ele não hospeda vídeo nenhum, só embute um player
         * de terceiro que devolve "Acesso proibido" a qualquer cliente que não
         * seja o navegador do usuário final.
         *
         * O embed continua valendo como **prova de mídia** (é o que o
         * `temMidia()` usa para aceitar a página), mas não como fonte. Uma página
         * cujo único player é um embed inacessível agora é descartada em vez de
         * gerar uma fonte quebrada.
         *
         * O que mudou desde então foi o destino do embed **acessível**: ele deixou
         * de ser beco sem saída. Quando a página não entrega arquivo nenhum — e o
         * `verpobreflix.net` nunca entrega —, o [`ResolvedorEmbed`] percorre a
         * cadeia do player até o vídeo que estava dentro do iframe, e é esse
         * vídeo que vira fonte (ver `fonteResolvida()`). A recusa do **iframe**
         * como fonte segue de pé; o que entrou no lugar dele é o que ele escondia.
         */
        $urls = $this->extrator->extrair($corpo);

        if ($urls === []) {
            /*
             * Sem arquivo na página, o embed conhecido ainda pode levar a um. É
             * onde entra o [`ResolvedorEmbed`]: quando o agregador só embute o
             * player de terceiro — o `plenoflu.com`, no caso do
             * `verpobreflix.net` —, o vídeo mora do outro lado do iframe, e a
             * cadeia até ele se percorre sem navegador (o `master.m3u8` assinado
             * que o FirePlayer devolve é a prova disso).
             */
            $resolvido = $this->resolvedor->resolver($corpo, $pagina, $teto);

            if ($resolvido !== null) {
                return $this->fonteResolvida($resolvido, $titulo, $pagina);
            }

            Log::debug('Stream direto: página sem arquivo de vídeo extraível.', ['pagina' => $pagina]);

            return [];
        }

        /*
         * Cada link de vídeo passa por duas barreiras antes de virar fonte.
         *
         * A primeira é a de conteúdo impróprio: o vídeo pode estar hospedado num
         * CDN adulto (`xvideos-cdn.com`) mesmo que a página que o embute pareça
         * inocente. O descarte é silencioso no log de erro e registrado em
         * `debug` para não poluir a saída.
         *
         * A segunda é a de relevância do próprio vídeo. A página já provou ser
         * sobre o título, mas o vídeo embutido pode não ser: um agregador com a
         * página certa serve um `/video/historia-4` de outro programa. A URL do
         * vídeo precisa carregar ao menos uma palavra-chave do título — um slug
         * puramente numérico ou genérico é recusado aqui, antes de virar fonte.
         */
        $urls = array_values(array_filter(
            $urls,
            function (string $url) use ($pagina, $titulo): bool {
                if ($this->filtroAdultoAtivo() && FiltroConteudoAdulto::urlBloqueada($url)) {
                    Log::warning('Stream direto: vídeo de origem imprópria descartado.', [
                        'pagina' => $pagina,
                        'video' => $url,
                    ]);

                    return false;
                }

                if (! $this->videoRelevante($url, $titulo)) {
                    Log::debug('Stream direto: vídeo sem relação com o título descartado.', [
                        'pagina' => $pagina,
                        'video' => $url,
                        'titulo_buscado' => $titulo,
                    ]);

                    return false;
                }

                return true;
            }
        ));

        if ($urls === []) {
            Log::debug('Stream direto: página não tinha vídeo aproveitável.', ['pagina' => $pagina]);

            return [];
        }

        Log::debug('Stream direto: vídeos extraídos da página.', [
            'pagina' => $pagina,
            'videos' => count($urls),
        ]);

        $fontes = [];

        foreach ($urls as $url) {
            $fontes[] = $this->montarFonteDireta([
                'url' => $url,
                'titulo' => $this->limparTexto($titulo),
                'idioma' => $this->idiomaDaPagina($pagina),
            ], ...$this->origemDa($pagina));
        }

        return $fontes;
    }

    /**
     * Identifica o **site de origem** de uma página, para marcar a fonte.
     *
     * A fonte direta era rotulada só como `stream_direto`, o nome do método. Para
     * quem olha a lista isso não diz nada: dois links do mesmo agregador pareciam
     * de provedores diferentes, e não havia como saber de onde a lista veio. A
     * origem é o domínio da página onde o vídeo foi encontrado — é ela que o
     * usuário reconhece na etiqueta da fonte, ao lado de "Link direto".
     *
     * @return array{0: string, 1: string} Domínio e rótulo legível
     */
    private function origemDa(string $pagina): array
    {
        $dominio = $this->dominiosDe([$pagina])[0] ?? '';

        // O `www.` cai antes de qualquer coisa: sem isso, `www.site.com` e
        // `site.com` apareceriam como dois sites diferentes na etiqueta da fonte.
        $dominio = preg_replace('/^www\./', '', $dominio) ?? $dominio;

        if ($dominio === '') {
            return [$this->identificador(), $this->rotulo()];
        }

        return [$dominio, ucfirst($dominio)];
    }

    /**
     * Monta a fonte de um vídeo achado dentro de um embed.
     *
     * O caminho é separado do que monta as fontes lidas do HTML da página porque
     * as duas não passam pelas mesmas provas. O vídeo resolvido chega por uma
     * cadeia que **já** provou ser sobre o título — a relevância foi conferida na
     * página do episódio —, e a URL em si é um caminho de hash num CDN
     * (`/cdn/hls/831b0172.../master.m3u8?md5=...`), sem uma palavra do título para
     * conferir. Passar essa URL pelo [`videoRelevante()`] recusaria justamente o
     * link que a cadeia provou ser o certo.
     *
     * A barreira de conteúdo impróprio continua valendo: o CDN de destino pode
     * ser adulto mesmo quando a página e o player não são.
     *
     * @param  array{url: string, idioma: ?string}  $resolvido
     * @return array<int, array<string, mixed>>
     */
    private function fonteResolvida(array $resolvido, string $titulo, string $pagina): array
    {
        if ($this->filtroAdultoAtivo() && FiltroConteudoAdulto::urlBloqueada($resolvido['url'])) {
            Log::warning('Stream direto: vídeo de origem imprópria descartado.', [
                'pagina' => $pagina,
                'video' => $resolvido['url'],
            ]);

            return [];
        }

        Log::debug('Stream direto: embed percorrido até o arquivo.', [
            'pagina' => $pagina,
            'video' => $resolvido['url'],
        ]);

        return [$this->montarFonteDireta([
            'url' => $resolvido['url'],
            'titulo' => $this->limparTexto($titulo),
            'idioma' => $resolvido['idioma'] ?? $this->idiomaDaPagina($pagina),
        ], ...$this->origemDa($pagina))];
    }

    /**
     * Procura, na listagem da série, o link para a página do episódio pedido.
     *
     * O agregador costuma ter duas páginas distintas: a **ficha da série**
     * (`/series/american-horror-story`), que lista as temporadas e os episódios
     * mas não tem player nenhum, e a **página do episódio**
     * (`/series/american-horror-story/temporada-1/episodio-1`), que é onde o
     * player de fato mora. A busca aberta devolve a primeira, porque é a que o
     * título descreve; sem esta descida, a página certa era aberta, provava ser
     * sobre o título e era descartada por "sem prova de mídia" — com o episódio
     * a um clique de distância.
     *
     * A descida só faz sentido quando o episódio foi pedido e a URL atual ainda
     * não carrega a numeração. Se já estamos na página do episódio e ela não tem
     * player, descer de novo não levaria a lugar nenhum — daí a guarda contra a
     * recursão infinita.
     *
     * O casamento aceita as duas grafias que os agregadores usam para a mesma
     * coisa: `temporada-1/episodio-1` (em segmentos de caminho) e
     * `temporada-1-episodio-1` (num segmento só). O link pode vir relativo, e é
     * resolvido contra a página atual.
     */
    private function linkDoEpisodio(string $html, string $pagina, ?int $temporada, ?int $episodio): ?string
    {
        if ($temporada === null || $episodio === null || $html === '') {
            return null;
        }

        if ($this->enderecoJaTemEpisodio($pagina, $temporada, $episodio)) {
            return null;
        }

        $padrao = sprintf(
            '#href\s*=\s*["\']([^"\']*temporada[-/]%d[-/]episodio[-/]%d[^"\']*)["\']#i',
            $temporada,
            $episodio
        );

        if (! preg_match($padrao, $html, $casamento)) {
            return null;
        }

        $endereco = html_entity_decode($casamento[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $this->resolverEndereco($endereco, $pagina);
    }

    /**
     * Diz se o endereço atual já aponta para a página do episódio pedido.
     *
     * Serve de trava contra a recursão infinita: se a página aberta já é a do
     * episódio e mesmo assim não tem player, descer para "a página do episódio"
     * seria voltar para o mesmo lugar.
     */
    private function enderecoJaTemEpisodio(string $pagina, int $temporada, int $episodio): bool
    {
        $padrao = sprintf(
            '#temporada[-/]%d[-/]episodio[-/]%d#i',
            $temporada,
            $episodio
        );

        return (bool) preg_match($padrao, $pagina);
    }

    /**
     * Transforma um endereço relativo achado no HTML num endereço absoluto.
     *
     * Os agregadores escrevem o link do episódio das mais variadas formas:
     * absoluto (`https://site/series/x/temporada-1/episodio-1`), relativo à
     * raiz (`/series/x/temporada-1/episodio-1`) ou relativo ao caminho atual
     * (`temporada-1/episodio-1`). O `parse_url` da página dá o esquema e o host
     * para remontar o absoluto sem depender de biblioteca externa.
     */
    private function resolverEndereco(string $endereco, string $pagina): ?string
    {
        $endereco = trim($endereco);

        if ($endereco === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $endereco)) {
            return $endereco;
        }

        $partes = parse_url($pagina);

        if ($partes === false || ! isset($partes['scheme'], $partes['host'])) {
            return null;
        }

        $base = $partes['scheme'].'://'.$partes['host'];

        if (isset($partes['port'])) {
            $base .= ':'.$partes['port'];
        }

        if (str_starts_with($endereco, '//')) {
            return $partes['scheme'].':'.$endereco;
        }

        if (str_starts_with($endereco, '/')) {
            return $base.$endereco;
        }

        $caminho = $partes['path'] ?? '/';
        $diretorio = rtrim(substr($caminho, 0, (int) strrpos($caminho, '/')), '/');

        return $base.$diretorio.'/'.$endereco;
    }

    /**
     * Lê o `<title>` da página para a checagem de conteúdo impróprio.
     *
     * O título é a pista mais barata que o HTML oferece: ele já vem no `<head>`,
     * antes do corpo, e é o que o site escreveu para descrever a página. Um
     * `<title>` com termo adulto é prova suficiente para descartar a fonte sem
     * nem varrer o resto do HTML atrás de vídeo.
     *
     * A leitura é tolerante: sem `<title>`, devolve string vazia e a checagem
     * simplesmente não bloqueia — a decisão fica com a URL e com os links de
     * vídeo, que são validados em seguida.
     */
    private function tituloDaPagina(string $html): string
    {
        if (! preg_match('#<title\b[^>]*>(.*?)</title>#is', $html, $casamento)) {
            return '';
        }

        return $this->limparTexto($casamento[1]);
    }

    /**
     * Diz se o corpo da página é o placeholder de um site morto.
     *
     * O caso que motivou a checagem foi o `assistaonline.tv`: o domínio respondia
     * 200, mas o corpo era a página "Deployment Paused" do Vercel — o projeto
     * expirou e o endereço ficou apontando para o placeholder da hospedagem. O
     * status 200 engana a checagem de bloqueio, e a página ainda gastava a prova
     * de mídia e a renderização do FlareSolverr antes de ser recusada.
     *
     * A comparação é por frases inteiras, não por palavras soltas: "paused"
     * sozinho apareceria num player pausado, e "deployment" num blog sobre
     * deploy. A frase exata é o que identifica o placeholder.
     */
    private function pareceSiteMorto(string $html): bool
    {
        if ($html === '') {
            return false;
        }

        $alvo = strtolower($html);

        foreach (self::MARCAS_DE_SITE_MORTO as $marca) {
            if (str_contains($alvo, $marca)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Diz se a barreira de conteúdo impróprio está ligada.
     *
     * A chave é a mesma que o [`MotorBuscaWeb`] consulta, para que ligar ou
     * desligar a barreira valha para o fluxo inteiro — não faz sentido filtrar
     * na origem e deixar passar na extração, nem o contrário.
     */
    private function filtroAdultoAtivo(): bool
    {
        return (bool) config('services.torrents.stream_direto_filtro_adulto', true);
    }

    /**
     * Deduz o idioma a partir do endereço da página.
     *
     * Muitos sites de streaming separam dublado e legendado por caminho
     * (`/dublado/`, `/legendado/`) ou por subdomínio. Mas a pista mais comum
     * nem é a palavra inteira: é o **código de idioma no slug ou no segmento de
     * país** — `tokyvideo.com/br/video/desperate-housewives-pt-01x01` traz o
     * `/br/` (Brasil) e o `-pt-` (português) no próprio endereço. Olhar só
     * "dublado" deixava esses casos caírem em "original", e o overlay marcava
     * como idioma original um vídeo que toca dublado.
     *
     * A ordem das checagens separa o que é prova do que é pista:
     *
     * 1. **"legendado"** vem primeiro porque é a única tag que **nega** o áudio
     *    PT-BR: um endereço "legendado pt br" carrega o `pt` da legenda, não da
     *    dublagem. Se ele viesse depois, o código `pt` o classificaria como
     *    dublado — o mesmo cuidado que [`IdiomaFonte::deduzirDoTitulo()`] toma.
     * 2. **"dublado"/"dublada"** é a prova explícita.
     * 3. **Indícios de PT-BR** ([`IndiciosPtBr`]) no endereço: o segmento de
     *    país (`/br/`, `/pt/`, `/pt-br/`) e o código `pt` como palavra no slug.
     *    É a pista que cobre os sites que não escrevem "dublado" na URL.
     *
     * Quando nada aparece, o idioma fica vazio e a dedução pelo título assume
     * na montagem — o comportamento antigo, preservado para não inventar
     * dublagem onde não há pista nenhuma.
     */
    private function idiomaDaPagina(string $pagina): string
    {
        $alvo = strtolower($pagina);

        if (str_contains($alvo, 'legendado') || str_contains($alvo, 'legendada')) {
            return 'legendado';
        }

        if (str_contains($alvo, 'dublado') || str_contains($alvo, 'dublada')) {
            return 'dublado';
        }

        if ($this->enderecoIndicaPortugues($alvo)) {
            return 'dublado';
        }

        return '';
    }

    /**
     * Diz se o endereço da página carrega um indício de PT-BR.
     *
     * O alvo é o **caminho** da URL, não o host: um domínio `.com.br` prova que
     * o site é brasileiro, mas não que aquele vídeo específico está dublado —
     * muitos agregadores hospedam o áudio original sob o mesmo domínio. O que
     * vale é o que o site escreveu no caminho para aquele título: o segmento de
     * país (`/br/`, `/pt/`, `/pt-br/`) ou o código `pt` no slug.
     *
     * O `IndiciosPtBr::temCodigoPt()` é reusado porque ele já resolve a
     * armadilha do "pt" solto — `str_contains('pt')` casaria com "script" e
     * "concept", e a borda de palavra que ele aplica libera "pt", "pt-br" e
     * "pt_br" sem esses falsos positivos.
     */
    private function enderecoIndicaPortugues(string $endereco): bool
    {
        $caminho = (string) parse_url($endereco, PHP_URL_PATH);

        if ($caminho === '') {
            return false;
        }

        /*
         * O segmento de país é procurado com as barras à volta para não casar
         * um pedaço de palavra: `/br/` é o Brasil, mas `/bruno/` não é.
         */
        foreach (['/br/', '/pt/', '/pt-br/', '/pt_br/', '/brasil/', '/brazil/'] as $segmento) {
            if (str_contains($caminho, $segmento)) {
                return true;
            }
        }

        return IndiciosPtBr::temCodigoPt($caminho);
    }

    /**
     * Remove fontes repetidas pelo mesmo `stream`.
     *
     * A mesma URL pode sair de páginas diferentes (um agregador que espelha
     * outro). O `id` da fonte direta é o md5 da URL, então a deduplicação é por
     * ele — o mesmo link vindo de duas páginas vira uma fonte só.
     *
     * @param  array<int, array<string, mixed>>  $fontes
     * @return array<int, array<string, mixed>>
     */
    private function deduplicar(array $fontes): array
    {
        $unicas = [];

        foreach ($fontes as $fonte) {
            $id = (string) ($fonte['id'] ?? '');

            if ($id === '' || isset($unicas[$id])) {
                continue;
            }

            $unicas[$id] = $fonte;
        }

        return array_values($unicas);
    }

    /**
     * Extrai os hosts únicos de uma lista de URLs, para o log de diagnóstico.
     *
     * O log mostra o domínio, não a URL inteira: a pergunta que ele responde é
     * "para onde o orçamento foi", e o caminho completo só polui a leitura.
     *
     * @param  array<int, string>  $urls
     * @return array<int, string>
     */
    private function dominiosDe(array $urls): array
    {
        $dominios = [];

        foreach ($urls as $url) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));

            if ($host !== '') {
                $dominios[$host] = true;
            }
        }

        return array_keys($dominios);
    }
}
