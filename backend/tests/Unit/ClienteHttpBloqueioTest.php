<?php

namespace Tests\Unit;

use App\Services\Torrents\ClienteHttp;
use App\Services\Torrents\OrcamentoBusca;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O reconhecimento de bloqueio é o que separa "página sem mídia" de "página
 * atrás de paywall". Sem ele, o scraper lia o 402 do agregador como resultado
 * legítimo, gastava o teto inteiro e o descartava por "sem prova de mídia" — o
 * orçamento acabava antes de os termos seguintes serem consultados.
 */
class ClienteHttpBloqueioTest extends TestCase
{
    private function cliente(): ClienteHttp
    {
        return new ClienteHttp(new OrcamentoBusca());
    }

    private function resposta(int $status, string $corpo = ''): Response
    {
        return new Response(
            new \GuzzleHttp\Psr7\Response($status, [], $corpo)
        );
    }

    public function test_402_e_reconhecido_como_bloqueio(): void
    {
        // O caso que motivou a correção: o `assistaonline.tv` respondeu 402 e o
        // scraper seguiu como se a página fosse normal.
        $this->assertTrue($this->cliente()->bloqueada($this->resposta(402)));
    }

    public function test_403_e_reconhecido_como_bloqueio(): void
    {
        // O clássico do Cloudflare, que já era tratado.
        $this->assertTrue($this->cliente()->bloqueada($this->resposta(403)));
    }

    public function test_429_e_503_sao_reconhecidos_como_bloqueio(): void
    {
        $this->assertTrue($this->cliente()->bloqueada($this->resposta(429)));
        $this->assertTrue($this->cliente()->bloqueada($this->resposta(503)));
    }

    public function test_200_com_desafio_no_corpo_e_bloqueio(): void
    {
        // Algumas versões do desafio respondem 200 com a página "Just a moment...".
        $this->assertTrue($this->cliente()->bloqueada(
            $this->resposta(200, '<html><title>Just a moment...</title></html>')
        ));
    }

    public function test_200_sem_desafio_nao_e_bloqueio(): void
    {
        $this->assertFalse($this->cliente()->bloqueada(
            $this->resposta(200, '<html><body>Página normal com player</body></html>')
        ));
    }

    public function test_404_nao_e_bloqueio(): void
    {
        // Um 404 é "não existe", não "bloqueado": o provedor trata os dois de
        // formas diferentes no log.
        $this->assertFalse($this->cliente()->bloqueada($this->resposta(404)));
    }

    /**
     * Sem o FlareSolverr configurado não há navegador para renderizar: o método
     * devolve `null` e o provedor fica com o HTML estático que já tinha.
     */
    public function test_renderizacao_sem_proxy_devolve_nulo(): void
    {
        config(['services.prowlarr.flaresolverr_url' => '']);

        $this->assertNull($this->cliente()->getRenderizado('https://pobreflix.bike/serie'));
    }

    /**
     * Com o proxy configurado, a página é entregue ao FlareSolverr e o HTML
     * renderizado volta como uma `Response` normal — é o que permite ler o player
     * que só existe depois do JavaScript rodar.
     */
    public function test_renderizacao_com_proxy_devolve_o_html_do_flaresolverr(): void
    {
        config([
            'services.prowlarr.flaresolverr_url' => 'http://flaresolverr:8191',
            'services.torrents.proxy_nativo' => true,
        ]);

        Http::fake([
            'http://flaresolverr:8191/v1' => Http::response([
                'status' => 'ok',
                'solution' => [
                    'response' => '<html><body><video src="https://cdn.site/ep1.mp4"></video></body></html>',
                ],
            ]),
        ]);

        $resposta = $this->cliente()->getRenderizado('https://pobreflix.bike/serie');

        $this->assertNotNull($resposta);
        $this->assertStringContainsString('ep1.mp4', (string) $resposta->body());
    }

    /**
     * Quando o FlareSolverr não resolve, o método devolve `null` em vez de uma
     * resposta vazia — assim o provedor sabe que a renderização falhou e não
     * confunde com uma página legitimamente sem mídia.
     */
    public function test_renderizacao_com_proxy_que_falha_devolve_nulo(): void
    {
        config([
            'services.prowlarr.flaresolverr_url' => 'http://flaresolverr:8191',
            'services.torrents.proxy_nativo' => true,
        ]);

        Http::fake([
            'http://flaresolverr:8191/v1' => Http::response([
                'status' => 'error',
                'message' => 'Challenge not solved',
            ]),
        ]);

        $this->assertNull($this->cliente()->getRenderizado('https://pobreflix.bike/serie'));
    }
}
