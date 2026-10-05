<?php

namespace Tests\Unit;

use App\Services\Torrents\ClienteHttp;
use App\Services\Torrents\OrcamentoBusca;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O socorro pelo FlareSolverr tem custo real de cerca de 20 s por chamada.
 *
 * Medido no container: uma chamada que pedia `maxTimeout` de 8 s levou **19,2 s**
 * para responder, porque o Chromium ainda sobe depois da espera pelo desafio. Sem
 * contabilizar esse custo, o socorro de uma página gastava o orçamento da busca
 * inteira — no episódio que motivou a correção, duas páginas consumiram os 45 s e
 * a busca terminou sem abrir a que tinha a fonte.
 *
 * Estes testes travam as duas regras que ficaram no lugar: o socorro só é chamado
 * quando o prazo cobre o custo dele, e uma falha encerra as tentativas de socorro
 * naquela busca.
 */
class ClienteHttpSocorroTest extends TestCase
{
    private function configurarProxy(): void
    {
        config([
            'services.prowlarr.flaresolverr_url' => 'http://flaresolverr:8191',
            'services.torrents.proxy_nativo' => true,
        ]);
    }

    /** Quantos pedidos de socorro foram realmente entregues ao FlareSolverr. */
    private function socorrosPedidos(): int
    {
        return collect(Http::recorded())
            ->filter(fn (array $par): bool => str_contains($par[0]->url(), 'flaresolverr'))
            ->count();
    }

    public function test_socorro_nao_e_chamado_quando_o_prazo_nao_cobre_o_custo_dele(): void
    {
        $this->configurarProxy();

        Http::fake(['*' => Http::response('desafio do Cloudflare', 403)]);

        $orcamento = new OrcamentoBusca();

        // 20 s restantes contra 8 s de `maxTimeout` + 20 s de custo real: não cabe.
        $orcamento->abrir(20);

        (new ClienteHttp($orcamento))->get('https://site.test/pagina', [], null, 8);

        $this->assertSame(0, $this->socorrosPedidos(), 'O socorro não cabe no prazo: não deve ser chamado.');
    }

    public function test_socorro_e_chamado_quando_o_prazo_cobre_o_custo_dele(): void
    {
        $this->configurarProxy();

        Http::fake([
            'https://site.test/*' => Http::response('desafio do Cloudflare', 403),
            'http://flaresolverr:8191/v1' => Http::response([
                'status' => 'ok',
                'solution' => ['response' => '<html><body>página liberada</body></html>'],
            ]),
        ]);

        $orcamento = new OrcamentoBusca();
        $orcamento->abrir(45);

        $resposta = (new ClienteHttp($orcamento))->get('https://site.test/pagina', [], null, 8);

        $this->assertSame(1, $this->socorrosPedidos());
        $this->assertStringContainsString('página liberada', (string) $resposta->body());
    }

    public function test_falha_do_socorro_encerra_as_tentativas_da_busca(): void
    {
        $this->configurarProxy();

        Http::fake([
            'https://site.test/*' => Http::response('desafio do Cloudflare', 403),
            'http://flaresolverr:8191/v1' => Http::response(['status' => 'error'], 500),
        ]);

        /*
         * Sem orçamento aberto o prazo não barra nada de propósito: quem impede a
         * segunda página de pagar o mesmo preço é a marca deixada pela falha.
         */
        $cliente = new ClienteHttp(new OrcamentoBusca());
        $cliente->get('https://site.test/pagina-1', [], null, 8);
        $cliente->get('https://site.test/pagina-2', [], null, 8);

        $this->assertSame(1, $this->socorrosPedidos(), 'Uma falha já encerra o socorro desta busca.');
    }
}
