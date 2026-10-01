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
}
