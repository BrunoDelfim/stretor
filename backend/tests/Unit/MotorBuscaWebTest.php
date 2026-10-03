<?php

namespace Tests\Unit;

use App\Services\Torrents\MotorBuscaWeb;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O motor de busca é a porta de entrada do scraper: se ele não devolve as URLs
 * certas, nenhuma página é aberta e o fallback morre na praia. O que importa
 * testar aqui é a leitura do JSON do SearXNG e do Brave — o formato dos
 * resultados e o descarte dos domínios de catálogo.
 */
class MotorBuscaWebTest extends TestCase
{
    /**
     * Invoca um método privado do serviço sem tocar na rede.
     */
    private function invocar(string $metodo, mixed ...$argumentos): mixed
    {
        $servico = app(MotorBuscaWeb::class);
        $reflexao = new \ReflectionMethod($servico, $metodo);
        $reflexao->setAccessible(true);

        return $reflexao->invoke($servico, ...$argumentos);
    }

    public function test_motores_padrao_quando_a_config_esta_vazia(): void
    {
        config()->set('services.torrents.stream_direto_motores', []);

        // O SearXNG interno do compose é o único motor padrão: sobe junto com o
        // stack, tem cota própria e devolve JSON limpo. O DuckDuckGo foi
        // removido do projeto.
        $this->assertSame(
            ['http://searxng:8080/search'],
            $this->invocar('motores')
        );
    }

    public function test_motores_configurados_sao_respeitados(): void
    {
        config()->set('services.torrents.stream_direto_motores', ['https://searx.be/search']);

        $this->assertSame(['https://searx.be/search'], $this->invocar('motores'));
    }

    public function test_motor_brave_sem_chave_e_descartado(): void
    {
        config()->set('services.torrents.stream_direto_motores', [
            'http://searxng:8080/search',
            'https://api.search.brave.com/res/v1/web/search',
        ]);
        config()->set('services.torrents.stream_direto_brave_key', '');

        // Sem chave, o Brave responderia 401 e só gastaria uma volta do laço.
        $this->assertSame(
            ['http://searxng:8080/search'],
            $this->invocar('motores')
        );
    }

    public function test_motor_brave_com_chave_e_mantido(): void
    {
        config()->set('services.torrents.stream_direto_motores', [
            'https://api.search.brave.com/res/v1/web/search',
        ]);
        config()->set('services.torrents.stream_direto_brave_key', 'chave-de-teste');

        $this->assertSame(
            ['https://api.search.brave.com/res/v1/web/search'],
            $this->invocar('motores')
        );
    }

    public function test_tipo_do_motor_e_inferido_do_host(): void
    {
        $this->assertSame('searxng', $this->invocar('tipoDoMotor', 'https://searx.be/search'));
        $this->assertSame('brave', $this->invocar('tipoDoMotor', 'https://api.search.brave.com/res/v1/web/search'));
    }

    public function test_host_interno_do_searxng_e_reconhecido(): void
    {
        // O host `searxng` do compose contém `searx`, então é inferido como
        // SearXNG sem precisar do prefixo `tipo:url` — é o que faz o endereço
        // interno funcionar sem configuração extra.
        $this->assertSame('searxng', $this->invocar('tipoDoMotor', 'http://searxng:8080/search'));
    }

    public function test_endereco_desconhecido_cai_no_searxng(): void
    {
        // Sem prefixo e sem host que denuncie, o motor é tratado como SearXNG —
        // que é o padrão do projeto. O DuckDuckGo não é mais um destino possível.
        $this->assertSame('searxng', $this->invocar('tipoDoMotor', 'https://busca.exemplo.com'));
    }

    public function test_prefixo_forca_o_tipo_do_motor(): void
    {
        // Uma instância SearXNG em domínio próprio não denuncia o tipo pelo host;
        // o prefixo `tipo:url` é o jeito de forçá-lo.
        $this->assertSame('searxng', $this->invocar('tipoDoMotor', 'searxng:https://busca.exemplo.com'));
        $this->assertSame('brave', $this->invocar('tipoDoMotor', 'brave:https://busca.exemplo.com'));
    }

    public function test_searxng_monta_a_query_com_format_json(): void
    {
        Http::fake([
            'searx.be/*' => Http::response(json_encode([
                'results' => [['url' => 'https://agregador.com/assistir/filme']],
            ]), 200),
        ]);

        $resultados = $this->invocar('consultarMotor', 'https://searx.be/search', 'filme dublado');

        // O endpoint é só o `/search`; a query e o `format=json` são acrescentados
        // na hora — é o que faz o SearXNG devolver JSON em vez de HTML.
        Http::assertSent(function ($requisicao): bool {
            return str_contains($requisicao->url(), 'searx.be/search')
                && str_contains($requisicao->url(), 'format=json')
                && str_contains($requisicao->url(), 'q=filme');
        });

        $this->assertSame(['https://agregador.com/assistir/filme'], $resultados);
    }

    public function test_extrai_resultados_do_json_do_searxng(): void
    {
        $json = json_encode([
            'results' => [
                ['url' => 'https://agregador.com/assistir/filme'],
                ['url' => 'https://www.imdb.com/title/tt123'],
            ],
        ]);

        // O formato do SearXNG é `results[].url`; a lista negra vale igual.
        $this->assertSame(
            ['https://agregador.com/assistir/filme'],
            $this->invocar('extrairResultadosJson', $json)
        );
    }

    public function test_extrai_resultados_do_json_do_brave(): void
    {
        $json = json_encode([
            'web' => [
                'results' => [
                    ['url' => 'https://agregador.com/assistir/filme'],
                ],
            ],
        ]);

        // O Brave aninha em `web.results[].url`.
        $this->assertSame(
            ['https://agregador.com/assistir/filme'],
            $this->invocar('extrairResultadosJson', $json)
        );
    }

    public function test_json_invalido_nao_quebra(): void
    {
        $this->assertSame([], $this->invocar('extrairResultadosJson', 'não é json'));
        $this->assertSame([], $this->invocar('extrairResultadosJson', ''));
    }

    public function test_json_do_searxng_com_captcha_no_payload_nao_e_bloqueio(): void
    {
        /*
         * O SearXNG carrega a palavra "captcha" na própria estrutura do JSON (o
         * campo que diz se o motor pediu captcha). A leitura é por `json_decode`,
         * não por varredura de texto, então o campo não derruba a resposta.
         */
        $json = json_encode([
            'results' => [
                ['url' => 'https://agregador.com/assistir/filme'],
            ],
            'unresponsive_engines' => [],
            'captcha' => false,
        ]);

        $this->assertSame(
            ['https://agregador.com/assistir/filme'],
            $this->invocar('extrairResultadosJson', $json)
        );
    }

    public function test_dominios_de_catalogo_sao_ignorados(): void
    {
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://www.justwatch.com/br/filme/x'));
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://www.imdb.com/title/tt123'));
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://watch.plex.tv/movie/x'));
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://www.youtube.com/watch?v=abc'));
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://pt.wikipedia.org/wiki/X'));
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://www.themoviedb.org/movie/1'));
    }

    public function test_dominio_parecido_nao_e_ignorado(): void
    {
        // O casamento é pelo host inteiro: um domínio que apenas termina com o
        // mesmo texto não pode ser confundido com a lista negra.
        $this->assertFalse($this->invocar('dominioIgnorado', 'https://naoimdb.com/filme'));
        $this->assertFalse($this->invocar('dominioIgnorado', 'https://meuyoutube.com/video'));
    }

    public function test_url_sem_host_nao_e_ignorada(): void
    {
        $this->assertFalse($this->invocar('dominioIgnorado', ''));
        $this->assertFalse($this->invocar('dominioIgnorado', '/caminho/relativo'));
    }

    public function test_foruns_e_redes_sociais_sao_ignorados(): void
    {
        // Fóruns, Q&A e redes sociais discutem o título, mas nunca hospedam o
        // arquivo de vídeo — abrir cada um só gasta o orçamento do fallback.
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://www.reddit.com/r/filmes/x'));
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://pt.quora.com/o-que-e-x'));
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://www.zhihu.com/question/1'));
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://www.facebook.com/pagina'));
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://x.com/perfil'));
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://t.me/canal'));
    }

    /**
     * O caso que motivou a correção: o `dramatotal.fandom.com` apareceu para
     * "American Horror Story" e rendeu seis vídeos que nada tinham a ver com o
     * episódio. A página de fandom embute o trailer no YouTube, então passa pela
     * prova de mídia — mas o vídeo que ela embute é um trecho aleatório, não o
     * episódio. Wiki não hospeda o episódio; hospeda o verbete sobre ele.
     */
    public function test_wikis_de_fandom_sao_ignoradas(): void
    {
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://dramatotal.fandom.com/pt-br/wiki/Gwen'));
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://americanhorrorstory.fandom.com/wiki/American_Horror_Story'));
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://qualquer.wikia.com/wiki/X'));
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://qualquer.wiki.gg/wiki/X'));
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://qualquer.miraheze.org/wiki/X'));
        $this->assertSame('dominio', $this->invocar('motivoDoDescarte', 'https://dramatotal.fandom.com/pt-br/wiki/Gwen'));
    }

    public function test_dominio_parecido_com_fandom_nao_e_ignorado(): void
    {
        // O casamento é pelo host inteiro: um domínio que apenas termina com o
        // mesmo texto não pode ser confundido com a lista negra.
        $this->assertFalse($this->invocar('dominioIgnorado', 'https://naofandom.com/filme'));
        $this->assertFalse($this->invocar('dominioIgnorado', 'https://meuwikia.com/video'));
    }

    /**
     * O caso que motivou a ampliação: "American Horror Story" devolveu a
     * American Airlines (`aa.com.br`, `aa.com`) e a Câmara Americana de Comércio
     * (`amcham.com.br`). Três páginas sem vídeo nenhum que consumiram o
     * orçamento curto do fallback. O motor casa o título com o nome da empresa
     * quando as palavras coincidem.
     */
    public function test_empresas_homonimas_sao_ignoradas(): void
    {
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://www.aa.com.br/homePage.do'));
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://www.aa.com/homePage.do'));
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://www.amcham.com.br/'));
        $this->assertSame('dominio', $this->invocar('motivoDoDescarte', 'https://www.aa.com.br/homePage.do'));
    }

    /**
     * "Donas de Casa Desesperadas" puxa páginas de imobiliária e classificados
     * de aluguel. Nenhuma delas é fonte de mídia.
     */
    public function test_sites_de_imovel_e_viagem_sao_ignorados(): void
    {
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://www.quintoandar.com.br/imovel/123'));
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://www.zapimoveis.com.br/aluguel/x'));
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://www.booking.com/hotel/br/x.html'));
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://www.airbnb.com.br/rooms/123'));
    }

    /**
     * O controle: um domínio que apenas contém o texto de uma empresa da lista
     * não pode ser barrado. O casamento é pelo host inteiro.
     */
    public function test_dominio_parecido_com_empresa_nao_e_ignorado(): void
    {
        $this->assertFalse($this->invocar('dominioIgnorado', 'https://meuaa.com/filme'));
        $this->assertFalse($this->invocar('dominioIgnorado', 'https://naoamcham.com.br/video'));
    }

    public function test_tld_estrangeiro_e_ignorado(): void
    {
        // O SearXNG agrega instâncias do mundo inteiro e devolve enciclopédias e
        // fóruns estrangeiros para qualquer título conhecido. Nenhum deles tem o
        // vídeo dublado que o fallback procura.
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://news.yahoo.co.jp/articles/x'));
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://site.ru/filme'));
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://portal.cn/video'));
    }

    public function test_tld_portugues_nao_e_ignorado(): void
    {
        // `.br` e `.pt` ficam de fora da lista de TLDs de propósito: são
        // justamente os que podem ter a página dublada.
        $this->assertFalse($this->invocar('dominioIgnorado', 'https://site.com.br/filme'));
        $this->assertFalse($this->invocar('dominioIgnorado', 'https://site.pt/filme'));
    }

    public function test_consultas_de_cnpj_sao_ignoradas(): void
    {
        // A busca por um título com número ("S01E01") faz o motor devolver
        // consultas de CNPJ: o padrão casa com o formato de inscrição que esses
        // sites indexam. Nenhuma tem vídeo, e abrir cada uma consumia o orçamento
        // curto do fallback — foi assim que três consultas zeraram a busca.
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://checacnpj.com.br/'));
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://cnpjcheck.com.br/'));
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://datapj.com.br/consultar-cnpj'));
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://www.cnpja.com/consulta'));
        $this->assertSame('dominio', $this->invocar('motivoDoDescarte', 'https://checacnpj.com.br/'));
    }

    public function test_extensao_de_arquivo_nao_video_e_ignorada(): void
    {
        // PDFs, planilhas e pacotes são material de referência *sobre* o título,
        // não o vídeo. Descartá-los aqui evita uma requisição inútil.
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://site.com/artigo.pdf'));
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://site.com/planilha.xlsx'));
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://site.com/pacote.zip'));
    }

    public function test_motivo_do_descarte_classifica_a_causa(): void
    {
        // O motivo separado é o que torna o log acionável: saber que os descartes
        // vieram de TLD estrangeiro é diferente de saber que vieram de fóruns.
        $this->assertSame('extensao', $this->invocar('motivoDoDescarte', 'https://site.com/x.pdf'));
        $this->assertSame('dominio', $this->invocar('motivoDoDescarte', 'https://www.imdb.com/title/tt1'));
        $this->assertSame('tld', $this->invocar('motivoDoDescarte', 'https://site.ru/filme'));
        $this->assertNull($this->invocar('motivoDoDescarte', 'https://agregador.com/assistir/filme'));
    }

    /**
     * O caso que motivou a correção: "Donas de Casa Desesperadas" fez o motor
     * devolver lojas que casam com a palavra "Donas". Cada uma custava uma
     * requisição e um pedaço do orçamento, e o agregador certo só aparecia depois.
     */
    public function test_loja_e_descartada_na_origem(): void
    {
        $this->assertSame('loja', $this->invocar('motivoDoDescarte', 'https://www.donasloja.com.br/'));
        $this->assertSame('loja', $this->invocar('motivoDoDescarte', 'https://www.donasacessorios.com.br/'));
        $this->assertSame('loja', $this->invocar('motivoDoDescarte', 'https://donasbijoux.com.br/'));
        $this->assertSame('loja', $this->invocar('motivoDoDescarte', 'https://www.donasloja.com.br/produtos/'));
    }

    public function test_loja_no_caminho_tambem_e_descartada(): void
    {
        // Muitas lojas usam host genérico e separam o setor na URL.
        $this->assertSame('loja', $this->invocar('motivoDoDescarte', 'https://site.com/produtos/123'));
        $this->assertSame('loja', $this->invocar('motivoDoDescarte', 'https://site.com/carrinho'));
    }

    public function test_palavra_que_apenas_contem_termo_de_loja_nao_e_descartada(): void
    {
        // A checagem é por segmento, não por substring: "lojado" não é loja, e um
        // agregador legítimo não pode cair por causa de um pedaço de palavra.
        $this->assertNull($this->invocar('motivoDoDescarte', 'https://lojado.com/assistir/filme'));
        $this->assertNull($this->invocar('motivoDoDescarte', 'https://agregador.com/assistir/filme'));
    }

    /**
     * O caso que motivou a correção: o buscador indexou `xvideos-cdn.com` para
     * "Donas de Casa Desesperadas". A URL precisa ser descartada na origem, antes
     * de virar candidata a página e gastar orçamento.
     */
    public function test_dominio_adulto_e_descartado_na_origem(): void
    {
        $this->assertSame('adulto', $this->invocar('motivoDoDescarte', 'https://xvideos-cdn.com/video.mp4'));
        $this->assertSame('adulto', $this->invocar('motivoDoDescarte', 'https://www.pornhub.com/view_video.php'));
        $this->assertTrue($this->invocar('dominioIgnorado', 'https://xhamster.com/videos/x'));
    }

    public function test_url_com_palavra_chave_adulta_e_descartada(): void
    {
        // A palavra-chave pega o que a lista de domínios ainda não conhece.
        $this->assertSame('adulto', $this->invocar('motivoDoDescarte', 'https://desconhecido.com/porn-video-123'));
    }

    public function test_extrair_resultados_json_descarta_conteudo_adulto(): void
    {
        $json = json_encode([
            'results' => [
                ['url' => 'https://xvideos-cdn.com/video.mp4'],
                ['url' => 'https://desconhecido.com/porn-video'],
                ['url' => 'https://agregador.com/assistir/filme'],
            ],
        ]);

        // Só o agregador legítimo sobrevive: os links adultos caem antes de
        // virarem candidatos a página.
        $this->assertSame(
            ['https://agregador.com/assistir/filme'],
            $this->invocar('extrairResultadosJson', $json)
        );
    }

    public function test_filtro_adulto_desligado_deixa_passar(): void
    {
        // A chave existe para depurar um falso positivo sem reverter código.
        // O domínio usado aqui é adulto só para o `FiltroConteudoAdulto`: ele não
        // está na lista negra geral do `MotorBuscaWeb`, então desligar a barreira
        // é o que decide o resultado — sem isso, o descarte viria por `dominio`.
        config()->set('services.torrents.stream_direto_filtro_adulto', false);

        $this->assertNull($this->invocar('motivoDoDescarte', 'https://rule34.xxx/video.mp4'));
    }

    public function test_extrair_resultados_json_descarta_foruns_e_estrangeiros(): void
    {
        $json = json_encode([
            'results' => [
                ['url' => 'https://news.yahoo.co.jp/articles/x'],
                ['url' => 'https://www.zhihu.com/question/1'],
                ['url' => 'https://site.com/artigo.pdf'],
                ['url' => 'https://agregador.com/assistir/filme'],
            ],
        ]);

        // Só o agregador de vídeo sobrevive: fórum estrangeiro, Q&A e PDF caem
        // antes de virarem candidatos a página.
        $this->assertSame(
            ['https://agregador.com/assistir/filme'],
            $this->invocar('extrairResultadosJson', $json)
        );
    }

    public function test_extrair_resultados_json_descarta_dominios_de_catalogo(): void
    {
        $json = json_encode([
            'results' => [
                ['url' => 'https://www.imdb.com/title/tt123'],
                ['url' => 'https://www.justwatch.com/br/filme/x'],
                ['url' => 'https://agregador.com/assistir/filme'],
            ],
        ]);

        // Só o agregador sobrevive: os catálogos são descartados antes de virarem
        // candidatos a página, poupando o orçamento curto do fallback.
        $this->assertSame(
            ['https://agregador.com/assistir/filme'],
            $this->invocar('extrairResultadosJson', $json)
        );
    }

    public function test_extrair_resultados_json_so_com_catalogo_devolve_vazio(): void
    {
        $json = json_encode([
            'results' => [
                ['url' => 'https://www.imdb.com/title/tt123'],
                ['url' => 'https://www.youtube.com/watch?v=abc'],
            ],
        ]);

        $this->assertSame([], $this->invocar('extrairResultadosJson', $json));
    }

    public function test_intervalo_zero_nao_espera(): void
    {
        config()->set('services.torrents.stream_direto_intervalo_min', 0);
        config()->set('services.torrents.stream_direto_intervalo_max', 0);

        $inicio = microtime(true);
        $this->invocar('aguardarIntervalo');
        $decorrido = microtime(true) - $inicio;

        // Com o intervalo zerado, a espera é pulada — é o que os testes usam
        // para não pagar o atraso real.
        $this->assertLessThan(0.05, $decorrido);
    }

    public function test_intervalo_espera_dentro_do_teto_configurado(): void
    {
        config()->set('services.torrents.stream_direto_intervalo_min', 10);
        config()->set('services.torrents.stream_direto_intervalo_max', 20);

        $inicio = microtime(true);
        $this->invocar('aguardarIntervalo');
        $decorrido = microtime(true) - $inicio;

        // A espera é sorteada entre 10 e 20 ms; a folga cobre o custo do próprio
        // `usleep` e do agendador.
        $this->assertGreaterThanOrEqual(0.008, $decorrido);
        $this->assertLessThan(0.2, $decorrido);
    }

    public function test_navegador_usa_o_agente_configurado(): void
    {
        config()->set('services.torrents.user_agent', 'AgenteDeTeste/1.0');

        $this->assertSame('AgenteDeTeste/1.0', $this->invocar('navegador'));
    }

    public function test_navegador_tem_um_padrao_de_navegador_real(): void
    {
        config()->set('services.torrents.user_agent', null);

        $agente = $this->invocar('navegador');

        // Um agente de robô é o primeiro item que um filtro anti-bot olha.
        $this->assertStringContainsString('Mozilla/5.0', $agente);
        $this->assertStringNotContainsString('Guzzle', $agente);
        $this->assertStringNotContainsString('curl', $agente);
    }

    public function test_pagina_de_dominio_de_video_sobe_na_ordem(): void
    {
        $urls = [
            'https://www.donasloja.com.br/',
            'https://www.tokyvideo.com/br/video/desperate-housewives-pt-01x02',
            'https://bancodeseries.com.br/index.php?action=ss&serieid=1171',
        ];

        $ordenadas = $this->invocar('priorizarPaginasDeVideo', $urls);

        // O agregador com player vem primeiro; os demais mantêm a ordem original.
        $this->assertSame('https://www.tokyvideo.com/br/video/desperate-housewives-pt-01x02', $ordenadas[0]);
        $this->assertSame('https://www.donasloja.com.br/', $ordenadas[1]);
        $this->assertSame('https://bancodeseries.com.br/index.php?action=ss&serieid=1171', $ordenadas[2]);
    }

    public function test_dominio_sem_player_conhecido_nao_sobe(): void
    {
        // O `bancodeseries.com.br` apareceu nos logs como "página sem prova de
        // mídia": ele passa pelo filtro de domínio, mas não tem player. Não pode
        // ocupar as primeiras páginas do orçamento.
        $this->assertFalse($this->invocar('dominioDeVideo', 'https://bancodeseries.com.br/index.php?action=ss&serieid=1171'));
    }

    public function test_ordem_original_e_preservada_dentro_de_cada_grupo(): void
    {
        $urls = [
            'https://site-a.com/pagina',
            'https://www.tokyvideo.com/br/video/1',
            'https://site-b.com/pagina',
            'https://cinepoca.com.br/episodio/2',
        ];

        $ordenadas = $this->invocar('priorizarPaginasDeVideo', $urls);

        $this->assertSame([
            'https://www.tokyvideo.com/br/video/1',
            'https://cinepoca.com.br/episodio/2',
            'https://site-a.com/pagina',
            'https://site-b.com/pagina',
        ], $ordenadas);
    }

    public function test_dominio_morto_ou_de_adware_nao_sobe_na_ordem(): void
    {
        // O `pobreflix.bike` (player de adware) e o `assistaonline.tv` (site
        // morto) saíram da lista de domínios de vídeo. Eles não podem mais subir
        // na ordem — o orçamento tem de ir para quem de fato entrega o vídeo.
        $this->assertFalse($this->invocar('dominioDeVideo', 'https://pobreflix.bike/filme/1'));
        $this->assertFalse($this->invocar('dominioDeVideo', 'https://assistaonline.tv/serie/1'));
        $this->assertFalse($this->invocar('dominioDeVideo', 'https://redecanais.hair/episodio/2'));
    }

    public function test_dominio_de_adware_e_descartado_na_origem(): void
    {
        // A cadeia de redirecionamento do player do `pobreflix.bike` terminava no
        // `guiadecapital.com` e no `fgtd.online`, que só servem adware.
        $this->assertSame('adware', $this->invocar('motivoDoDescarte', 'https://guiadecapital.com/campaign.php?token=abc'));
        $this->assertSame('adware', $this->invocar('motivoDoDescarte', 'https://cdn.fgtd.online/redirect'));
    }

    public function test_dominio_parecido_com_adware_nao_e_descartado(): void
    {
        // A comparação é por sufixo de host com o ponto à frente: um domínio que
        // apenas termina com o texto não é o adware.
        $this->assertNull($this->invocar('motivoDoDescarte', 'https://naoguiadecapital.com/pagina'));
    }

    public function test_dominio_parecido_nao_e_tratado_como_video(): void
    {
        // "naotokyvideo.com" termina com o texto, mas não é subdomínio do domínio
        // de vídeo — a comparação é por sufixo com o ponto à frente.
        $this->assertFalse($this->invocar('dominioDeVideo', 'https://naotokyvideo.com/video/1'));
        $this->assertTrue($this->invocar('dominioDeVideo', 'https://www.tokyvideo.com/video/1'));
    }

    public function test_cinepoca_e_reconhecido_como_dominio_de_video(): void
    {
        // O `cinepoca` apareceu nos logs com o conteúdo do episódio, mas atrás de
        // Cloudflare. Sem entrar na lista, ele não subia na ordem e o orçamento
        // acabava antes de chegar nele.
        $this->assertTrue($this->invocar('dominioDeVideo', 'https://app.cinepoca.com.br/serie/1'));
        $this->assertTrue($this->invocar('dominioDeVideo', 'https://cinepoca.com.br/filme/1'));
    }

    public function test_url_sem_host_nao_e_dominio_de_video(): void
    {
        $this->assertFalse($this->invocar('dominioDeVideo', 'nao-e-url'));
    }
}
