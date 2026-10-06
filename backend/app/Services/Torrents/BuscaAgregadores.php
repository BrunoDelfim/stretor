<?php

namespace App\Services\Torrents;

use App\Support\FiltroConteudoAdulto;
use Illuminate\Support\Facades\Log;

/**
 * Busca direta nos agregadores de vídeo — o caminho que dispensa o motor web.
 *
 * O fallback de stream direto nasceu apoiado num motor de busca aberto (o
 * SearXNG interno), que resolve o título em páginas candidatas. O motor, porém,
 * é a peça mais frágil da corrente: quando os motores grandes (Google,
 * DuckDuckGo, Brave) suspendem o IP do container — o que acontece com
 * frequência —, sobra o Bing, que devolve só plataforma legal. Para o conteúdo
 * raro, o resultado é uma busca que volta vazia **antes** de abrir qualquer
 * página.
 *
 * Este serviço fecha essa brecha perguntando direto a quem tem o conteúdo: os
 * agregadores que hospedam o vídeo costumam ter **busca própria**, e o
 * resultado dela já é a página do título — sem depender de um terceiro indexar
 * a página certa. É o mesmo caminho do usuário: em vez de googlar "assistir X",
 * ele digita X na busca do próprio site.
 *
 * A lista é curta e por conhecimento de causa: cada agregador declara onde a
 * busca mora (`busca`) e como reconhecer o link de conteúdo na resposta
 * (`conteudo`). O domínio serve de âncora — só links do próprio site entram, o
 * que descarta menu, rodapé e link patrocinado.
 *
 * A saída é uma lista de URLs de página, do mesmo tipo que o
 * [`MotorBuscaWeb::procurar()`] devolveria: o provedor a processa com a mesma
 * prova de relevância, de mídia e de extração. Este serviço só troca a origem
 * do link, não o contrato.
 */
class BuscaAgregadores
{
    use ConsultaComOrcamento;
    use RelevanciaTitulo;

    /**
     * Quantos caracteres de cada lado do link formam a vizinhança do cartão.
     *
     * O valor nasceu de uma medição no cartão do superflix: o nome do título (no
     * `alt` da capa) fica **1.847 caracteres antes** do endereço que o botão
     * "copiar link" publica. Uma janela menor lia o cartão como anônimo e
     * descartava a candidata certa por "sem relação com o título".
     */
    private const JANELA_DO_CARTAO = 2500;

    /**
     * Quantos endereços o censo guarda por agregador.
     *
     * O relatório é para leitura: um agregador pode devolver dezenas de candidatas
     * e a lista completa só empurraria as outras linhas para fora da tela. Cinco
     * bastam para provar de onde a candidata veio — e, no caso do superflix, para
     * mostrar se ele parou na ficha ou desceu até o episódio.
     */
    private const MAX_ENCONTRADAS = 5;

    /**
     * Agregadores com busca própria e o padrão que identifica o link de conteúdo.
     *
     * O padrão (`conteudo`) é sobre o **caminho** da URL, não o host: cada
     * agregador separa o conteúdo num prefixo próprio (`/series/`) e é por ele que
     * a página de resultado se distingue da navegação.
     *
     * A chave opcional `episodio` diz como **descer** da ficha para a página do
     * episódio quando o agregador separa as duas e o caminho é previsível. É o
     * caso do superflix: `/serie/693` é a ficha e `/serie/693/4/17` é o episódio,
     * e como os ids numéricos dele são ids do TMDB a descida é **montar** a URL,
     * não procurar um link. Os agregadores de slug (o `verpobreflix.net`) não
     * declaram a chave: ali a descida continua sendo ler o `<a>` de
     * `temporada-N/episodio-N`, o que o [`ProvedorStreamDireto`] já faz.
     *
     * A **ordem** é a da cascata: os agregadores são percorridos um por vez, de
     * cima para baixo, e o primeiro que render uma fonte encerra a busca — o
     * seguinte nem chega a ser perguntado. O `verpobreflix.net` vem primeiro
     * porque é o acervo cuja página carrega o vídeo de verdade (o embed do
     * `plenoflu.com`), que a extração resolve sem passe nenhum. O superflix vem
     * logo depois: a busca dele é a mais direta, indexada por id do TMDB, mas a
     * página do episódio é fechada pelo Cloudflare e só abre com o passe
     * configurado — é o mesmo acervo por outro caminho, com um degrau a mais.
     *
     * O `tokyvideo.com` chegou a estar aqui e saiu: a busca dele é montada por
     * JavaScript, então o HTML estático de `/search?q=` só traz a lista de vídeos
     * populares da barra lateral — nenhuma pista do termo pedido, e zero
     * ocorrência do título nos bytes baixados. A regra passa a ser essa: só entra
     * agregador cujo resultado de busca esteja no HTML que se baixa sem navegador.
     *
     * @var array<string, array{busca: string, conteudo: string, episodio?: string, atributos?: array<int, string>}>
     */
    private const AGREGADORES = [
        'verpobreflix.net' => [
            'busca' => 'https://www.verpobreflix.net/search?q={termo}',
            'conteudo' => '#^/series/#',
        ],
        /*
         * O superflix entra pela **busca**, e não pelo embed: o portão do
         * Cloudflare fecha a página do episódio (a `/serie/693/4/17` responde com
         * a tela de verificação), mas `/pesquisar?s=` responde 200 sem desafio
         * nenhum e devolve a ficha com o id. A página do episódio, essa, só abre
         * com o passe configurado — ver `services.torrents.passe_cloudflare_*`.
         *
         * Ele vem depois do `verpobreflix.net` de propósito: é o segundo caminho
         * para o mesmo acervo, e só é pago quando o primeiro não rendeu fonte.
         */
        'superflixapi.quest' => [
            'busca' => 'https://superflixapi.quest/pesquisar?s={termo}',
            'conteudo' => '#^/(serie|filme)/\d+(/|$)#',
            /*
             * O cartão de resultado do superflix esconde o endereço **bom** num
             * `data-copy` e deixa no `href` um slug que responde 404
             * (`/serie/donas-de-casa-desesperadas`). Ler só o `href` devolvia zero
             * candidatas de um site que tem o episódio — a rota que funciona é a
             * numérica, a mesma que o botão "copiar link" publica.
             */
            'atributos' => ['data-copy'],
            'episodio' => 'https://superflixapi.quest/serie/{id}/{temporada}/{episodio}',
        ],
    ];

    /**
     * Censo da busca direta: o que cada agregador respondeu nesta varredura.
     *
     * O relatório de cobertura da busca inteira tem **uma** linha para o stream
     * direto — ele é acionado à parte e cobra o próprio tempo —, e essa linha
     * escondia justamente o que interessa quando o fallback volta vazio: dos
     * agregadores, quem não tem acervo, quem respondeu sem o título e quem nem
     * chegou a ser perguntado. É o mesmo problema que o censo do
     * [`CatalogoProvedores`] resolve um nível acima, e a resposta é a mesma:
     * registrar **todos** os agregadores declarados, e não só os que responderam.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $censo = [];

    public function __construct(
        private readonly OrcamentoBusca $orcamento,
        private readonly ClienteHttp $cliente,
    ) {
        /*
         * O censo nasce preenchido, e não vazio: assim uma varredura que nem
         * chegou a consultar nenhum agregador ainda devolve a lista completa com
         * todo mundo em `nao_consultado` — que é a informação que o relatório
         * precisa para dizer "não foi perguntado" em vez de não dizer nada.
         */
        $this->reiniciarCenso();
    }

    /**
     * Zera o censo, mantendo todos os agregadores declarados no relatório.
     *
     * Roda no início de cada varredura, e não dentro do gerador de
     * [`candidatas()`]: um gerador que ninguém consome não executa o próprio corpo
     * e um censo que só nascesse lá dentro ficaria com os números da varredura
     * anterior. Quem chama é o [`ProvedorStreamDireto`], antes de percorrer os
     * agregadores.
     */
    public function reiniciarCenso(): void
    {
        $ligada = (bool) config('services.torrents.stream_direto_busca_direta', true);

        $this->censo = [];

        foreach (array_keys(self::AGREGADORES) as $dominio) {
            $this->censo[$dominio] = [
                'agregador' => $dominio,
                // Com a busca direta desligada, o motivo do silêncio é a chave — e
                // não a cascata ter parado antes de chegar até ele. Comentar a
                // diferença aqui evita que a configuração vire um mistério no
                // relatório.
                'situacao' => $ligada ? 'nao_consultado' : 'desligada',
                'consultas' => 0,
                'paginas' => 0,
                'ms' => 0,
                'titulo' => '',
                'encontradas' => [],
            ];
        }
    }

    /**
     * Relatório da última varredura: o que cada agregador respondeu.
     *
     * É a resposta verificável a "o superflix foi consultado?" — pergunta que a
     * linha única do stream direto na cobertura não consegue responder. Cada
     * agregador declarado aparece, mesmo o que nunca foi tocado, com a situação
     * que explica o silêncio: `desligada` (a chave da busca direta está off),
     * `nao_consultado` (o orçamento acabou ou um agregador anterior já entregou),
     * `sem_resposta` (a busca estourou o tempo ou voltou com erro HTTP), `erro`
     * (a exceção subiu), `sem_resultado` (respondeu, mas nenhum link de conteúdo
     * passou pela relevância) ou `com_pagina` (entregou endereços de conteúdo).
     *
     * @return array<int, array<string, mixed>>
     */
    public function censo(): array
    {
        return array_values($this->censo);
    }

    /**
     * Procura, em cada agregador, a página do título e devolve a lista inteira.
     *
     * É o atalho para quem consome todas as candidatas de uma vez — a cascata de
     * verdade, que para na primeira fonte que rende, é o [`candidatas()`].
     *
     * @param  array<int, string>  $titulos
     * @return array<int, string>
     */
    public function buscar(array $titulos, ?int $temporada = null, ?int $episodio = null): array
    {
        // Quem percorre o gerador inteiro é o consumo de todas as candidatas; o
        // censo começa do zero para não somar duas varreduras na mesma linha.
        $this->reiniciarCenso();

        $encontradas = [];

        foreach ($this->candidatas($titulos, $temporada, $episodio) as $paginas) {
            foreach ($paginas as $pagina) {
                $encontradas[$pagina] = true;
            }
        }

        return array_keys($encontradas);
    }

    /**
     * Percorre os agregadores **um por vez**, na ordem declarada, devolvendo o que
     * cada um achou antes de passar ao seguinte.
     *
     * A diferença para o [`buscar()`] não é de forma, é de custo: quem consome o
     * gerador abre as páginas de um agregador — relevância, prova de mídia,
     * extração — e só então pergunta ao próximo. Assim o agregador que entrega o
     * episódio encerra a cascata no alvo de fontes, e um agregador morto não
     * consome o orçamento que o seguinte precisava. Antes, todos eram consultados
     * de uma vez e a lista vinha misturada, sem como saber de onde cada candidata
     * veio nem quem nunca respondeu.
     *
     * O domínio vai como chave porque essa origem é justamente o que o log precisa
     * registrar para distinguir "este agregador entregou" de "este não tem acervo".
     *
     * Os títulos são tentados em ordem (o principal primeiro). Para cada
     * agregador, o primeiro título que devolver resultado encerra a busca naquele
     * site — a alternativa serve de rede de segurança para quando o acervo está
     * cadastrado pelo nome de origem.
     *
     * @param  array<int, string>  $titulos
     * @return \Generator<string, array<int, string>>
     */
    public function candidatas(array $titulos, ?int $temporada = null, ?int $episodio = null): \Generator
    {
        if (! (bool) config('services.torrents.stream_direto_busca_direta', true)) {
            return;
        }

        $titulos = $this->titulosLimpos($titulos);

        if ($titulos === []) {
            return;
        }

        foreach (self::AGREGADORES as $dominio => $agregador) {
            if (! $this->temOrcamento()) {
                return;
            }

            foreach ($titulos as $titulo) {
                $inicio = microtime(true);

                $paginas = $this->consultar($dominio, $agregador, $titulo, $temporada, $episodio);

                $this->anotar($dominio, $titulo, $paginas, (microtime(true) - $inicio) * 1000);

                if ($paginas !== []) {
                    yield $dominio => $paginas;

                    break;
                }
            }
        }
    }

    /**
     * Grava no censo o desfecho de uma consulta a um agregador.
     *
     * A situação de **falha** não é decidida aqui: quem sabe se a página respondeu
     * ou se a conexão caiu é o [`consultar()`], que já marca o motivo antes de
     * devolver. O que se grava aqui — e que só quem consome o gerador sabe — é o
     * custo (uma tentativa e o tempo de parede dela) e, quando houve páginas, o
     * título que casou e os endereços entregues. É esta lista que responde se o
     * superflix devolveu a ficha do episódio ou parou na busca do título.
     *
     * @param  array<int, string>  $paginas
     */
    private function anotar(string $dominio, string $titulo, array $paginas, float $ms): void
    {
        if (! isset($this->censo[$dominio])) {
            return;
        }

        $this->censo[$dominio]['consultas']++;
        $this->censo[$dominio]['ms'] += (int) round($ms);

        if ($paginas === []) {
            return;
        }

        $this->censo[$dominio]['situacao'] = 'com_pagina';
        $this->censo[$dominio]['titulo'] = $titulo;
        $this->censo[$dominio]['paginas'] = count($paginas);
        $this->censo[$dominio]['encontradas'] = array_slice(array_values($paginas), 0, self::MAX_ENCONTRADAS);
    }

    /**
     * Consulta a busca interna de um agregador e devolve os links de conteúdo.
     *
     * O `$temporada` e o `$episodio` entram aqui, e não no laço de quem chama,
     * porque é o **agregador** que sabe como descer da ficha para o episódio: a
     * receita mora na declaração dele, junto do padrão de busca.
     *
     * @param  array{busca: string, conteudo: string, episodio?: string}  $agregador
     * @return array<int, string>
     */
    private function consultar(
        string $dominio,
        array $agregador,
        string $titulo,
        ?int $temporada = null,
        ?int $episodio = null,
    ): array
    {
        $url = str_replace('{termo}', rawurlencode($titulo), $agregador['busca']);
        $teto = (int) config('services.torrents.stream_direto_tempo_limite', 8);

        try {
            $resposta = $this->cliente->get($url, [], $this->navegador(), $teto);
        } catch (\Throwable $excecao) {
            $this->marcarSituacao($dominio, 'erro');

            Log::warning('Stream direto: falha na busca direta do agregador.', [
                'dominio' => $dominio,
                'titulo' => $titulo,
                'erro' => $excecao->getMessage(),
            ]);

            return [];
        }

        if ($resposta === null || $resposta->failed()) {
            $this->marcarSituacao($dominio, 'sem_resposta');

            Log::debug('Stream direto: agregador não respondeu à busca direta.', [
                'dominio' => $dominio,
                'titulo' => $titulo,
            ]);

            return [];
        }

        $links = $this->links(
            (string) $resposta->body(),
            $url,
            $dominio,
            $agregador['conteudo'],
            $agregador['atributos'] ?? []
        );

        /*
         * A relevância pelo título é conferida **aqui**, e não só lá na frente,
         * para o ruído não gastar orçamento. A página de resultado de um
         * agregador costuma vir salpicada de "veja também", e cada link desses
         * custaria uma requisição e uma vaga do teto de páginas antes de ser
         * descartado. Filtrar o slug agora é barato e deixa o crivo final (título
         * da página e URL do vídeo) exatamente onde já estava.
         *
         * O julgamento usa o **texto do cartão** além do endereço, e isso nasceu
         * do superflix: a busca dele devolve `/serie/693`, um endereço sem uma
         * única palavra do título. Julgada só pela URL, a candidata certa seria
         * descartada por "sem relação com o título" — o nome está no cartão do
         * resultado, ao lado do link, e é o que a página de fato mostra.
         */
        $candidatas = [];

        foreach ($links as $link => $texto) {
            if (! $this->paginaRelevante($link, $texto, $titulo)) {
                continue;
            }

            $candidatas[] = $this->descerParaEpisodio($link, $agregador, $temporada, $episodio);
        }

        /*
         * Respondeu e nada passou pela relevância — o caso mais comum de um
         * agregador que não tem aquele título (a busca devolve "veja também" e
         * pouco mais). Quando há páginas, quem grava a situação é o [`anotar()`]:
         * é ele que vê a lista já deduplicada e sabe qual título casou.
         */
        if ($candidatas === []) {
            $this->marcarSituacao($dominio, 'sem_resultado');
        }

        return array_values(array_unique($candidatas));
    }

    /**
     * Marca no censo a situação de um agregador já registrado.
     *
     * O `isset` é o guarda de quem chama [`consultar()`] fora de uma varredura (num
     * teste, por exemplo): melhor não gravar nada do que criar uma linha de censo
     * sem os contadores que o relatório espera ler.
     */
    private function marcarSituacao(string $dominio, string $situacao): void
    {
        if (isset($this->censo[$dominio])) {
            $this->censo[$dominio]['situacao'] = $situacao;
        }
    }

    /**
     * Extrai os links de conteúdo de uma página de resultados, com o texto do
     * cartão de cada um ao lado.
     *
     * Só entram links do próprio agregador (âncora pelo host) que carreguem o
     * prefixo de conteúdo. É a mesma ideia da lista branca do motor web, mas pelo
     * host do resultado em vez do domínio de busca: aqui já se sabe em qual site
     * se está.
     *
     * @param  array<int, string>  $atributos  Atributos extras além do `href`
     * @return array<string, string> Endereço absoluto → texto do cartão
     */
    private function links(
        string $html,
        string $base,
        string $dominio,
        string $padraoConteudo,
        array $atributos = [],
    ): array {
        if ($html === '') {
            return [];
        }

        // O texto que acompanha cada link é o que permite julgar a relevância de
        // um endereço puramente numérico (`/serie/693`), como o do superflix.
        $textos = $this->textosDosLinks($html, $atributos);

        $links = [];

        foreach ($this->enderecosNoHtml($html, $atributos) as $endereco) {
            $absoluto = $this->absolutizar($endereco, $base);

            if ($absoluto === null) {
                continue;
            }

            $host = strtolower((string) parse_url($absoluto, PHP_URL_HOST));

            if ($host !== $dominio && ! str_ends_with($host, '.'.$dominio)) {
                continue;
            }

            $caminho = strtolower((string) parse_url($absoluto, PHP_URL_PATH));

            if (! preg_match($padraoConteudo, $caminho)) {
                continue;
            }

            if ($this->filtroAdultoAtivo() && FiltroConteudoAdulto::urlBloqueada($absoluto)) {
                continue;
            }

            $links[$absoluto] = $textos[$endereco] ?? ($textos[$absoluto] ?? '');
        }

        return $links;
    }

    /**
     * Os endereços publicados no HTML: o `href` e os atributos que o agregador
     * declarar.
     *
     * A lista de atributos existe por causa do superflix: o cartão de resultado
     * dele esconde o endereço **bom** num `data-copy`
     * (`data-copy="https://superflixapi.quest/serie/693"`) e deixa no `href` um
     * slug que responde 404 (`/serie/donas-de-casa-desesperadas`). Ler só o
     * `href` — o que basta para os outros agregadores — devolvia zero candidatas de
     * um site que tem o episódio.
     *
     * @param  array<int, string>  $atributos
     * @return array<int, string>
     */
    private function enderecosNoHtml(string $html, array $atributos): array
    {
        $enderecos = [];

        foreach ($this->atributosDeEndereco($atributos) as $atributo) {
            if (! preg_match_all($this->padraoDoAtributo($atributo), $html, $casamentos)) {
                continue;
            }

            foreach ($casamentos[1] as $bruto) {
                $enderecos[] = html_entity_decode((string) $bruto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return array_values(array_unique($enderecos));
    }

    /**
     * O texto visível de cada cartão, indexado pelo endereço como ele aparece no
     * HTML.
     *
     * O recorte é o da **vizinhança** do endereço, para os dois lados, e não o do
     * texto dentro do `<a>` — a escolha vem de uma medição: no cartão do superflix
     * o nome do título está no `alt` da capa, **1.847 caracteres antes** do
     * endereço que o botão "copiar link" publica, e nada entre os dois diz o nome.
     * Com uma janela de um lado só, o cartão lia como anônimo e a candidata certa
     * era descartada por "sem relação com o título".
     *
     * @param  array<int, string>  $atributos
     * @return array<string, string>
     */
    private function textosDosLinks(string $html, array $atributos): array
    {
        $textos = [];

        foreach ($this->atributosDeEndereco($atributos) as $atributo) {
            if (! preg_match_all($this->padraoDoAtributo($atributo), $html, $casamentos, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($casamentos[1] as [$bruto, $posicao]) {
                $chave = html_entity_decode((string) $bruto, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if ($chave === '' || isset($textos[$chave])) {
                    continue;
                }

                $textos[$chave] = $this->textoDaVizinhanca($html, $posicao);
            }
        }

        return $textos;
    }

    /**
     * O texto em volta de um endereço, sem as tags.
     *
     * O corte depois do primeiro `>` descarta o resto da tag do próprio endereço
     * — o `class="..."` que vem depois do valor não é texto de cartão.
     */
    private function textoDaVizinhanca(string $html, int $posicao): string
    {
        $antes = substr($html, max(0, $posicao - self::JANELA_DO_CARTAO), min(self::JANELA_DO_CARTAO, $posicao));
        $depois = substr($html, $posicao, self::JANELA_DO_CARTAO);

        $fimDaTag = strpos($depois, '>');

        if ($fimDaTag !== false) {
            $depois = substr($depois, $fimDaTag + 1);
        }

        return trim((string) preg_replace('/\s+/u', ' ', $this->semTags($antes).' '.$this->semTags($depois)));
    }

    /**
     * A marcação fora, o texto dentro — e os `alt`/`title` preservados.
     *
     * É nesses dois atributos que mora o nome do título nas capas dos
     * agregadores, e o `strip_tags` puro apagaria a tag inteira, nome incluído.
     * Aqui o valor deles é trazido para o texto e o resto da marcação vai embora.
     */
    private function semTags(string $html): string
    {
        $comNomes = (string) preg_replace(
            '#<[^>]*\b(?:alt|title)\s*=\s*["\']([^"\']{1,200})["\'][^>]*>#i',
            ' $1 ',
            $html
        );

        return strip_tags($comNomes);
    }

    /**
     * Os atributos que valem como endereço: o `href` e os que o agregador declara.
     *
     * @param  array<int, string>  $atributos
     * @return array<int, string>
     */
    private function atributosDeEndereco(array $atributos): array
    {
        return array_values(array_unique(array_merge(['href'], array_map(
            fn (mixed $atributo): string => trim((string) $atributo),
            $atributos
        ))));
    }

    /**
     * O casamento de um atributo com o seu valor entre aspas.
     */
    private function padraoDoAtributo(string $atributo): string
    {
        return '#'.preg_quote($atributo, '#').'\s*=\s*["\']([^"\']+)["\']#i';
    }

    /**
     * Desce da ficha da série para a página do episódio, quando o agregador
     * declara como fazê-lo.
     *
     * O superflix separa as duas páginas: `/serie/693` é a ficha, sem player
     * nenhum, e `/serie/693/4/17` é o episódio. Como o id dele é o do TMDB, a
     * descida é **montar** a URL — e é por isso que ela não passa pelo caminho
     * genérico do [`ProvedorStreamDireto`], que procura no HTML o `<a>` de
     * `temporada-N/episodio-N` dos agregadores de slug. Sem esta descida, o
     * provedor abriria a ficha, não acharia player e descartaria a candidata certa.
     *
     * Agregador sem a chave `episodio`, ou busca sem episódio pedido (filme, ou
     * quando a própria ficha já basta), devolve o link intacto.
     *
     * @param  array{busca: string, conteudo: string, episodio?: string}  $agregador
     */
    private function descerParaEpisodio(string $link, array $agregador, ?int $temporada, ?int $episodio): string
    {
        $modelo = (string) ($agregador['episodio'] ?? '');

        if ($modelo === '' || $temporada === null || $episodio === null) {
            return $link;
        }

        $caminho = (string) parse_url($link, PHP_URL_PATH);

        if (! preg_match('#^/serie/(\d+)(?:/|$)#', $caminho, $achado)) {
            return $link;
        }

        return str_replace(
            ['{id}', '{temporada}', '{episodio}'],
            [$achado[1], $temporada, $episodio],
            $modelo
        );
    }

    /**
     * Transforma um endereço relativo à raiz num absoluto.
     *
     * As buscas dos agregadores devolvem links das duas formas: absoluto
     * (`https://www.tokyvideo.com/video/x`) e relativo à raiz (`/series/x`). O
     * relativo resolve contra o esquema e o host da página de busca. Um relativo
     * ao caminho não é resolvido — não aparece em página de resultado e evitá-lo
     * mantém a regra simples.
     */
    private function absolutizar(string $endereco, string $base): ?string
    {
        $endereco = trim($endereco);

        if ($endereco === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $endereco)) {
            return $endereco;
        }

        $partes = parse_url($base);

        if ($partes === false || ! isset($partes['scheme'], $partes['host'])) {
            return null;
        }

        $raiz = $partes['scheme'].'://'.$partes['host'];

        if (isset($partes['port'])) {
            $raiz .= ':'.$partes['port'];
        }

        if (str_starts_with($endereco, '//')) {
            return $partes['scheme'].':'.$endereco;
        }

        if (str_starts_with($endereco, '/')) {
            return $raiz.$endereco;
        }

        return null;
    }

    /**
     * Normaliza a lista de títulos, descartando vazios e repetidos.
     *
     * @param  array<int, string>  $titulos
     * @return array<int, string>
     */
    private function titulosLimpos(array $titulos): array
    {
        $limpos = [];

        foreach ($titulos as $titulo) {
            $titulo = trim((string) $titulo);

            if ($titulo !== '') {
                $limpos[] = $titulo;
            }
        }

        return array_values(array_unique($limpos));
    }

    /**
     * Agente de navegador das requisições, no mesmo padrão do [`MotorBuscaWeb`].
     */
    private function navegador(): string
    {
        $agente = (string) config('services.torrents.user_agent', '');

        return $agente !== ''
            ? $agente
            : 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
    }

    /**
     * Diz se a barreira de conteúdo impróprio está ligada.
     */
    private function filtroAdultoAtivo(): bool
    {
        return (bool) config('services.torrents.stream_direto_filtro_adulto', true);
    }
}

