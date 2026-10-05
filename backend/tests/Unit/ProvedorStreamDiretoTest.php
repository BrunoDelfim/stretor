<?php

namespace Tests\Unit;

use App\Services\Torrents\ProvedorStreamDireto;
use Tests\TestCase;

/**
 * O provedor de stream direto é o socorro do conteúdo raro. O que importa
 * testar aqui, sem tocar na rede, é o rastro de diagnóstico: o log precisa
 * dizer para quais domínios o orçamento foi, e não só quantas páginas foram
 * abertas.
 */
class ProvedorStreamDiretoTest extends TestCase
{
    /**
     * Invoca um método privado do provedor sem abrir conexão nenhuma.
     */
    private function invocar(string $metodo, mixed ...$argumentos): mixed
    {
        $servico = app(ProvedorStreamDireto::class);
        $reflexao = new \ReflectionMethod($servico, $metodo);
        $reflexao->setAccessible(true);

        return $reflexao->invoke($servico, ...$argumentos);
    }

    public function test_dominios_consultados_sao_extraidos_das_urls(): void
    {
        $dominios = $this->invocar('dominiosDe', [
            'https://agregador.com/assistir/filme',
            'https://www.outro.com/serie',
        ]);

        $this->assertSame(['agregador.com', 'www.outro.com'], $dominios);
    }

    public function test_dominios_repetidos_aparecem_uma_vez(): void
    {
        $dominios = $this->invocar('dominiosDe', [
            'https://agregador.com/assistir/filme',
            'https://agregador.com/assistir/serie',
        ]);

        $this->assertSame(['agregador.com'], $dominios);
    }

    public function test_url_sem_host_nao_entra_no_log(): void
    {
        $dominios = $this->invocar('dominiosDe', ['', '/caminho/relativo']);

        $this->assertSame([], $dominios);
    }

    public function test_lista_vazia_devolve_lista_vazia(): void
    {
        $this->assertSame([], $this->invocar('dominiosDe', []));
    }

    /**
     * O caso que motivou a correção: o tokyvideo separa o conteúdo por país no
     * caminho (`/br/`) e marca o idioma no slug (`-pt-`), sem escrever
     * "dublado" em lugar nenhum. Antes, esse endereço caía em "original" e o
     * overlay marcava como idioma original um vídeo que toca dublado.
     */
    public function test_endereco_com_segmento_de_pais_e_codigo_pt_e_dublado(): void
    {
        $idioma = $this->invocar(
            'idiomaDaPagina',
            'https://tokyvideo.com/br/video/desperate-housewives-pt-01x01-nicsfilm'
        );

        $this->assertSame('dublado', $idioma);
    }

    public function test_endereco_com_dublado_explicito_e_dublado(): void
    {
        $this->assertSame('dublado', $this->invocar('idiomaDaPagina', 'https://site.com/filme-dublado'));
    }

    /**
     * "legendado" precisa vencer o código `pt`: num endereço "legendado pt br"
     * o `pt` é da legenda, não do áudio. Se a checagem de indícios viesse
     * primeiro, ele seria classificado como dublado.
     */
    public function test_legendado_vence_o_codigo_pt(): void
    {
        $idioma = $this->invocar('idiomaDaPagina', 'https://site.com/br/filme-legendado-pt-br');

        $this->assertSame('legendado', $idioma);
    }

    public function test_endereco_sem_pista_devolve_vazio(): void
    {
        $this->assertSame('', $this->invocar('idiomaDaPagina', 'https://site.com/watch/12345'));
    }

    /**
     * O segmento de país é procurado com as barras à volta: `/br/` é o Brasil,
     * mas `/bruno/` não é. Sem essa borda, qualquer slug com "br" viraria
     * dublado.
     */
    public function test_segmento_de_pais_nao_casa_pedaco_de_palavra(): void
    {
        $this->assertFalse($this->invocar('enderecoIndicaPortugues', 'https://site.com/bruno/filme'));
    }

    public function test_codigo_pt_solto_no_slug_indica_portugues(): void
    {
        $this->assertTrue($this->invocar('enderecoIndicaPortugues', 'https://site.com/video/filme-pt-01x01'));
    }

    /**
     * `str_contains('pt')` casaria com "script" e "concept". A borda de palavra
     * que o `IndiciosPtBr` aplica evita esse falso positivo.
     */
    public function test_palavra_com_pt_no_meio_nao_indica_portugues(): void
    {
        $this->assertFalse($this->invocar('enderecoIndicaPortugues', 'https://site.com/script/concept'));
    }

    public function test_titulo_da_pagina_e_extraido_do_html(): void
    {
        $html = '<html><head><title>Donas de Casa Desesperadas 1x01 Dublado</title></head><body></body></html>';

        $this->assertSame(
            'Donas de Casa Desesperadas 1x01 Dublado',
            $this->invocar('tituloDaPagina', $html)
        );
    }

    public function test_pagina_sem_title_devolve_titulo_vazio(): void
    {
        // Sem `<title>`, a checagem de conteúdo não bloqueia — a decisão fica com
        // a URL e com os links de vídeo, validados em seguida.
        $this->assertSame('', $this->invocar('tituloDaPagina', '<html><body>sem título</body></html>'));
    }

    public function test_titulo_com_entidades_e_limpo(): void
    {
        $html = '<title>Filme & S&#233;rie &#8211; Dublado</title>';

        $this->assertSame('Filme & Série – Dublado', $this->invocar('tituloDaPagina', $html));
    }

    public function test_filtro_adulto_ativo_por_padrao(): void
    {
        config()->set('services.torrents.stream_direto_filtro_adulto', true);

        $this->assertTrue($this->invocar('filtroAdultoAtivo'));
    }

    public function test_filtro_adulto_pode_ser_desligado(): void
    {
        config()->set('services.torrents.stream_direto_filtro_adulto', false);

        $this->assertFalse($this->invocar('filtroAdultoAtivo'));
    }

    public function test_alvo_de_fontes_encerra_ao_atingir_o_numero(): void
    {
        // O alvo conta fontes, não páginas: com duas fontes na mão, o laço para.
        $this->assertFalse($this->invocar('alvoAtingido', [], 2));
        $this->assertFalse($this->invocar('alvoAtingido', [['id' => 'a']], 2));
        $this->assertTrue($this->invocar('alvoAtingido', [['id' => 'a'], ['id' => 'b']], 2));
        $this->assertTrue($this->invocar('alvoAtingido', [['id' => 'a'], ['id' => 'b'], ['id' => 'c']], 2));
    }

    public function test_alvo_zero_desliga_o_corte(): void
    {
        // Zero ou negativo devolve o comportamento antigo: só o teto de páginas e
        // o orçamento encerram a varredura.
        $fontes = [['id' => 'a'], ['id' => 'b'], ['id' => 'c']];

        $this->assertFalse($this->invocar('alvoAtingido', $fontes, 0));
        $this->assertFalse($this->invocar('alvoAtingido', $fontes, -1));
    }

    /**
     * O `assistaonline.tv` respondia 200 com a página "Deployment Paused" do
     * Vercel — o status não denuncia, mas o corpo sim.
     */
    public function test_pagina_de_site_morto_e_reconhecida(): void
    {
        $html = '<html><body><h1>Deployment Paused</h1><p>This deployment has been paused.</p></body></html>';

        $this->assertTrue($this->invocar('pareceSiteMorto', $html));
    }

    public function test_pagina_de_dominio_estacionado_e_reconhecida(): void
    {
        $this->assertTrue($this->invocar('pareceSiteMorto', '<html><body>This domain is for sale</body></html>'));
        $this->assertTrue($this->invocar('pareceSiteMorto', '<html><body>Account suspended</body></html>'));
    }

    public function test_pagina_normal_nao_e_site_morto(): void
    {
        $html = '<html><head><title>American Horror Story 1x01</title></head><body><video src="https://cdn.exemplo.com/ahs.mp4"></video></body></html>';

        $this->assertFalse($this->invocar('pareceSiteMorto', $html));
    }

    /**
     * A comparação é por frase inteira: "paused" sozinho apareceria num player
     * pausado e não pode derrubar a página.
     */
    public function test_palavra_solta_nao_marca_site_morto(): void
    {
        $this->assertFalse($this->invocar('pareceSiteMorto', '<html><body>O player foi paused pelo usuário</body></html>'));
    }

    public function test_corpo_vazio_nao_e_site_morto(): void
    {
        $this->assertFalse($this->invocar('pareceSiteMorto', ''));
    }

    /**
     * O caso que motivou a descida: a busca aberta devolve a ficha da série
     * (`/series/american-horror-story`), que lista os episódios mas não tem
     * player. O player mora na página do episódio, um clique abaixo.
     */
    public function test_link_do_episodio_e_achado_na_listagem_da_serie(): void
    {
        $html = <<<'HTML'
        <html><body>
            <a href="/series/american-horror-story/temporada-1/episodio-1">Episódio 1</a>
            <a href="/series/american-horror-story/temporada-1/episodio-2">Episódio 2</a>
        </body></html>
        HTML;

        $link = $this->invocar(
            'linkDoEpisodio',
            $html,
            'https://www.verpobreflix.net/series/american-horror-story',
            1,
            1
        );

        $this->assertSame('https://www.verpobreflix.net/series/american-horror-story/temporada-1/episodio-1', $link);
    }

    /**
     * A outra grafia que os agregadores usam: temporada e episódio num único
     * segmento (`temporada-1-episodio-1`).
     */
    public function test_link_do_episodio_aceita_grafia_de_segmento_unico(): void
    {
        $html = '<a href="/series/ahs/temporada-2-episodio-3">T2E3</a>';

        $link = $this->invocar('linkDoEpisodio', $html, 'https://site.com/series/ahs', 2, 3);

        $this->assertSame('https://site.com/series/ahs/temporada-2-episodio-3', $link);
    }

    /**
     * Sem temporada ou episódio pedidos não há o que descer — a página de filme
     * não tem numeração.
     */
    public function test_sem_episodio_pedido_nao_ha_descida(): void
    {
        $html = '<a href="/series/ahs/temporada-1/episodio-1">Episódio 1</a>';

        $this->assertNull($this->invocar('linkDoEpisodio', $html, 'https://site.com/series/ahs', null, null));
        $this->assertNull($this->invocar('linkDoEpisodio', $html, 'https://site.com/series/ahs', 1, null));
    }

    /**
     * A trava contra a recursão infinita: se a página aberta já é a do episódio
     * e mesmo assim não tem player, descer de novo voltaria ao mesmo lugar.
     */
    public function test_pagina_que_ja_e_do_episodio_nao_desce_de_novo(): void
    {
        $html = '<a href="/series/ahs/temporada-1/episodio-1">Episódio 1</a>';

        $link = $this->invocar(
            'linkDoEpisodio',
            $html,
            'https://site.com/series/ahs/temporada-1/episodio-1',
            1,
            1
        );

        $this->assertNull($link);
    }

    public function test_listagem_sem_o_episodio_pedido_devolve_nulo(): void
    {
        $html = '<a href="/series/ahs/temporada-1/episodio-2">Episódio 2</a>';

        $this->assertNull($this->invocar('linkDoEpisodio', $html, 'https://site.com/series/ahs', 1, 1));
    }

    public function test_corpo_vazio_nao_tem_link_de_episodio(): void
    {
        $this->assertNull($this->invocar('linkDoEpisodio', '', 'https://site.com/series/ahs', 1, 1));
    }

    /**
     * O link pode vir absoluto, relativo à raiz ou relativo ao caminho atual.
     */
    public function test_endereco_absoluto_e_preservado(): void
    {
        $link = $this->invocar('resolverEndereco', 'https://outro.com/ep/1', 'https://site.com/series/ahs');

        $this->assertSame('https://outro.com/ep/1', $link);
    }

    public function test_endereco_relativo_a_raiz_recebe_o_host(): void
    {
        $link = $this->invocar('resolverEndereco', '/series/ahs/temporada-1/episodio-1', 'https://site.com/series/ahs');

        $this->assertSame('https://site.com/series/ahs/temporada-1/episodio-1', $link);
    }

    public function test_endereco_relativo_ao_caminho_atual_recebe_o_diretorio(): void
    {
        // `/series/ahs` é o "arquivo" atual; o diretório dele é `/series`. Um
        // link relativo resolve contra esse diretório, como manda a semântica
        // de URL — não contra o caminho inteiro.
        $link = $this->invocar('resolverEndereco', 'temporada-1/episodio-1', 'https://site.com/series/ahs');

        $this->assertSame('https://site.com/series/temporada-1/episodio-1', $link);
    }

    public function test_endereco_relativo_usa_o_diretorio_com_barra_final(): void
    {
        // Com barra final, o caminho já é um diretório e o relativo entra dentro
        // dele sem cortar o último segmento.
        $link = $this->invocar('resolverEndereco', 'temporada-1/episodio-1', 'https://site.com/series/ahs/');

        $this->assertSame('https://site.com/series/ahs/temporada-1/episodio-1', $link);
    }

    public function test_endereco_vazio_nao_resolve(): void
    {
        $this->assertNull($this->invocar('resolverEndereco', '   ', 'https://site.com/series/ahs'));
    }

    public function test_endereco_com_pagina_sem_host_nao_resolve(): void
    {
        $this->assertNull($this->invocar('resolverEndereco', 'temporada-1/episodio-1', '/caminho/solto'));
    }

    /**
     * O censo dos agregadores existe desde a construção, antes de qualquer busca.
     *
     * É o que permite à cobertura dizer "o superflix não foi perguntado" em vez de
     * não dizer nada: a lista já nasce completa — todos em `nao_consultado`, com os
     * contadores em zero —, e a varredura só sobrescreve a situação de quem ela de
     * fato consulta. A chave da busca direta é fixada aqui para o teste não
     * depender do `.env` de quem ligou ou desligou o atalho.
     */
    public function test_censo_dos_agregadores_existe_antes_de_qualquer_busca(): void
    {
        config()->set('services.torrents.stream_direto_busca_direta', true);

        $censo = app(ProvedorStreamDireto::class)->censoDosAgregadores();

        $this->assertSame(
            ['superflixapi.quest', 'verpobreflix.net'],
            array_column($censo, 'agregador'),
            'O censo precisa listar todos os agregadores declarados, na ordem da declaração.'
        );
        $this->assertSame(
            ['nao_consultado', 'nao_consultado'],
            array_column($censo, 'situacao'),
            'Sem varredura, nenhum agregador pode aparecer como consultado.'
        );
        $this->assertSame(0, array_sum(array_column($censo, 'consultas')));
    }
}
