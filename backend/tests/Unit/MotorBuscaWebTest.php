<?php

namespace Tests\Unit;

use App\Services\Torrents\MotorBuscaWeb;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O motor de busca é a porta de entrada do scraper: se ele não devolve as URLs
 * certas, nenhuma página é aberta e o fallback morre na praia. O que importa
 * testar aqui é a leitura do HTML do DuckDuckGo — o formato dos links de
 * resultado e o desembrulho do redirecionamento `uddg`.
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

    public function test_extrai_resultado_do_formato_html(): void
    {
        $html = <<<'HTML'
        <div class="result">
            <a class="result__a" href="//duckduckgo.com/l/?uddg=https%3A%2F%2Fsite.com%2Ffilme">Filme</a>
        </div>
        HTML;

        $urls = $this->invocar('extrairResultados', $html);

        $this->assertSame(['https://site.com/filme'], $urls);
    }

    public function test_extrai_resultado_do_formato_lite(): void
    {
        $html = '<a class="result-link" href="https://outro.com/serie">Serie</a>';

        $urls = $this->invocar('extrairResultados', $html);

        $this->assertSame(['https://outro.com/serie'], $urls);
    }

    public function test_desembrulha_o_redirecionamento_uddg(): void
    {
        $destino = $this->invocar(
            'resolverDestino',
            '//duckduckgo.com/l/?uddg=https%3A%2F%2Fsite.com%2Fassistir%3Fid%3D7&rut=abc'
        );

        $this->assertSame('https://site.com/assistir?id=7', $destino);
    }

    public function test_link_direto_passa_intacto(): void
    {
        $destino = $this->invocar('resolverDestino', 'https://site.com/filme');

        $this->assertSame('https://site.com/filme', $destino);
    }

    public function test_link_relativo_sem_protocolo_e_descartado(): void
    {
        $this->assertSame('', $this->invocar('resolverDestino', '/l/?uddg=qualquer'));
    }

    public function test_html_vazio_nao_devolve_resultado(): void
    {
        $this->assertSame([], $this->invocar('extrairResultados', ''));
    }

    public function test_html_sem_resultado_nao_devolve_nada(): void
    {
        $this->assertSame([], $this->invocar('extrairResultados', '<html><body>nada</body></html>'));
    }

    public function test_resultados_repetidos_sao_deduplicados(): void
    {
        $html = <<<'HTML'
        <a class="result__a" href="https://site.com/filme">A</a>
        <a class="result__a" href="https://site.com/filme">B</a>
        HTML;

        $this->assertSame(['https://site.com/filme'], $this->invocar('extrairResultados', $html));
    }

    public function test_motores_padrao_quando_a_config_esta_vazia(): void
    {
        config()->set('services.torrents.stream_direto_motores', []);

        // O SearXNG interno do compose vem primeiro porque o DuckDuckGo bloqueia
        // o IP dos containers com 202 e as instâncias públicas vêm e vão; o par
        // DDG HTML + Lite fica como reserva.
        $this->assertSame(
            [
                'http://searxng:8080/search',
                'https://html.duckduckgo.com/html/',
                'https://lite.duckduckgo.com/lite/',
            ],
            $this->invocar('motores')
        );
    }

    public function test_motores_configurados_sao_respeitados(): void
    {
        config()->set('services.torrents.stream_direto_motores', ['https://lite.duckduckgo.com/lite/']);

        $this->assertSame(['https://lite.duckduckgo.com/lite/'], $this->invocar('motores'));
    }

    public function test_motor_brave_sem_chave_e_descartado(): void
    {
        config()->set('services.torrents.stream_direto_motores', [
            'https://html.duckduckgo.com/html/',
            'https://api.search.brave.com/res/v1/web/search',
        ]);
        config()->set('services.torrents.stream_direto_brave_key', '');

        // Sem chave, o Brave responderia 401 e só gastaria uma volta do laço.
        $this->assertSame(
            ['https://html.duckduckgo.com/html/'],
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
        $this->assertSame('ddg', $this->invocar('tipoDoMotor', 'https://html.duckduckgo.com/html/'));
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

    public function test_prefixo_forca_o_tipo_do_motor(): void
    {
        // Uma instância SearXNG em domínio próprio não denuncia o tipo pelo host;
        // o prefixo `tipo:url` é o jeito de forçá-lo.
        $this->assertSame('searxng', $this->invocar('tipoDoMotor', 'searxng:https://busca.exemplo.com'));
        $this->assertSame('ddg', $this->invocar('tipoDoMotor', 'ddg:https://busca.exemplo.com'));
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

    public function test_status_202_devolve_lista_vazia(): void
    {
        Http::fake([
            'searx.be/*' => Http::response('', 202),
        ]);

        // O 202 é o bloqueio silencioso: sem tratá-lo, a página vazia passaria
        // como "zero resultados" e o orçamento seria gasto à toa.
        $this->assertSame([], $this->invocar('consultarMotor', 'https://searx.be/search', 'filme'));
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
         * campo que diz se o motor pediu captcha). A varredura de marcas de
         * bloqueio, feita para o HTML do DDG, dava falso positivo aqui e
         * descartava uma resposta cheia de resultados. O JSON tem estrutura
         * própria: quem valida é o `json_decode`, não a varredura de texto.
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

    public function test_detecta_pagina_de_bloqueio(): void
    {
        $this->assertTrue($this->invocar('pareceBloqueio', '<html>Unusual traffic detected</html>'));
        $this->assertTrue($this->invocar('pareceBloqueio', '<html>Too many requests</html>'));
        $this->assertTrue($this->invocar('pareceBloqueio', '<html>Please solve the captcha</html>'));
    }

    public function test_pagina_normal_nao_e_bloqueio(): void
    {
        $this->assertFalse($this->invocar('pareceBloqueio', '<html><a class="result__a" href="x">y</a></html>'));
        $this->assertFalse($this->invocar('pareceBloqueio', ''));
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

    public function test_extrair_resultados_descarta_dominios_de_catalogo(): void
    {
        $html = <<<'HTML'
        <a class="result__a" href="https://www.imdb.com/title/tt123">IMDb</a>
        <a class="result__a" href="https://www.justwatch.com/br/filme/x">JustWatch</a>
        <a class="result__a" href="https://agregador.com/assistir/filme">Agregador</a>
        HTML;

        // Só o agregador sobrevive: os catálogos são descartados antes de virarem
        // candidatos a página, poupando o orçamento curto do fallback.
        $this->assertSame(
            ['https://agregador.com/assistir/filme'],
            $this->invocar('extrairResultados', $html)
        );
    }

    public function test_extrair_resultados_so_com_catalogo_devolve_vazio(): void
    {
        $html = <<<'HTML'
        <a class="result__a" href="https://www.imdb.com/title/tt123">IMDb</a>
        <a class="result__a" href="https://www.youtube.com/watch?v=abc">YouTube</a>
        HTML;

        $this->assertSame([], $this->invocar('extrairResultados', $html));
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
}
