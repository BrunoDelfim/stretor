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
}
