<?php

namespace App\Services\Torrents;

use App\Support\FiltroConteudoAdulto;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * Resolve páginas candidatas a partir de um termo, usando motores de busca leves.
 *
 * O scraper de stream direto precisa de uma lista de URLs de páginas de streaming
 * para abrir. Ele não tem um catálogo próprio nem uma API: quem sabe onde as
 * páginas estão é o motor de busca. Este serviço é a ponte — recebe um termo e
 * devolve os endereços que o motor apontou.
 *
 * O motor é o **SearXNG interno do compose**, consultado em
 * `http://searxng:8080/search` com `format=json`. Ele sobe junto com o stack, tem
 * cota própria e devolve JSON limpo — não depende de instância pública nem de
 * terceiros, e não sofre o bloqueio por IP que os buscadores comerciais aplicam
 * aos containers. O DuckDuckGo HTML/Lite foi removido do projeto: além de
 * bloquear o IP dos containers com status 202, exigia parsing de HTML e detecção
 * de captcha que só existiam para contornar o bloqueio.
 *
 * Cada endereço em `stream_direto_motores` é consultado por um **tipo** de motor,
 * e o tipo decide como montar a requisição e como ler a resposta:
 *
 * - `searxng` — instância SearXNG (interna ou pública), com `format=json`.
 *   Resultados em `results[].url`.
 * - `brave` — API oficial do Brave Search, exige chave (`stream_direto_brave_key`).
 *   Resultados em `web.results[].url`.
 *
 * O tipo é inferido do endereço quando não declarado explicitamente: um host com
 * `searx` vira `searxng`, um host com `brave` vira `brave`. Também dá para forçar
 * o tipo com o prefixo `tipo:url` na lista de motores.
 *
 * Com mais de um endereço, o serviço **percorre todos** e junta o que cada um
 * devolveu: um motor que respondeu com poucos resultados não impede o outro de
 * contribuir. E um motor que falha não consome o orçamento dos termos seguintes —
 * ele é apenas pulado, e o próximo endereço assume.
 *
 * A leitura é sempre por `json_decode`, que é barato e não depende de parser de
 * DOM: os motores devolvem JSON estruturado, então não há HTML para raspar.
 *
 * ## A lista negra é a peneira grossa; a prova é a extração
 *
 * Aqui só se descarta o que **sabidamente** nunca tem vídeo: fórum, Q&A, suporte
 * de fabricante, rede social, PDF, site adulto. É uma peneira grossa de
 * propósito — ela existe para o orçamento não vazar com páginas que jamais
 * teriam player, e não para decidir o que é "site de vídeo".
 *
 * A decisão de aceitar uma página é da **extração**, não do domínio. Um domínio
 * desconhecido que devolva um `<video>`, um `.mp4`/`.m3u8` ou um iframe de embed
 * é tão válido quanto um agregador famoso; um domínio famoso que não devolva
 * nada é descartado do mesmo jeito. Amarrar a aceitação a uma lista fixa de
 * domínios deixaria de fora justamente o acervo variado que o fallback existe
 * para alcançar.
 */
class MotorBuscaWeb
{
    use ConsultaComOrcamento;

    /**
     * Domínios que nunca têm vídeo extraível e só gastam orçamento.
     *
     * O motor de busca devolve, para qualquer título conhecido, uma fileira de
     * páginas que ranqueiam alto por autoridade mas não hospedam o vídeo. São
     * três famílias:
     *
     * 1. **Catálogo e metadados** — JustWatch (lista onde assistir), IMDb (ficha
     *    técnica), Plex (catálogo de assinatura), YouTube oficial (trailer),
     *    Wikipédia/TMDB (verbete).
     * 2. **Fóruns, Q&A e redes sociais** — Reddit, Quora, Zhihu, Yahoo
     *    Respostas, Stack Exchange, Facebook, X/Twitter, Instagram, TikTok,
     *    Pinterest, Medium, Tumblr. Nenhum deles publica o arquivo de vídeo: são
     *    discussões *sobre* o título, e o extrator não acha nada ali.
     * 3. **Buscadores e agregadores de link** — Google, Bing, DuckDuckGo,
     *    Yandex, Baidu. Devolvem páginas de resultado, não o vídeo.
     *
     * O descarte é por sufixo de domínio, e não por substring: `imdb.com` precisa
     * barrar `www.imdb.com` e `m.imdb.com`, mas **não** um hipotético
     * `naoimdb.com`. A comparação é feita sobre o host, com o ponto à frente.
     */
    private const DOMINIOS_IGNORADOS = [
        // Catálogo e metadados.
        'justwatch.com',
        'imdb.com',
        'plex.tv',
        'youtube.com',
        'youtu.be',
        'wikipedia.org',
        'tmdb.org',
        'themoviedb.org',
        'rottentomatoes.com',
        'metacritic.com',
        'letterboxd.com',
        'filmaffinity.com',
        'adorocinema.com',
        'filmow.com',
        'thetvdb.com',
        'trakt.tv',
        'sensacine.com',
        'papodecinema.com.br',
        // Fóruns e comunidades de discussão.
        'reddit.com',
        'lowyat.net',
        'forumeiros.com',
        'forumfree.it',
        'hardmob.com.br',
        'adrenaline.com.br',
        'clubedohardware.com.br',
        'htforum.com',
        'outerspace.com.br',
        'neogaf.com',
        'resetera.com',
        'gamefaqs.gamespot.com',
        'stackexchange.com',
        'stackoverflow.com',
        'superuser.com',
        'serverfault.com',
        'askubuntu.com',
        'medium.com',
        'dev.to',
        'hashnode.dev',
        // Perguntas e respostas e suporte de fabricante.
        'quora.com',
        'zhihu.com',
        'answers.yahoo.com',
        'br.answers.yahoo.com',
        'answers.microsoft.com',
        'support.microsoft.com',
        'learn.microsoft.com',
        'docs.microsoft.com',
        'support.google.com',
        'support.apple.com',
        'discussions.apple.com',
        'wikihow.com',
        'ehow.com',
        'allexperts.com',
        'justanswer.com',
        'brainly.com.br',
        'brainly.com',
        // Redes sociais e mensageria.
        'facebook.com',
        'fb.com',
        'fb.watch',
        'instagram.com',
        'twitter.com',
        'x.com',
        'tiktok.com',
        'linkedin.com',
        'pinterest.com',
        'tumblr.com',
        'snapchat.com',
        'threads.net',
        'mastodon.social',
        'bsky.app',
        't.me',
        'telegram.me',
        'whatsapp.com',
        'discord.com',
        'discord.gg',
        // Buscadores e agregadores de link.
        'google.com',
        'bing.com',
        'duckduckgo.com',
        'yandex.com',
        'yandex.ru',
        'baidu.com',
        'search.yahoo.com',
        'br.search.yahoo.com',
        'yahoo.com',
        'ecosia.org',
        'startpage.com',
        'qwant.com',
        'mojeek.com',
        'ask.com',
        'lycos.com',
        'aol.com',
        // Lojas, streamings oficiais e serviços.
        'amazon.com',
        'amazon.com.br',
        'mercadolivre.com.br',
        'shopee.com.br',
        'aliexpress.com',
        'ebay.com',
        'olx.com.br',
        'enjoei.com.br',
        'americanas.com.br',
        'magazineluiza.com.br',
        'submarino.com.br',
        'netflix.com',
        'primevideo.com',
        'disneyplus.com',
        'max.com',
        'hbomax.com',
        'globoplay.globo.com',
        'paramountplus.com',
        'starplus.com',
        'deezer.com',
        'spotify.com',
        'soundcloud.com',
        /*
         * Empresas, marcas e instituições homônimas de títulos.
         *
         * O motor de busca casa o título com o nome da empresa quando as
         * palavras coincidem. "American Horror Story" devolveu a American
         * Airlines (`aa.com.br`, `aa.com`) e a Câmara Americana de Comércio
         * (`amcham.com.br`) — três páginas que não têm vídeo nenhum e
         * consumiram o orçamento curto do fallback. O mesmo vale para
         * "Donas de Casa Desesperadas", que puxa páginas de imobiliárias e
         * classificados de aluguel. Nenhuma delas é fonte de mídia.
         */
        'aa.com',
        'aa.com.br',
        'amcham.com.br',
        'americanas.com',
        'americanairlines.com',
        'americanairlines.com.br',
        'latam.com',
        'gol.com.br',
        'voegol.com.br',
        'azul.com.br',
        'voeazul.com.br',
        'tam.com.br',
        'avianca.com',
        'cvc.com.br',
        'decolar.com',
        'booking.com',
        'airbnb.com',
        'airbnb.com.br',
        'trivago.com.br',
        'quintoandar.com.br',
        'zapimoveis.com.br',
        'vivareal.com.br',
        'imovelweb.com.br',
        'chavesnamao.com.br',
        'loft.com.br',
        'quintoandar.com',
        /*
         * Wikis de fandom e enciclopédias colaborativas.
         *
         * A armadilha é sutil: uma página de fandom *sobre* a série embute
         * player de vídeo (o YouTube oficial do trailer, o clipe da cena), então
         * ela passa pela prova de mídia e vira fonte — mas o vídeo que ela
         * embute é um trecho aleatório, não o episódio. Foi o que aconteceu com
         * "American Horror Story": o `dramatotal.fandom.com` rendeu seis vídeos
         * que nada tinham a ver com o episódio 1. Wiki não hospeda o episódio;
         * hospeda o verbete sobre ele.
         */
        'fandom.com',
        'wikia.com',
        'wikia.org',
        'wiki.gg',
        'shoutwiki.com',
        'miraheze.org',
        'wikidot.com',
        'wikitia.com',
        'wikiwand.com',
        'fextralife.com',
        'gamepedia.com',
        // Enciclopédias, notícias e portais.
        'britannica.com',
        'g1.globo.com',
        'uol.com.br',
        'terra.com.br',
        'folha.uol.com.br',
        'estadao.com.br',
        'oglobo.globo.com',
        'bbc.com',
        'cnnbrasil.com.br',
        'nytimes.com',
        'theguardian.com',
        'wired.com',
        'tecmundo.com.br',
        'canaltech.com.br',
        'olhardigital.com.br',
        'techtudo.com.br',
        'showmetech.com.br',
        // Repositórios, documentação e acadêmico.
        'github.com',
        'gitlab.com',
        'bitbucket.org',
        'sourceforge.net',
        'readthedocs.io',
        'gitbook.io',
        'notion.so',
        'scribd.com',
        'slideshare.net',
        'academia.edu',
        'researchgate.net',
        'scielo.br',
        'scholar.google.com',
        'jstor.org',
        // Consultas de CNPJ, CPF e dados de empresas.
        //
        // A busca por um título que contém um número ("S01E01") faz o motor
        // devolver consultas de CNPJ: o padrão "S01E01" casa com o formato de
        // inscrição que esses sites indexam. Nenhuma delas tem vídeo, e abrir cada
        // uma custa segundos do orçamento curto do fallback — foi assim que três
        // consultas de CNPJ consumiram a janela inteira e a busca terminou com
        // zero fontes.
        'checacnpj.com.br',
        'cnpjcheck.com.br',
        'datapj.com.br',
        'cnpj.biz',
        'cnpj.info',
        'consultacnpj.com',
        'consultacnpj.com.br',
        'cnpja.com',
        'casadosdados.com.br',
        'econodata.com.br',
        'cnpjservices.com.br',
        'meucnpj.com.br',
        'situacaocadastral.com.br',
        'cnpjagora.com.br',
        'cnpjfacil.com.br',
        'empresascnpj.com',
        'cnpjs.com.br',
        'cnpj.rocks',
        'cnpj.io',
        'receitaws.com.br',
        'serpro.gov.br',
        'gov.br',
        // Dicionários e tradução.
        'dictionary.com',
        'cambridge.org',
        'merriam-webster.com',
        'linguee.com.br',
        'reverso.net',
        'wordreference.com',
        'dicio.com.br',
        'significados.com.br',
        'priberam.org',
        'infopedia.pt',
        /*
         * Agregadores de fachada e clones mortos.
         *
         * São páginas que se anunciam como "assistir online" mas não hospedam
         * mídia: ou embrulham o player de um terceiro que exige `Referer` e
         * devolve 403 (o `plenoflu.com` por trás do `verpobreflix.net`), ou já
         * morreram e servem só de isca de SEO. O custo de abrir cada uma é alto —
         * a página demora a responder e a prova de mídia falha no fim —, e o
         * orçamento curto do fallback acaba antes de o agregador que funciona ser
         * alcançado. O `pobreflix.bike` e o `assistaonline.tv` foram medidos:
         * juntos consumiram 39 s de uma janela de 45 s e não entregaram fonte.
         */
        'pobreflix.bike',
        'pobreflix.tv',
        'assistaonline.tv',
        'assistironline.tv',
        // Sites adultos conhecidos (reforço da barreira de conteúdo).
        'xvideos.com',
        'xvideos-cdn.com',
        'pornhub.com',
        'phncdn.com',
        'xhamster.com',
        'xhcdn.com',
        'redtube.com',
        'rdtcdn.com',
        'youporn.com',
        'ypncdn.com',
        'spankbang.com',
        'beeg.com',
        'brazzers.com',
        'onlyfans.com',
        'chaturbate.com',
        'livejasmin.com',
        'cam4.com',
        'bongacams.com',
        'stripchat.com',
        'erome.com',
        'motherless.com',
        'tnaflix.com',
        'tube8.com',
        'porntrex.com',
        'hqporner.com',
        'eporner.com',
        'txxx.com',
        'hclips.com',
        'upornia.com',
        'porn300.com',
        'sex.com',
        'xnxx.com',
        'xnxx-cdn.com',
        'youjizz.com',
        'pornhd.com',
        'porn.com',
        'pornone.com',
        'pornhat.com',
        'porn00.com',
        'pornolab.net',
        'pornolab.cc',
        'pornolab.biz',
        'pornolab.org',
        'pornolab.me',
        'pornolab.tv',
        'pornolab.ws',
        'pornolab.io',
        'pornolab.to',
        'pornolab.se',
        'pornolab.nu',
        'pornolab.su',
        'pornolab.ru',
        'pornolab.com',
    ];

    /**
     * Domínios de país que nunca hospedam vídeo PT-BR.
     *
     * O SearXNG agrega instâncias do mundo inteiro e, para um título conhecido,
     * devolve páginas de fóruns e enciclopédias estrangeiras — Yahoo japonês,
     * Zhihu chinês, Naver coreano, portais russos. Nenhuma delas tem o vídeo
     * dublado que o fallback procura, e abrir cada uma custa uma requisição.
     *
     * A lista é de **sufixos de TLD**, não de hosts: `yahoo.co.jp` cobre
     * `news.yahoo.co.jp` e `search.yahoo.co.jp` de uma vez. Os TLDs de língua
     * portuguesa (`.br`, `.pt`) ficam de fora de propósito — são justamente os
     * que podem ter a página dublada.
     */
    private const TLDS_IGNORADOS = [
        '.jp',
        '.cn',
        '.kr',
        '.ru',
        '.ir',
        '.vn',
        '.th',
        '.id',
        '.tr',
        '.pl',
        '.ua',
        '.kz',
    ];

    /**
     * Extensões de arquivo que nunca são vídeo.
     *
     * O motor devolve PDFs, planilhas, documentos e pacotes — material de
     * referência *sobre* o título, não o vídeo. O extrator já os recusaria, mas
     * descartá-los aqui evita gastar uma requisição para descobrir isso.
     */
    private const EXTENSOES_IGNORADAS = [
        '.pdf',
        '.doc',
        '.docx',
        '.xls',
        '.xlsx',
        '.ppt',
        '.pptx',
        '.txt',
        '.zip',
        '.rar',
        '.7z',
        '.torrent',
        '.epub',
    ];

    /**
     * Domínios que só existem para redirecionar para adware.
     *
     * A cadeia foi rastreada na prática: o player do `pobreflix.bike` devolvia
     * uma URL do `guiadecapital.com`, que redirecionava para o `fgtd.online`,
     * que por fim abria um artigo aleatório sobre investimento em cavalos de
     * corrida. Não há vídeo em ponto nenhum da cadeia — é tráfego comprado, e o
     * "player" é só a isca. Um domínio assim nunca vira fonte, e deixá-lo passar
     * só gasta orçamento e polui o log.
     *
     * A lista é de **sufixos de host**, como a lista negra principal: `fgtd.online`
     * cobre `cdn.fgtd.online` sem casar um domínio que apenas termine com o texto.
     *
     * @var array<int, string>
     */
    private const DOMINIOS_DE_ADWARE = [
        'guiadecapital.com',
        'fgtd.online',
    ];

    /**
     * Marcas no corpo que denunciam um site morto, não uma página de vídeo.
     *
     * O `assistaonline.tv` respondia 200 com uma página do Vercel dizendo
     * "Deployment Paused" — o projeto expirou e o domínio ficou apontando para o
     * placeholder da hospedagem. O status é 200, então a checagem de bloqueio não
     * pega; o corpo, porém, é inequívoco. Procurar essas marcas antes da extração
     * evita gastar a prova de mídia e a renderização do FlareSolverr numa página
     * que nunca vai ter player.
     *
     * As marcas são frases inteiras, não palavras soltas: "paused" sozinho
     * apareceria num player pausado, e "deployment" num blog sobre deploy. A
     * combinação exata é o que identifica o placeholder da hospedagem.
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

    /**
     * Termos que denunciam loja ou comércio, não agregador de vídeo.
     *
     * A armadilha é o título que contém uma palavra comum de comércio. "Donas de
     * Casa Desesperadas" fez o motor devolver `donasloja.com.br`,
     * `donasacessorios.com.br` e `donasbijoux.com.br` — lojas que casam com
     * "Donas" e não têm vídeo nenhum. Cada uma custava uma requisição e um
     * pedaço do orçamento, e o agregador certo (`cinepoca`) só aparecia depois.
     *
     * A checagem é por **sufixo de segmento** do host ou do caminho: `donasloja`
     * termina em `loja` e casa; `lojado` termina em `jado` e não casa. É o mesmo
     * cuidado do [`FiltroConteudoAdulto`], que evita casar pedaço de palavra.
     */
    private const TERMOS_DE_COMERCIO = [
        'loja',
        'lojas',
        'shop',
        'store',
        'acessorios',
        'bijoux',
        'bijuterias',
        'produtos',
        'carrinho',
        'checkout',
        'comprar',
        'preco',
        'promocao',
        'mercado',
        'ecommerce',
    ];

    /**
     * Motores padrão, na ordem em que são tentados.
     *
     * O SearXNG interno do compose é o único motor padrão: ele sobe junto com o
     * stack, tem cota própria, devolve JSON limpo e não depende de terceiros. Se
     * um dia for preciso redundância, ela deve vir de outra instância SearXNG —
     * nunca de um buscador comercial que bloqueia o IP dos containers.
     *
     * O endereço é só o endpoint de busca (`http://searxng:8080/search`) — a
     * query e o `format=json` entram na hora da requisição. O host interno
     * `searxng` contém `searx`, então `tipoDoMotor()` o reconhece como SearXNG
     * sem precisar do prefixo `tipo:url`.
     */
    private const MOTORES_PADRAO = [
        'http://searxng:8080/search',
    ];

    /**
     * Domínios que, pela prática, hospedam player de vídeo.
     *
     * A lista negra corta o lixo grosso, mas não ordena o que sobra: o motor
     * devolve, para um título de série, uma mistura de agregadores de vídeo e
     * sites de nome parecido que passam pelo filtro sem ter player nenhum. Como o
     * provedor abre as páginas na ordem recebida e para no teto de páginas, um
     * punhado de páginas inúteis no topo consumia o orçamento antes de o
     * agregador certo ser alcançado — foi assim que o episódio 2 deixou de achar
     * o Tokyvideo que o episódio 1 achou.
     *
     * Esta lista **não** é critério de aceitação: ela só reordena. A prova de
     * mídia continua decidindo o que vira fonte, e um domínio desconhecido com
     * player segue valendo. O ganho é gastar as primeiras páginas do orçamento
     * com quem tem chance real de ter o vídeo.
     *
     * ## A lista foi enxugada: só quem tem player nativo e limpo
     *
     * A versão anterior misturava agregadores de verdade com clones piratas
     * instáveis e sites que já morreram. O custo disso era alto: o provedor
     * abria primeiro o `pobreflix.bike` (que responde com um player de anúncio
     * que redireciona para adware) e o `assistaonline.tv` (que hoje devolve uma
     * página "Deployment Paused" do Vercel), gastando o orçamento antes de
     * chegar ao `tokyvideo.com` — o único que de fato entrega o vídeo.
     *
     * O critério agora é estreito: entra quem **comprovadamente** hospeda o
     * vídeo num player nativo e limpo, sem depender de cadeia de redirecionamento
     * de anúncio. O `tokyvideo.com` é o caso de referência. Os clones de
     * `redecanais.*` saíram porque trocam de domínio toda semana e hoje caem em
     * estacionamento de domínio; os `pobreflix.*` de fachada saíram porque o
     * player deles é um redirecionador de adware, não um player.
     *
     * O `verpobreflix.net` é a exceção que confirma a regra: apesar do nome, é o
     * agregador que de fato monta a página do episódio e embute o player (o
     * `plenoflu.com` por trás). Ele entra no topo porque é o domínio que a busca
     * genérica alcança e que rende a fonte — subir na ordem faz o orçamento curto
     * chegar nele antes dos catálogos.
     */
    private const DOMINIOS_DE_VIDEO = [
        'verpobreflix.net',
        'tokyvideo.com',
        'cinepoca.com.br',
        'cinepoca.com',
        'dailymotion.com',
        'archive.org',
        'ok.ru',
        'vimeo.com',
    ];

    public function __construct(
        private readonly OrcamentoBusca $orcamento,
        private readonly ClienteHttp $cliente,
    ) {
    }

    /**
     * Busca um termo e devolve as URLs de resultado, na ordem em que vieram.
     *
     * Percorre todos os motores configurados e agrega os resultados. Um motor que
     * falha (rede, erro HTTP, JSON inválido) não impede os outros: a busca
     * degrada, não quebra. Quando um motor falha, o próximo assume — e a falha
     * não consome o orçamento dos termos seguintes.
     *
     * @return array<int, string>
     */
    public function procurar(string $termo): array
    {
        $termo = trim($termo);

        if ($termo === '') {
            return [];
        }

        $motores = $this->motores();

        Log::debug('Stream direto: consultando motores de busca.', [
            'termo' => $termo,
            'motores' => $motores,
        ]);

        $urls = [];
        $primeiraConsulta = true;

        foreach ($motores as $motor) {
            if (! $this->temOrcamento()) {
                Log::debug('Stream direto: orçamento esgotado durante a busca.', [
                    'termo' => $termo,
                    'motor' => $motor,
                ]);

                break;
            }

            /*
             * A espera vale entre consultas, não antes da primeira: atrasar a
             * abertura da busca só somaria latência sem proteger nada. Com mais
             * de um motor, a rajada é o que os buscadores punem.
             */
            if (! $primeiraConsulta) {
                $this->aguardarIntervalo();
            }

            $primeiraConsulta = false;

            $encontradas = $this->consultarMotor($motor, $termo);

            Log::debug('Stream direto: motor respondeu.', [
                'termo' => $termo,
                'motor' => $motor,
                'links' => count($encontradas),
            ]);

            /*
             * Um motor que falhou devolve zero links, mas não é o mesmo que "não
             * achou nada": o próximo endereço da lista ainda pode responder. O
             * laço segue em frente em vez de parar — é o fallback entre motores.
             */
            $urls = array_merge($urls, $encontradas);
        }

        $urls = array_values(array_unique($urls));

        /*
         * A reordenação vem depois da deduplicação: o mesmo agregador pode
         * aparecer em mais de um motor, e priorizar antes de deduplicar só
         * repetiria a mesma página no topo. O que sobe são os domínios com
         * vocação de player; o resto mantém a ordem do motor.
         */
        $urls = $this->priorizarPaginasDeVideo($urls);

        Log::debug('Stream direto: busca concluída.', [
            'termo' => $termo,
            'total_de_links' => count($urls),
        ]);

        return $urls;
    }

    /**
     * Sobe as páginas de domínios com vocação de player, preservando a ordem.
     *
     * A ordenação é estável: dentro de cada grupo (provável e improvável), a
     * ordem original do motor é mantida. Isso importa porque o ranqueamento do
     * motor ainda é sinal — só não pode ser o único, sob pena de o orçamento
     * acabar antes de o agregador certo ser aberto.
     *
     * @param  array<int, string>  $urls
     * @return array<int, string>
     */
    private function priorizarPaginasDeVideo(array $urls): array
    {
        $provaveis = [];
        $demais = [];

        foreach ($urls as $url) {
            if ($this->dominioDeVideo($url)) {
                $provaveis[] = $url;

                continue;
            }

            $demais[] = $url;
        }

        return array_merge($provaveis, $demais);
    }

    /**
     * Diz se a URL pertence a um domínio com vocação de player.
     *
     * A checagem é por sufixo de host, com o ponto à frente, para casar
     * subdomínios (`www.tokyvideo.com`) sem casar um domínio que apenas termine
     * com o mesmo texto.
     */
    private function dominioDeVideo(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        foreach (self::DOMINIOS_DE_VIDEO as $dominio) {
            if ($host === $dominio || str_ends_with($host, '.'.$dominio)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Consulta um motor e extrai os links de resultado.
     *
     * O tipo do motor decide a rota: o SearXNG e o Brave montam a requisição de
     * um jeito e leem a resposta de outro, mas ambos devolvem JSON. Uma falha
     * devolve lista vazia e o chamador tenta o próximo.
     *
     * @return array<int, string>
     */
    private function consultarMotor(string $motor, string $termo): array
    {
        $teto = $this->tempoDeConsulta((int) config('services.torrents.stream_direto_tempo_limite', 10));

        if ($teto <= 0) {
            return [];
        }

        $tipo = $this->tipoDoMotor($motor);

        try {
            $resposta = $this->requisitar($tipo, $motor, $termo, $teto);
        } catch (\Throwable $excecao) {
            Log::warning('Stream direto: falha ao consultar o motor de busca.', [
                'motor' => $motor,
                'tipo' => $tipo,
                'termo' => $termo,
                'erro' => $excecao->getMessage(),
            ]);

            return [];
        }

        if ($resposta === null) {
            Log::warning('Stream direto: motor não respondeu (sem tempo ou sem rede).', [
                'motor' => $motor,
                'tipo' => $tipo,
                'termo' => $termo,
            ]);

            return [];
        }

        if ($resposta->failed()) {
            Log::warning('Stream direto: motor devolveu erro HTTP.', [
                'motor' => $motor,
                'tipo' => $tipo,
                'termo' => $termo,
                'status' => $resposta->status(),
            ]);

            return [];
        }

        /*
         * A validação do corpo é o próprio `json_decode`: um corpo que não é JSON
         * (página de erro, HTML de bloqueio) já devolve lista vazia. Não há mais
         * varredura por marcas de bloqueio nem tratamento de status 202 — isso
         * existia só para o HTML do DuckDuckGo, que respondia 200 com página de
         * captcha no corpo.
         */
        return $this->extrairResultadosJson((string) $resposta->body());
    }

    /**
     * Monta a requisição conforme o tipo do motor.
     *
     * O SearXNG recebe o termo por query string (`q`) e pede `format=json` para
     * devolver JSON em vez de HTML. O Brave também recebe `q`, mas exige o
     * cabeçalho de chave.
     */
    private function requisitar(string $tipo, string $motor, string $termo, int $teto): ?Response
    {
        return match ($tipo) {
            'brave' => $this->cliente->get(
                $motor,
                ['q' => $termo],
                $this->navegador(),
                $teto,
                $this->cabecalhosBrave()
            ),
            default => $this->cliente->get(
                $motor,
                ['q' => $termo, 'format' => 'json'],
                $this->navegador(),
                $teto
            ),
        };
    }

    /**
     * Cabeçalhos da API do Brave Search.
     *
     * A API oficial exige a chave em `X-Subscription-Token` e o `Accept` de JSON.
     * Sem a chave configurada, o motor é pulado antes de chegar aqui.
     *
     * @return array<string, string>
     */
    private function cabecalhosBrave(): array
    {
        return [
            'Accept' => 'application/json',
            'X-Subscription-Token' => (string) config('services.torrents.stream_direto_brave_key', ''),
        ];
    }

    /**
     * Descobre o tipo de um motor a partir do endereço ou do prefixo declarado.
     *
     * O prefixo `tipo:url` vence sempre — é o jeito de forçar um tipo quando o
     * host não denuncia (uma instância SearXNG em domínio próprio, por exemplo).
     * Sem prefixo, o host decide: `searx` vira SearXNG, `brave` vira Brave. Um
     * endereço que não casa com nenhum dos dois é tratado como SearXNG, que é o
     * motor padrão do projeto.
     */
    private function tipoDoMotor(string $motor): string
    {
        if (str_contains($motor, ':')) {
            [$possivelTipo, $resto] = explode(':', $motor, 2);

            if (in_array($possivelTipo, ['searxng', 'brave'], true) && $resto !== '') {
                return $possivelTipo;
            }
        }

        $host = strtolower((string) parse_url($motor, PHP_URL_HOST));

        if (str_contains($host, 'brave')) {
            return 'brave';
        }

        return 'searxng';
    }

    /**
     * Extrai as URLs de resultado de uma resposta JSON (SearXNG ou Brave).
     *
     * O SearXNG devolve `{"results": [{"url": "..."}]}`; o Brave devolve
     * `{"web": {"results": [{"url": "..."}]}}`. Os dois formatos são aceitos, e a
     * lista negra de domínios vale igual.
     *
     * @return array<int, string>
     */
    private function extrairResultadosJson(string $corpo): array
    {
        if ($corpo === '') {
            return [];
        }

        $dados = json_decode($corpo, true);

        if (! is_array($dados)) {
            return [];
        }

        $itens = $dados['results'] ?? $dados['web']['results'] ?? [];

        if (! is_array($itens)) {
            return [];
        }

        $urls = [];
        $ignorados = [];

        foreach ($itens as $item) {
            if (! is_array($item)) {
                continue;
            }

            $url = trim((string) ($item['url'] ?? ''));

            if ($url === '' || ! preg_match('#^https?://#i', $url)) {
                continue;
            }

            $motivo = $this->motivoDoDescarte($url);

            if ($motivo !== null) {
                $ignorados[$motivo][] = (string) parse_url($url, PHP_URL_HOST);

                continue;
            }

            $urls[] = $url;
        }

        if ($ignorados !== []) {
            /*
             * O log separa os motivos para o diagnóstico ser acionável: muitos
             * descartes por `tld` significam que a query está a alcançar acervo
             * estrangeiro; muitos por `dominio` significam que a lista negra
             * está a segurar fóruns. Sem essa separação, só se saberia que
             * "algo" foi ignorado.
             */
            Log::debug('Stream direto: resultados irrelevantes ignorados.', [
                'por_motivo' => array_map(
                    static fn (array $hosts): array => [
                        'quantidade' => count($hosts),
                        'dominios' => array_values(array_unique($hosts)),
                    ],
                    $ignorados
                ),
            ]);
        }

        return array_values(array_unique($urls));
    }

    /**
     * Espera um intervalo sorteado antes da próxima consulta ao motor.
     *
     * Buscadores bloqueiam rajadas: consultar os termos em sequência, sem pausa,
     * é o caminho mais curto para o rate limit. O atraso é sorteado entre o
     * mínimo e o máximo a cada consulta — uma cadência fixa também é padrão de
     * bot, e o sorteio imita o ritmo irregular de quem digita e clica.
     *
     * A espera nunca ultrapassa o orçamento restante: se o que sobra é menor que
     * o intervalo sorteado, dormir até o fim só atrasaria a resposta sem ganhar
     * consulta nenhuma. Nesse caso, o laço de chamada já vai parar pelo
     * `temOrcamento()`.
     */
    private function aguardarIntervalo(): void
    {
        $minimo = max(0, (int) config('services.torrents.stream_direto_intervalo_min', 800));
        $maximo = max($minimo, (int) config('services.torrents.stream_direto_intervalo_max', 2200));

        if ($maximo <= 0) {
            return;
        }

        $espera = random_int($minimo, $maximo);

        $restante = $this->orcamento->restante();

        if ($restante !== null) {
            $restanteMs = $restante * 1000;

            if ($restanteMs <= 0) {
                return;
            }

            $espera = min($espera, $restanteMs);
        }

        Log::debug('Stream direto: aguardando intervalo entre consultas.', [
            'ms' => $espera,
        ]);

        usleep($espera * 1000);
    }

    /**
     * User-Agent de navegador comum para as consultas ao motor de busca.
     *
     * Um User-Agent de robô (`GuzzleHttp/...`, `curl/...`) é o primeiro item que
     * um filtro anti-bot olha. O valor vem da mesma chave dos provedores nativos
     * (`services.torrents.user_agent`), para não haver dois agentes diferentes
     * no mesmo processo.
     */
    private function navegador(): string
    {
        $agente = (string) config('services.torrents.user_agent', '');

        // A chave pode existir e vir vazia (env ausente no ambiente de teste),
        // e nesse caso o `config()` devolve string vazia em vez do padrão. O
        // `?:` garante que o agente de navegador sempre valha.
        return $agente !== ''
            ? $agente
            : 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
    }

    /**
     * Diz se a URL pertence a um domínio de catálogo/metadados da lista negra.
     *
     * A checagem é pelo host, com o ponto à frente, para casar subdomínios
     * (`www.imdb.com`) sem casar domínios que apenas terminam com o mesmo texto
     * (`naoimdb.com`). URLs sem host válido não são descartadas aqui — quem
     * decide se servem é o extrator.
     */
    private function dominioIgnorado(string $url): bool
    {
        return $this->motivoDoDescarte($url) !== null;
    }

    /**
     * Diz se a barreira de conteúdo impróprio está ligada.
     *
     * A chave existe para poder desligar a barreira sem reverter código — útil
     * ao depurar um falso positivo. Ligada por padrão: o custo é uma comparação
     * de strings por resultado, e o benefício é não abrir uma página adulta.
     */
    private function filtroAdultoAtivo(): bool
    {
        return (bool) config('services.torrents.stream_direto_filtro_adulto', true);
    }

    /**
     * Classifica por que uma URL foi descartada, ou `null` se ela serve.
     *
     * Separar o motivo permite um log útil: saber que 12 links caíram por TLD
     * estrangeiro é diferente de saber que caíram por serem fóruns. A ordem das
     * checagens vai do mais barato ao mais caro — host vazio, extensão, domínio
     * e, por fim, TLD.
     *
     * A checagem de conteúdo adulto vem **primeiro**, antes de qualquer outra:
     * um link impróprio não pode nem ser classificado como "domínio de catálogo"
     * no log, e o descarte precisa ser inequívoco. É a barreira que impede o
     * `xvideos-cdn.com` de virar candidato a página.
     *
     * @return string|null `adulto`, `extensao`, `loja`, `dominio`, `adware`, `tld` ou `null`
     */
    private function motivoDoDescarte(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return null;
        }

        if ($this->filtroAdultoAtivo() && FiltroConteudoAdulto::urlBloqueada($url)) {
            return 'adulto';
        }

        $caminho = strtolower((string) parse_url($url, PHP_URL_PATH));

        foreach (self::EXTENSOES_IGNORADAS as $extensao) {
            if (str_ends_with($caminho, $extensao)) {
                return 'extensao';
            }
        }

        /*
         * A loja é descartada antes da lista negra: o host dela não está lá, e
         * sem esta checagem ela passaria e consumiria uma requisição. O descarte
         * é por segmento, não por substring — "donasloja" casa "loja", mas
         * "lojado" não.
         */
        if ($this->pareceLoja($host, $caminho)) {
            return 'loja';
        }

        foreach (self::DOMINIOS_IGNORADOS as $dominio) {
            if ($host === $dominio || str_ends_with($host, '.'.$dominio)) {
                return 'dominio';
            }
        }

        /*
         * O adware é classificado à parte da lista negra comum: no log, saber
         * que um link caiu por ser redirecionador de anúncio é diferente de
         * saber que caiu por ser fórum. A checagem vem depois da lista negra
         * principal porque os dois conjuntos não se sobrepõem — é só para o
         * motivo ficar preciso.
         */
        foreach (self::DOMINIOS_DE_ADWARE as $dominio) {
            if ($host === $dominio || str_ends_with($host, '.'.$dominio)) {
                return 'adware';
            }
        }

        foreach (self::TLDS_IGNORADOS as $tld) {
            if (str_ends_with($host, $tld)) {
                return 'tld';
            }
        }

        return null;
    }

    /**
     * Diz se o host ou o caminho tem cara de loja, não de agregador de vídeo.
     *
     * A checagem quebra o host e o caminho em segmentos e compara cada um com a
     * lista de termos de comércio. A comparação é por **sufixo do segmento**, e
     * não por igualdade: `donasloja` termina em `loja` e casa; `donasacessorios`
     * termina em `acessorios` e casa. Já `lojado` termina em `jado` e não casa —
     * o cuidado é não confundir uma palavra que apenas contém o termo com uma
     * que o carrega no fim, que é como as lojas de fato se nomeiam.
     *
     * O caminho entra na conta porque muitas lojas usam o host genérico e
     * separam o setor na URL (`/produtos/`, `/carrinho/`).
     */
    private function pareceLoja(string $host, string $caminho): bool
    {
        $segmentos = preg_split('/[.\-\/]+/', $host.'/'.$caminho) ?: [];

        foreach ($segmentos as $segmento) {
            if ($segmento === '') {
                continue;
            }

            foreach (self::TERMOS_DE_COMERCIO as $termo) {
                if (str_ends_with($segmento, $termo)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Motores de busca configurados, já normalizados.
     *
     * A lista vem de `stream_direto_motores`. Vazia, cai no motor padrão (o
     * SearXNG interno). O Brave só entra se houver chave configurada — sem ela, o
     * motor devolveria 401 e só gastaria orçamento.
     *
     * @return array<int, string>
     */
    private function motores(): array
    {
        $motores = config('services.torrents.stream_direto_motores', []);

        if (! is_array($motores) || $motores === []) {
            $motores = self::MOTORES_PADRAO;
        }

        $motores = array_values(array_filter(
            array_map(static fn ($endereco): string => trim((string) $endereco), $motores),
            static fn (string $endereco): bool => $endereco !== ''
        ));

        /*
         * O Brave sem chave é um motor morto: a API responde 401 e o endereço só
         * ocuparia uma volta do laço. Ele é descartado aqui, não na hora da
         * requisição, para o log de motores refletir o que de fato será tentado.
         */
        $temChaveBrave = (string) config('services.torrents.stream_direto_brave_key', '') !== '';

        $motores = array_values(array_filter(
            $motores,
            fn (string $endereco): bool => $this->tipoDoMotor($endereco) !== 'brave' || $temChaveBrave
        ));

        return $motores === [] ? self::MOTORES_PADRAO : $motores;
    }
}
