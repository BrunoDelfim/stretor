<?php

namespace Tests\Unit;

use App\Support\FiltroConteudoAdulto;
use Tests\TestCase;

/**
 * A barreira de conteúdo impróprio é a defesa contra o vazamento que motivou a
 * correção: o buscador web indexou `xvideos-cdn.com` para "Donas de Casa
 * Desesperadas". O que importa testar aqui é que o domínio adulto é barrado por
 * sufixo de host (sem confundir domínios parecidos) e que a palavra-chave pega
 * o que a lista de domínios ainda não conhece.
 */
class FiltroConteudoAdultoTest extends TestCase
{
    public function test_dominio_adulto_conhecido_e_bloqueado(): void
    {
        // O caso que motivou a correção: o CDN do xvideos, que não tem o nome
        // comercial no host e por isso passaria batido por uma lista ingênua.
        $this->assertTrue(FiltroConteudoAdulto::dominioBloqueado('https://xvideos-cdn.com/video.mp4'));
        $this->assertTrue(FiltroConteudoAdulto::dominioBloqueado('https://www.xvideos.com/video123'));
        $this->assertTrue(FiltroConteudoAdulto::dominioBloqueado('https://pornhub.com/view_video.php'));
        $this->assertTrue(FiltroConteudoAdulto::dominioBloqueado('https://xhamster.com/videos/x'));
        $this->assertTrue(FiltroConteudoAdulto::dominioBloqueado('https://www.redtube.com/12345'));
    }

    public function test_subdominio_do_dominio_adulto_e_bloqueado(): void
    {
        // O casamento é por sufixo de host com o ponto à frente: `cdn.xvideos.com`
        // precisa cair junto com `xvideos.com`.
        $this->assertTrue(FiltroConteudoAdulto::dominioBloqueado('https://cdn.xvideos.com/video.mp4'));
        $this->assertTrue(FiltroConteudoAdulto::dominioBloqueado('https://m.pornhub.com/view'));
    }

    public function test_dominio_parecido_nao_e_bloqueado(): void
    {
        // Um domínio que apenas termina com o mesmo texto não pode ser confundido
        // com a lista negra — o casamento é pelo host inteiro.
        $this->assertFalse(FiltroConteudoAdulto::dominioBloqueado('https://naoxvideos.com/filme'));
        $this->assertFalse(FiltroConteudoAdulto::dominioBloqueado('https://meupornhub.com/video'));
    }

    public function test_dominio_legitimo_nao_e_bloqueado(): void
    {
        $this->assertFalse(FiltroConteudoAdulto::dominioBloqueado('https://tokyvideo.com/br/video/x'));
        $this->assertFalse(FiltroConteudoAdulto::dominioBloqueado('https://agregador.com/assistir/filme'));
    }

    public function test_url_sem_host_nao_e_bloqueada_por_dominio(): void
    {
        // Quem decide se uma URL sem host serve é o extrator, não esta barreira.
        $this->assertFalse(FiltroConteudoAdulto::dominioBloqueado(''));
        $this->assertFalse(FiltroConteudoAdulto::dominioBloqueado('/caminho/relativo'));
    }

    public function test_palavra_chave_proibida_no_texto_e_bloqueada(): void
    {
        $this->assertTrue(FiltroConteudoAdulto::textoBloqueado('Assistir vídeo pornô grátis'));
        $this->assertTrue(FiltroConteudoAdulto::textoBloqueado('Filme porno completo'));
        $this->assertTrue(FiltroConteudoAdulto::textoBloqueado('Novinha gostosa dançando'));
        $this->assertTrue(FiltroConteudoAdulto::textoBloqueado('Conteúdo adulto +18'));
    }

    public function test_palavra_chave_com_acento_e_bloqueada(): void
    {
        // A normalização translitera os acentos, então "pornô" e "porno" caem na
        // mesma regra — a lista de palavras-chave só precisa de uma grafia.
        $this->assertTrue(FiltroConteudoAdulto::textoBloqueado('Pornô'));
        $this->assertTrue(FiltroConteudoAdulto::textoBloqueado('PORNÔ'));
        $this->assertTrue(FiltroConteudoAdulto::textoBloqueado('pornô'));
    }

    public function test_texto_legitimo_nao_e_bloqueado(): void
    {
        $this->assertFalse(FiltroConteudoAdulto::textoBloqueado('Donas de Casa Desesperadas 1x01'));
        $this->assertFalse(FiltroConteudoAdulto::textoBloqueado('Assistir online dublado'));
        $this->assertFalse(FiltroConteudoAdulto::textoBloqueado(''));
    }

    public function test_url_com_palavra_chave_no_caminho_e_bloqueada(): void
    {
        // O termo proibido pode estar no slug mesmo que o host seja desconhecido.
        $this->assertTrue(FiltroConteudoAdulto::urlBloqueada('https://agregador.com/porn-video-123'));
        $this->assertTrue(FiltroConteudoAdulto::urlBloqueada('https://site.com/assistir/hentai-ep1'));
    }

    public function test_url_legitima_nao_e_bloqueada(): void
    {
        $this->assertFalse(FiltroConteudoAdulto::urlBloqueada('https://tokyvideo.com/br/video/desperate-housewives-pt-01x01'));
        $this->assertFalse(FiltroConteudoAdulto::urlBloqueada('https://agregador.com/assistir/filme-dublado'));
    }

    public function test_url_bloqueada_pega_dominio_ou_palavra_chave(): void
    {
        // A checagem de porta de entrada combina as duas camadas.
        $this->assertTrue(FiltroConteudoAdulto::urlBloqueada('https://xvideos-cdn.com/video.mp4'));
        $this->assertTrue(FiltroConteudoAdulto::urlBloqueada('https://desconhecido.com/porn-video'));
        $this->assertFalse(FiltroConteudoAdulto::urlBloqueada('https://desconhecido.com/filme-dublado'));
    }
}
