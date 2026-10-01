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
}
