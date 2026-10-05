<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O contrato do endpoint que recebe o passe do Cloudflare.
 *
 * É o caminho mais curto do sistema: a bancada cola o que a aba ganhou ao vencer o
 * widget e o backend passa a se apresentar com isso. O que este teste trava é o
 * que a colagem **recusa** (pedido vazio) e o que ela **avisa** — o token do
 * widget colado no campo do cookie é guardado, mas nunca em silêncio, porque o
 * sintoma (a tela de verificação de volta) só apareceria meia hora depois.
 */
class PasseCloudflareEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'services.torrents.passe_cloudflare_hosts' => ['superflixapi.quest'],
            'services.torrents.passe_cloudflare_clearance' => '',
            'services.torrents.passe_cloudflare_token' => '',
            'services.torrents.passe_cloudflare_agente' => '',
        ]);
    }

    public function test_pedido_sem_cookie_e_sem_token_e_recusado(): void
    {
        $this->postJson('/api/v1/passe-cloudflare', [])
            ->assertStatus(422)
            ->assertJsonPath('erro', 'Informe o cookie `cf_clearance` ou o token `cfv`.');
    }

    public function test_linha_de_cookie_e_guardada_sem_aviso(): void
    {
        $this->postJson('/api/v1/passe-cloudflare', [
            'clearance' => 'cf_clearance=ABC-1791188466-1.2.1.1-x.y; __cf_bm=Zm9vYmFy',
            'agente' => 'Agente-Da-Aba/1.0',
        ])
            ->assertOk()
            ->assertJsonPath('disponivel', true)
            ->assertJsonMissingPath('aviso');

        $this->getJson('/api/v1/passe-cloudflare')
            ->assertOk()
            ->assertJsonPath('disponivel', true)
            ->assertJsonPath('agente', 'Agente-Da-Aba/1.0');
    }

    public function test_token_solto_e_guardado_mas_o_aviso_aponta_o_campo_certo(): void
    {
        $this->postJson('/api/v1/passe-cloudflare', [
            'clearance' => '2jm9xlj1blROoU0Q'.str_repeat('k', 500),
        ])
            ->assertOk()
            ->assertJsonPath('disponivel', true)
            ->assertJsonPath('aviso', fn (string $aviso) => str_contains($aviso, 'cfv'));
    }

    public function test_esquecer_descarta_o_passe_guardado(): void
    {
        $this->postJson('/api/v1/passe-cloudflare', ['token' => 'TOKEN-123'])->assertOk();

        $this->deleteJson('/api/v1/passe-cloudflare')
            ->assertOk()
            ->assertJsonPath('disponivel', false);

        $this->getJson('/api/v1/passe-cloudflare')->assertJsonPath('disponivel', false);
    }

    /**
     * A conferência busca o endereço que lhe mandarem, então só aceita endereço
     * dos hosts com portão e em https. Aceitar qualquer URL faria dela um proxy
     * aberto para dentro da rede — e o que interessa conferir é justamente o host
     * que fecha a página no desafio.
     */
    public function test_conferencia_recusa_endereco_fora_dos_hosts_com_portao(): void
    {
        $this->postJson('/api/v1/passe-cloudflare/conferir', ['url' => 'https://verpobreflix.net/serie/693'])
            ->assertStatus(422)
            ->assertJsonPath('erro', 'A conferência testa só endereços https dos hosts com portão.');

        $this->postJson('/api/v1/passe-cloudflare/conferir', ['url' => 'http://superflixapi.quest/serie/693'])
            ->assertStatus(422);

        $this->postJson('/api/v1/passe-cloudflare/conferir', [])->assertStatus(422);
    }

    /**
     * O caso que a bancada precisa ver: o passe está em mãos e o host devolve a
     * tela de verificação assim mesmo.
     *
     * É aqui que a resposta ganha o carimbo do valor e o IP de saída — as duas
     * metades da comparação que sobram quando a resposta é a verificação, e que
     * separam "passe velho" de "par IP+agente trocado".
     */
    public function test_conferencia_relata_a_verificacao_com_carimbo_e_ip(): void
    {
        Http::fake([
            'superflixapi.quest/*' => Http::response($this->telaDeVerificacao(), 200, ['x-cloudflare-captcha' => 'required']),
            'cloudflare.com/*' => Http::response("fl=123\nip=203.0.113.7\nts=1791188466\n"),
        ]);

        $this->postJson('/api/v1/passe-cloudflare', [
            'clearance' => 'ABC-1791188466-1.2.1.1-x.y',
            'agente' => 'Agente-Da-Aba/1.0',
        ])->assertOk();

        $this->postJson('/api/v1/passe-cloudflare/conferir', ['url' => 'https://superflixapi.quest/serie/693/4/17'])
            ->assertOk()
            ->assertJsonPath('situacao', 'desafio')
            ->assertJsonPath('passe', true)
            ->assertJsonPath('carimbo', 1791188466)
            // Valor solto colado da célula do DevTools não tem nome — o passe aqui
            // está em mãos, e a lista de nomes vazia é a verdade sobre a colagem.
            ->assertJsonPath('cookies_do_passe', [])
            ->assertJsonPath('ip_de_saida', '203.0.113.7')
            ->assertJsonPath('familia_de_saida', 'ipv4')
            ->assertJsonPath('agente', 'Agente-Da-Aba/1.0')
            ->assertJsonPath('captcha', 'required')
            ->assertJsonPath('titulo', 'Verificação')
            ->assertJsonPath('veredito', fn (string $veredito): bool => str_contains($veredito, 'primeiro suspeito é o IP'));
    }

    /**
     * O outro desfecho, e o único que dispensa investigação: a página do episódio
     * voltou. Sem passe nenhum, é o esperado que a verificação volte — e o
     * veredito diz isso, em vez de mandar procurar defeito no par IP+agente.
     */
    public function test_conferencia_relata_a_pagina_liberada(): void
    {
        Http::fake([
            'superflixapi.quest/*' => Http::response(
                '<html><head><title>Donas de Casa Desesperadas 4x17</title></head><body><video src="master.m3u8"></video></body></html>',
                200
            ),
            'cloudflare.com/*' => Http::response("ip=203.0.113.7\n"),
        ]);

        $this->postJson('/api/v1/passe-cloudflare/conferir', ['url' => 'https://superflixapi.quest/serie/693/4/17'])
            ->assertOk()
            ->assertJsonPath('situacao', 'liberado')
            ->assertJsonPath('passe', false)
            ->assertJsonPath('carimbo', null)
            ->assertJsonPath('titulo', 'Donas de Casa Desesperadas 4x17')
            ->assertJsonPath('veredito', fn (string $veredito): bool => str_contains($veredito, 'abriu a página'));
    }

    /**
     * A família do IP de saída sai na resposta porque é metade da comparação: o
     * passe vale para o IP que o conquistou, e a família faz parte dele.
     *
     * O caso medido nesta máquina é justamente o divergente — a aba do usuário
     * sai por IPv6 (o host tem endereço global) e o container por IPv4 (a rede do
     * Docker não tem IPv6) —, e é essa divergência que o campo denuncia: sem ele,
     * o veredito sobra em "não funcionou", que não se conserta.
     */
    public function test_conferencia_denuncia_a_familia_do_ip_de_saida(): void
    {
        Http::fake([
            'superflixapi.quest/*' => Http::response(
                '<html><head><title>Verificação</title></head><body><input name="cf_embed_challenge"></body></html>',
                200
            ),
            'cloudflare.com/*' => Http::response("ip=2804:7f0:ba00:dfef:447e:a576:a669:d53b\n"),
        ]);

        $this->postJson('/api/v1/passe-cloudflare/conferir', ['url' => 'https://superflixapi.quest/serie/693/4/17'])
            ->assertOk()
            ->assertJsonPath('ip_de_saida', '2804:7f0:ba00:dfef:447e:a576:a669:d53b')
            ->assertJsonPath('familia_de_saida', 'ipv6');
    }

    /**
     * O caso que a colagem pelo console produz: o campo fica preenchido com os
     * cookies que o JavaScript enxerga — e o `cf_clearance` não está entre eles,
     * porque o host o marca como `HttpOnly`.
     *
     * Sem o carimbo e sem os nomes, esse caso é idêntico, na tela, a um passe
     * vencido: a resposta do host é a mesma, e a investigação começa no lugar
     * errado. Os nomes que vieram no passe são o que separa "o backend está sem
     * `cf_clearance`" de "o passe é velho".
     */
    public function test_conferencia_diz_quais_cookies_o_passe_traz(): void
    {
        Http::fake([
            'superflixapi.quest/*' => Http::response($this->telaDeVerificacao(), 200, ['x-cloudflare-captcha' => 'required']),
            'cloudflare.com/*' => Http::response("ip=203.0.113.7\n"),
        ]);

        $this->postJson('/api/v1/passe-cloudflare', [
            'clearance' => '__cf_bm=Zm9vYmFy; cf_chl_2=abc; sr_session=xyz',
        ])->assertOk();

        $this->postJson('/api/v1/passe-cloudflare/conferir', ['url' => 'https://superflixapi.quest/serie/693/4/17'])
            ->assertOk()
            ->assertJsonPath('situacao', 'desafio')
            ->assertJsonPath('passe', true)
            ->assertJsonPath('carimbo', null)
            ->assertJsonPath('cookies_do_passe', ['__cf_bm', 'cf_chl_2', 'sr_session'])
            ->assertJsonPath('veredito', fn (string $veredito): bool => str_contains($veredito, '`__cf_bm`')
                && str_contains($veredito, 'cf_clearance')
                && str_contains($veredito, 'HttpOnly'));
    }

    /**
     * A tela de verificação como ela chega do host: grande e **aberta pelo CSS do
     * widget**, com os campos do formulário do desafio bem depois do começo — é
     * essa a forma que o detector precisa reconhecer sem varrer o documento
     * inteiro por engano.
     */
    private function telaDeVerificacao(): string
    {
        return '<html><head><title>Verificação</title></head><body>'
            .str_repeat('<style>.cf-turnstile{}</style>', 300)
            .'<form method="POST"><input name="cf_embed_challenge" value="1">'
            .'<input name="cf_embed_hash" value="2"><input name="cf-turnstile-response"></form>'
            .'</body></html>';
    }
}
