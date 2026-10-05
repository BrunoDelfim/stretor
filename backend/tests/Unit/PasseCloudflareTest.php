<?php

namespace Tests\Unit;

use App\Services\Torrents\ClienteHttp;
use App\Services\Torrents\OrcamentoBusca;
use App\Services\Torrents\PasseCloudflare;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O passe do Cloudflare vale para o par **IP + agente**, e era exatamente aí que ele
 * se perdia.
 *
 * Duas armadilhas medidas contra o `superflixapi.quest`: o cookie colado chega em
 * formatos diferentes — só o valor, a linha `Cookie:` inteira, o bloco do "copiar como
 * cURL" — e era prefixado às cegas, virando `cf_clearance=cf_clearance=...`; e o
 * `withUserAgent()` do Laravel atropelava o agente que vinha nos cabeçalhos, então o
 * host recebia um `cf_clearance` conquistado por uma aba, apresentado pelo agente do
 * backend. Nos dois casos a resposta foi a mesma tela de verificação, sem uma linha
 * de log explicando o motivo — e é esse silêncio que os testes abaixo fecham.
 */
class PasseCloudflareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        // O passe do `.env` de quem está desenvolvendo não pode contaminar o teste:
        // aqui ele sempre nasce do cache, que é o caminho da bancada.
        config([
            'services.torrents.passe_cloudflare_hosts' => ['superflixapi.quest'],
            'services.torrents.passe_cloudflare_clearance' => '',
            'services.torrents.passe_cloudflare_token' => '',
            'services.torrents.passe_cloudflare_agente' => '',
            'services.torrents.user_agent' => 'Agente-Padrao/1.0',
        ]);
    }

    private function passe(): PasseCloudflare
    {
        return new PasseCloudflare;
    }

    private function cliente(): ClienteHttp
    {
        return new ClienteHttp(new OrcamentoBusca);
    }

    public function test_valor_puro_recebe_o_nome_do_cookie(): void
    {
        $this->passe()->registrar('ABC-1791188466-1.2.1.1-x.y', '');

        $this->assertSame(
            'cf_clearance=ABC-1791188466-1.2.1.1-x.y',
            $this->passe()->cabecalhosDeAcesso()['Cookie']
        );
    }

    public function test_cabecalho_pronto_com_outros_cookies_e_preservado(): void
    {
        $this->passe()->registrar('cf_clearance=ABC-1791188466-1.2.1.1-x.y; __cf_bm=Zm9vYmFy', '');

        $this->assertSame(
            'cf_clearance=ABC-1791188466-1.2.1.1-x.y; __cf_bm=Zm9vYmFy',
            $this->passe()->cabecalhosDeAcesso()['Cookie']
        );
    }

    /**
     * O formato mais fácil de colar e o mais fácil de estragar: o bloco que o
     * DevTools entrega no "copiar como cURL" traz a linha `cookie:` embutida num
     * comando, com aspas e outros cabeçalhos em volta.
     */
    public function test_bloco_do_copiar_como_curl_e_recortado(): void
    {
        $this->passe()->registrar(
            "curl 'https://superflixapi.quest/serie/693' -H 'cookie: cf_clearance=ABC-1791188466-1.2.1.1-x.y; __cf_bm=Zm9vYmFy' -H 'accept: text/html'",
            ''
        );

        $this->assertSame(
            'cf_clearance=ABC-1791188466-1.2.1.1-x.y; __cf_bm=Zm9vYmFy',
            $this->passe()->cabecalhosDeAcesso()['Cookie']
        );
    }

    public function test_agente_do_passe_vence_o_agente_padrao(): void
    {
        Http::fake(['*' => Http::response('<html>ok</html>', 200)]);

        $this->passe()->registrar('MINHA-CLEARANCE-1789-1.2.1.1-abc.def', '', 'Agente-Da-Aba/1.0');

        $this->cliente()->get('https://superflixapi.quest/serie/693/4/17', [], null, 8);

        Http::assertSent(function ($requisicao) {
            $cabecalhos = $requisicao->headers();

            return ($cabecalhos['Cookie'][0] ?? '') === 'cf_clearance=MINHA-CLEARANCE-1789-1.2.1.1-abc.def'
                && ($cabecalhos['User-Agent'][0] ?? '') === 'Agente-Da-Aba/1.0';
        });
    }

    public function test_sem_passe_nada_e_injetado_e_o_agente_padrao_permanece(): void
    {
        Http::fake(['*' => Http::response('<html>ok</html>', 200)]);

        $this->cliente()->get('https://superflixapi.quest/serie/693/4/17', [], null, 8);

        Http::assertSent(function ($requisicao) {
            $cabecalhos = $requisicao->headers();

            return ! isset($cabecalhos['Cookie'])
                && ($cabecalhos['User-Agent'][0] ?? '') === 'Agente-Padrao/1.0';
        });
    }

    /**
     * O outro prêmio do widget: quando o site devolve o token em vez do cookie, ele
     * viaja na query da própria página — e o host recusa a visita sem ele.
     */
    public function test_token_viaja_na_query_junto_dos_demais_parametros(): void
    {
        Http::fake(['*' => Http::response('<html>ok</html>', 200)]);

        $this->passe()->registrar('', 'TOKEN-123', 'Agente-Da-Aba/1.0');

        $this->cliente()->get('https://superflixapi.quest/serie/693', ['temporada' => 4], null, 8);

        Http::assertSent(fn ($requisicao) => str_contains($requisicao->url(), 'cfv=TOKEN-123')
            && str_contains($requisicao->url(), 'temporada=4'));
    }

    public function test_host_fora_da_lista_nao_exige_passe(): void
    {
        $this->assertTrue($this->passe()->exige('https://superflixapi.quest/serie/693/4/17'));
        $this->assertTrue($this->passe()->exige('https://www.superflixapi.quest/serie/693/4/17'));
        $this->assertFalse($this->passe()->exige('https://verpobreflix.net/pesquisar?s=teste'));
    }

    /**
     * O recorte antigo começava em `cf_clearance=` e deixava para trás tudo o que
     * vinha antes — e é justamente aí que fica o `__cf_bm`, o cookie do próprio
     * Cloudflare que costuma acompanhar a liberação. O descarte não fazia barulho:
     * o que sobrava ainda *parecia* um cabeçalho válido.
     */
    public function test_cookie_que_vem_antes_do_cf_clearance_nao_e_descartado(): void
    {
        $this->passe()->registrar('__cf_bm=Zm9vYmFy; cf_clearance=ABC-1791188466-1.2.1.1-x.y', '');

        $this->assertSame(
            '__cf_bm=Zm9vYmFy; cf_clearance=ABC-1791188466-1.2.1.1-x.y',
            $this->passe()->cabecalhosDeAcesso()['Cookie']
        );
    }

    /**
     * Quem cola a linha do cabeçalho, e não o valor, entregava o rótulo junto: o
     * pedido saía com `Cookie: Cookie: ...` e o host devolvia a tela de verificação.
     */
    public function test_linha_com_rotulo_cookie_perde_o_rotulo(): void
    {
        $this->passe()->registrar('Cookie: sr_session=abc; cfv=TOKEN-123', '');

        $this->assertSame(
            'sr_session=abc; cfv=TOKEN-123',
            $this->passe()->cabecalhosDeAcesso()['Cookie']
        );
    }

    /**
     * O `-b` do cURL é a forma mais enxuta do bloco, e não traz o rótulo do
     * cabeçalho: o que vem depois dele já é o valor.
     */
    public function test_cookie_passado_por_b_do_curl_e_recortado(): void
    {
        $this->passe()->registrar(
            "curl 'https://superflixapi.quest/serie/693/4/17' -b 'cf_clearance=ABC-1791188466-1.2.1.1-x.y; cfv=TOKEN-123'",
            ''
        );

        $this->assertSame(
            'cf_clearance=ABC-1791188466-1.2.1.1-x.y; cfv=TOKEN-123',
            $this->passe()->cabecalhosDeAcesso()['Cookie']
        );
    }

    public function test_cabecalho_cookie_do_curl_com_aspas_duplas_e_recortado(): void
    {
        $this->passe()->registrar(
            'curl "https://superflixapi.quest/serie/693" --header "Cookie: cf_clearance=ABC-1791188466-1.2.1.1-x.y"',
            ''
        );

        $this->assertSame(
            'cf_clearance=ABC-1791188466-1.2.1.1-x.y',
            $this->passe()->cabecalhosDeAcesso()['Cookie']
        );
    }

    /**
     * O aviso da colagem: o token do widget não é cookie, e o campo dele é o do
     * `cfv`. Colado no campo do cookie — como aconteceu na bancada —, ele era
     * prefixado como `cf_clearance` e o host devolvia a mesma tela de verificação,
     * igual a não ter passe nenhum.
     */
    public function test_token_solto_e_reconhecido_para_aviso(): void
    {
        $passe = $this->passe();

        $this->assertTrue($passe->pareceTokenSolto('2jm9xlj1blROoU0Q'.str_repeat('k', 500)));
        $this->assertTrue($passe->pareceTokenSolto('TOKEN-123'));
        $this->assertFalse($passe->pareceTokenSolto('ABC-1791188466-1.2.1.1-x.y'));
        $this->assertFalse($passe->pareceTokenSolto('cf_clearance=ABC-1791188466-1.2.1.1-x.y; __cf_bm=Zm9vYmFy'));
        $this->assertFalse($passe->pareceTokenSolto(''));
    }

    /**
     * O carimbo da colagem diz quando o passe **nasceu**, não quando ele vence.
     *
     * A diferença foi medida, não suposta: um `cf_clearance` conquistado de
     * verdade pelo FlareSolverr (num site de teste com o mesmo Cloudflare) veio
     * com o carimbo do próprio segundo da conquista, enquanto o vencimento do
     * cookie, lido do navegador pelo CDP, era exatamente um ano depois. É esse
     * número que a conferência usa para separar um passe recém-conquistado de um
     * esquecido no campo desde ontem — os dois chegam ao host com a mesma cara, e
     * o host recusa os dois do mesmo jeito.
     */
    public function test_carimbo_do_valor_e_lido_em_qualquer_forma_de_colagem(): void
    {
        $passe = $this->passe();

        $this->assertSame(1791188466, $passe->carimboDoValor('ABC-1791188466-1.2.1.1-x.y'));
        $this->assertSame(1791188466, $passe->carimboDoValor('cf_clearance=ABC-1791188466-1.2.1.1-x.y; __cf_bm=Zm9vYmFy'));
        $this->assertSame(1791188466, $passe->carimboDoValor('Cookie: cf_clearance=ABC-1791188466-1.2.1.1-x.y'));
        $this->assertSame(1791188466, $passe->carimboDoValor(
            "curl 'https://superflixapi.quest/serie/693' -H 'cookie: cf_clearance=ABC-1791188466-1.2.1.1-x.y' -H 'accept: text/html'"
        ));
    }

    /**
     * Sem `cf_clearance` não há carimbo para ler: o token do widget, a linha que
     * só traz o `cfv` e o campo vazio devolvem `null` — a conferência prefere não
     * dizer idade nenhuma a inventar uma.
     */
    public function test_carimbo_e_nulo_quando_o_valor_nao_e_um_cf_clearance(): void
    {
        $passe = $this->passe();

        $this->assertNull($passe->carimboDoValor('TOKEN-123'));
        $this->assertNull($passe->carimboDoValor('cfv=TOKEN-123; sr_session=abc'));
        $this->assertNull($passe->carimboDoValor('2jm9xlj1blROoU0Q'.str_repeat('k', 500)));
        $this->assertNull($passe->carimboDoValor(''));
    }

    /**
     * Os nomes dos cookies da colagem, que é o que responde "o que está em mãos?"
     * quando a conferência recebe a verificação de volta.
     *
     * O caso que importa é o da lista sem o `cf_clearance` — a colagem feita pelo
     * console, onde o `HttpOnly` o esconde. O valor solto, colado da célula do
     * DevTools, não tem nome nenhum: dizer `cf_clearance` ali seria inventar.
     */
    public function test_nomes_de_cookies_sao_lidos_de_qualquer_forma_de_colagem(): void
    {
        $passe = $this->passe();

        $this->assertSame(['cf_clearance', '__cf_bm'], $passe->nomesDeCookies('cf_clearance=A-1791188466-1.2.1.1-x.y; __cf_bm=Zm9vYmFy'));
        $this->assertSame(['__cf_bm'], $passe->nomesDeCookies('cookie: __cf_bm=Zm9vYmFy'));
        $this->assertSame(
            ['__cf_bm', 'cf_clearance'],
            $passe->nomesDeCookies("curl 'https://superflixapi.quest/serie/693' -H 'cookie: __cf_bm=Zm9vYmFy; cf_clearance=A-1791188466-1.2.1.1-x.y'")
        );

        // Nome repetido conta uma vez: a lista fala de cookies, não de pedaços.
        $this->assertSame(['__cf_bm'], $passe->nomesDeCookies('__cf_bm=a; __cf_bm=b'));

        // Valor solto e texto que nem cookie é não têm nome para mostrar.
        $this->assertSame([], $passe->nomesDeCookies('ABC-1791188466-1.2.1.1-x.y'));
        $this->assertSame([], $passe->nomesDeCookies(''));
    }
}
