<?php

namespace Tests\Unit;

use App\Services\Torrents\ProvedorStreamDireto;
use Tests\TestCase;

/**
 * A relevância é a segunda peneira da aceitação: depois de a página provar que
 * tem player, ela precisa provar que é *sobre* o título. Sem isso, uma página
 * de fandom que embute o trailer ou um agregador que devolve um episódio
 * qualquer passam — foi o que aconteceu com "American Horror Story", que abriu
 * um episódio aleatório vindo do `dramatotal.fandom.com`.
 *
 * O que importa testar aqui é a contagem de palavras-chave: o que conta, o que
 * não conta (ligação, numeração, acento) e o limiar configurável.
 */
class RelevanciaTituloTest extends TestCase
{
    /**
     * Invoca um método privado do provedor (que usa a trait) sem tocar na rede.
     */
    private function invocar(string $metodo, mixed ...$argumentos): mixed
    {
        $servico = app(ProvedorStreamDireto::class);
        $reflexao = new \ReflectionMethod($servico, $metodo);
        $reflexao->setAccessible(true);

        return $reflexao->invoke($servico, ...$argumentos);
    }

    /**
     * O caso que motivou a correção: a página do fandom sobre a série embutia
     * vídeos que nada tinham a ver com o episódio pedido.
     */
    public function test_pagina_de_fandom_sem_relacao_e_recusada(): void
    {
        // O endereço fala de "Gwen", não de "American Horror Story"; o título da
        // página também não traz as palavras do título buscado.
        $this->assertFalse($this->invocar(
            'paginaRelevante',
            'https://dramatotal.fandom.com/pt-br/wiki/Gwen',
            'Gwen | Dramatotal Wiki | Fandom',
            'American Horror Story'
        ));
    }

    /**
     * O outro caso do log: o Tokyvideo devolveu um `historia-4` que nada tem a
     * ver com o título pedido.
     */
    public function test_pagina_de_agregador_com_outro_conteudo_e_recusada(): void
    {
        $this->assertFalse($this->invocar(
            'paginaRelevante',
            'https://www.tokyvideo.com/br/video/historia-4',
            'História 4',
            'American Horror Story'
        ));
    }

    public function test_pagina_com_as_palavras_do_titulo_e_aceita(): void
    {
        // Endereço com "american" e "horror": duas palavras-chave, limiar batido.
        $this->assertTrue($this->invocar(
            'paginaRelevante',
            'https://www.tokyvideo.com/br/video/american-horror-story-ep1',
            'American Horror Story Episódio 1',
            'American Horror Story'
        ));
    }

    public function test_palavras_no_title_da_pagina_tambem_contam(): void
    {
        // O endereço é opaco, mas o `<title>` traz as palavras do título.
        $this->assertTrue($this->invocar(
            'paginaRelevante',
            'https://site.com/watch/12345',
            'Assistir American Horror Story 1x01 Online',
            'American Horror Story'
        ));
    }

    /**
     * Uma só palavra não basta para um título composto: "american" sozinho
     * casaria com `americanas.com.br` e `americansportshop.com.br`, que
     * apareceram no log.
     */
    public function test_uma_palavra_so_nao_basta_para_titulo_composto(): void
    {
        $this->assertFalse($this->invocar(
            'paginaRelevante',
            'https://www.americanas.com.br/produto/1',
            'Americanas — tudo você encontra aqui',
            'American Horror Story'
        ));
    }

    public function test_duas_palavras_bastam(): void
    {
        $this->assertTrue($this->invocar(
            'paginaRelevante',
            'https://site.com/american-story',
            '',
            'American Horror Story'
        ));
    }

    /**
     * O acento não pode derrubar a página: o endereço de um site raramente
     * preserva o acento do título.
     */
    public function test_acento_nao_impede_o_casamento(): void
    {
        $this->assertTrue($this->invocar(
            'paginaRelevante',
            'https://site.com/historia-sem-fim',
            '',
            'História Sem Fim'
        ));
    }

    /**
     * As palavras de ligação não contam: "Donas de Casa Desesperadas" tem duas
     * palavras que importam ("donas", "casa", "desesperadas" — três, na
     * verdade) e uma que não ("de").
     */
    public function test_palavras_de_ligacao_nao_contam(): void
    {
        // Só "de" e "the" no endereço não provam nada.
        $this->assertFalse($this->invocar(
            'paginaRelevante',
            'https://site.com/de-the',
            '',
            'Donas de Casa Desesperadas'
        ));
    }

    public function test_titulo_de_uma_palavra_exige_apenas_uma(): void
    {
        // Um título de uma palavra só não tem como exigir duas: o limiar é
        // limitado ao que o título oferece.
        $this->assertTrue($this->invocar(
            'paginaRelevante',
            'https://site.com/dexter-temporada-1',
            '',
            'Dexter'
        ));
    }

    public function test_titulo_de_uma_palavra_sem_casamento_e_recusado(): void
    {
        $this->assertFalse($this->invocar(
            'paginaRelevante',
            'https://site.com/outra-coisa',
            'Outra coisa',
            'Dexter'
        ));
    }

    /**
     * A numeração do episódio não é palavra-chave: ela diz *qual* episódio, não
     * *qual* série, e exigi-la deixaria de fora a página que cobre a série
     * inteira.
     */
    public function test_numeracao_do_episodio_nao_e_palavra_chave(): void
    {
        $palavras = $this->invocar('palavrasChave', 'American Horror Story S01E01');

        $this->assertContains('american', $palavras);
        $this->assertContains('horror', $palavras);
        $this->assertContains('story', $palavras);
        $this->assertNotContains('s01e01', $palavras);
        $this->assertNotContains('01', $palavras);
    }

    public function test_limiar_zero_desliga_a_checagem(): void
    {
        config()->set('services.torrents.stream_direto_min_palavras_chave', 0);

        // Com a checagem desligada, qualquer página passa — o comportamento
        // antigo, só com a prova de mídia.
        $this->assertTrue($this->invocar(
            'paginaRelevante',
            'https://site.com/qualquer-coisa',
            '',
            'American Horror Story'
        ));
    }

    public function test_limiar_configuravel_e_respeitado(): void
    {
        config()->set('services.torrents.stream_direto_min_palavras_chave', 3);

        // Com o limiar em 3, duas palavras não bastam.
        $this->assertFalse($this->invocar(
            'paginaRelevante',
            'https://site.com/american-story',
            '',
            'American Horror Story'
        ));

        // Três palavras bastam.
        $this->assertTrue($this->invocar(
            'paginaRelevante',
            'https://site.com/american-horror-story',
            '',
            'American Horror Story'
        ));
    }

    public function test_titulo_vazio_nao_bloqueia(): void
    {
        // Sem título não há como provar relevância; bloquear tudo seria pior que
        // não bloquear nada.
        $this->assertTrue($this->invocar('paginaRelevante', 'https://site.com/x', '', ''));
    }

    public function test_palavras_chave_ignoram_palavras_de_uma_letra(): void
    {
        $palavras = $this->invocar('palavrasChave', 'A B C Dexter');

        $this->assertSame(['dexter'], $palavras);
    }

    /**
     * A validação do vídeo é a última peneira: a página pode ser sobre o título
     * e ainda assim embutir um vídeo de outro programa. O caso do log foi o
     * Tokyvideo, que serviu um `/video/historia-4` numa página cujo título era o
     * da série pedida.
     */
    public function test_video_com_slug_generico_e_recusado(): void
    {
        $this->assertFalse($this->invocar(
            'videoRelevante',
            'https://www.tokyvideo.com/br/video/historia-4',
            'American Horror Story'
        ));
    }

    public function test_video_com_slug_numerico_e_recusado(): void
    {
        // Um slug puramente numérico não carrega nenhuma palavra do título.
        $this->assertFalse($this->invocar(
            'videoRelevante',
            'https://cdn.exemplo.com/video/12345.mp4',
            'American Horror Story'
        ));
    }

    public function test_video_com_palavra_do_titulo_e_aceito(): void
    {
        $this->assertTrue($this->invocar(
            'videoRelevante',
            'https://cdn.exemplo.com/american-horror-story-s01e01.mp4',
            'American Horror Story'
        ));
    }

    public function test_video_com_uma_palavra_do_titulo_basta(): void
    {
        // Diferente da página, aqui **uma** palavra já aprova: o endereço do
        // vídeo é curto e o slug costuma trazer só o essencial. O que se recusa é
        // a ausência total de pista, não a pista parcial.
        $this->assertTrue($this->invocar(
            'videoRelevante',
            'https://cdn.exemplo.com/horror-s01e01.mp4',
            'American Horror Story'
        ));
    }

    /**
     * O host de embed conhecido é isento: o id do YouTube não carrega o título, e
     * exigi-lo derrubaria a fonte legítima.
     */
    public function test_video_de_host_de_embed_conhecido_e_isento(): void
    {
        $this->assertTrue($this->invocar(
            'videoRelevante',
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'American Horror Story'
        ));

        $this->assertTrue($this->invocar(
            'videoRelevante',
            'https://vimeo.com/123456789',
            'American Horror Story'
        ));
    }

    /**
     * O `plenoflu.com` **não** é isento. Ele chegou a entrar na lista pela
     * medição do `verpobreflix.net`, mas o host não serve vídeo nenhum — só uma
     * página de player que responde "Acesso proibido" a qualquer cliente
     * automatizado. O endereço (`/tvshow/1413/1/1`) não carrega o título, então
     * a checagem o recusa, como deve.
     */
    public function test_video_do_player_plenoflu_nao_e_isento(): void
    {
        $this->assertFalse($this->invocar(
            'videoRelevante',
            'https://plenoflu.com/tvshow/1413/1/1',
            'American Horror Story'
        ));
    }

    public function test_video_com_limiar_zero_passa(): void
    {
        config()->set('services.torrents.stream_direto_min_palavras_chave', 0);

        $this->assertTrue($this->invocar(
            'videoRelevante',
            'https://cdn.exemplo.com/video/12345.mp4',
            'American Horror Story'
        ));
    }

    public function test_video_com_titulo_vazio_nao_bloqueia(): void
    {
        $this->assertTrue($this->invocar(
            'videoRelevante',
            'https://cdn.exemplo.com/video/12345.mp4',
            ''
        ));
    }
}
