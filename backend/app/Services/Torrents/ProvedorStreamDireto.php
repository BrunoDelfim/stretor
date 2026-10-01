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
 * Ele **não** é um degrau da cascata: é acionado pelo [`CatalogoProvedores`]
 * apenas quando os torrents falharam, e por isso não paga custo nenhum no
 * caminho comum. A fonte que ele devolve sai com `tipo=direto` e o campo
 * `stream` preenchido; o `magnet` fica vazio de propósito, e é o `tipo` que diz
 * ao player para seguir pelo media-service (proxy/remux) em vez do WebTorrent.
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

    public function __construct(
        private readonly OrcamentoBusca $orcamento,
        private readonly MotorBuscaWeb $motor,
        private readonly ExtratorVideo $extrator,
        private readonly ClienteHttp $cliente,
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
    ): array {
        return $this->buscarComTitulos([$titulo], $ano, $imdbId, $temporada, $episodio);
    }

    /**
     * Busca usando todas as grafias do título.
     *
     * O primeiro título é o principal (PT-BR); os demais entram como rede de
     * segurança na camada genérica de termos — é o que cobre o caso em que o
     * acervo PT-BR não tem página nenhuma.
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
         * O alvo de fontes é diferente do teto de páginas, e antes os dois eram a
         * mesma variável — o que fazia o laço parar só depois de abrir seis
         * páginas, mesmo com duas fontes já na mão. O fallback é socorro, não
         * catálogo: assim que há fontes suficientes para o usuário escolher, parar
         * é o certo. O alvo é configurável porque "suficiente" é uma decisão de
         * operação, não uma verdade do código.
         */
        $alvoFontes = (int) config('services.torrents.stream_direto_max_fontes', 2);

        /*
         * O teto de cada consulta ao motor e a margem de segurança que impede
         * começar uma requisição que não caberia no orçamento. Sem a margem, o
         * fallback iniciava a última consulta a 1 s do fim, esperava o teto cheio
         * e devolvia zero — o orçamento inteiro gasto sem nada entregue.
         */
        $tetoConsulta = (int) config('services.torrents.stream_direto_tempo_limite', 10);

        foreach ($termos as $termo) {
            if (! $this->temTempoParaConsulta($tetoConsulta)) {
                Log::debug('Stream direto: orçamento insuficiente para o próximo termo.', [
                    'termo' => $termo,
                    'restante' => $this->orcamento->restante(),
                ]);

                break;
            }

            /*
             * O laço para assim que junta fontes suficientes: o fallback é socorro,
             * não catálogo. Uma vez que há links tocáveis, gastar o orçamento
             * restante em mais termos só atrasaria a resposta.
             */
            if ($this->alvoAtingido($fontes, $alvoFontes)) {
                break;
            }

            $candidatas = $this->motor->procurar($termo);

            if ($candidatas === []) {
                continue;
            }

            $termosComResultado++;

            foreach ($candidatas as $pagina) {
                if (! $this->temTempoParaConsulta($tetoConsulta) || $this->alvoAtingido($fontes, $alvoFontes)) {
                    break 2;
                }

                /*
                 * A barreira de conteúdo impróprio roda antes de qualquer
                 * requisição: um link adulto que escapou do motor de busca é
                 * descartado aqui, sem gastar orçamento nem abrir a página. É a
                 * segunda linha de defesa — o [`MotorBuscaWeb`] já filtra na
                 * origem, mas o provedor não confia cegamente no que recebe.
                 */
                if ($this->filtroAdultoAtivo() && FiltroConteudoAdulto::urlBloqueada($pagina)) {
                    Log::warning('Stream direto: página imprópria descartada.', [
                        'pagina' => $pagina,
                    ]);

                    continue;
                }

                // A mesma página pode aparecer em vários termos; não vale abri-la
                // duas vezes.
                if (isset($paginasVisitadas[$pagina])) {
                    continue;
                }

                $paginasVisitadas[$pagina] = true;

                $fontes = array_merge($fontes, $this->rasparPagina($pagina, $titulo));
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
     * Diz se já há fontes suficientes para encerrar a varredura.
     *
     * O alvo é o número de fontes distintas, não de páginas abertas — uma página
     * pode render várias fontes, e o que o usuário escolhe é a fonte. Alvo zero ou
     * negativo desliga o corte: aí o laço só para pelo teto de páginas ou pelo
     * orçamento, que é o comportamento antigo.
     *
     * @param  array<int, array<string, mixed>>  $fontes
     */
    private function alvoAtingido(array $fontes, int $alvo): bool
    {
        return $alvo > 0 && count($fontes) >= $alvo;
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
    private function rasparPagina(string $pagina, string $titulo): array
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

            return [];
        }

        if ($resposta === null) {
            Log::debug('Stream direto: página não respondeu.', ['pagina' => $pagina]);

            return [];
        }

        if ($resposta->failed()) {
            Log::debug('Stream direto: página devolveu erro HTTP.', [
                'pagina' => $pagina,
                'status' => $resposta->status(),
            ]);

            return [];
        }

        $corpo = (string) $resposta->body();

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
         * A prova de mídia é o critério de aceitação. Antes de gastar a extração
         * completa, a página precisa provar que tem player: um arquivo de vídeo
         * ou um iframe de embed conhecido. É o que substitui a lista fixa de
         * domínios — um site desconhecido com player passa, um agregador famoso
         * sem player não passa. Sem essa prova, a página é descartada aqui.
         */
        if (! $this->extrator->temMidia($corpo)) {
            Log::debug('Stream direto: página sem prova de mídia descartada.', ['pagina' => $pagina]);

            return [];
        }

        $urls = $this->extrator->extrair($corpo);

        if ($urls === []) {
            Log::debug('Stream direto: página sem vídeo extraível.', ['pagina' => $pagina]);

            return [];
        }

        /*
         * Cada link de vídeo passa pela barreira antes de virar fonte: o vídeo
         * pode estar hospedado num CDN adulto (`xvideos-cdn.com`) mesmo que a
         * página que o embute pareça inocente. O descarte é silencioso no log de
         * erro e registrado em `debug` para não poluir a saída.
         */
        $urls = array_values(array_filter(
            $urls,
            function (string $url) use ($pagina): bool {
                if (! $this->filtroAdultoAtivo() || ! FiltroConteudoAdulto::urlBloqueada($url)) {
                    return true;
                }

                Log::warning('Stream direto: vídeo de origem imprópria descartado.', [
                    'pagina' => $pagina,
                    'video' => $url,
                ]);

                return false;
            }
        ));

        if ($urls === []) {
            Log::debug('Stream direto: página só tinha vídeo impróprio.', ['pagina' => $pagina]);

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
            ], $this->identificador(), $this->rotulo());
        }

        return $fontes;
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
