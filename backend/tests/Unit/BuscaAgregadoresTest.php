<?php

namespace Tests\Unit;

use App\Services\Torrents\BuscaAgregadores;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A busca direta nos agregadores é o atalho que dispensa o motor web: em vez de
 * perguntar ao SearXNG onde o episódio mora, pergunta à busca interna do próprio
 * agregador. O que importa testar aqui, sem tocar na rede, é a leitura da página
 * de resultado — escolher só os links de conteúdo do agregador, resolver o
 * relativo à raiz e ignorar o que não é do site ou não é conteúdo.
 */
class BuscaAgregadoresTest extends TestCase
{
    /**
     * Invoca um método privado do serviço sem abrir conexão nenhuma.
     */
    private function invocar(string $metodo, mixed ...$argumentos): mixed
    {
        $servico = app(BuscaAgregadores::class);
        $reflexao = new \ReflectionMethod($servico, $metodo);
        $reflexao->setAccessible(true);

        return $reflexao->invoke($servico, ...$argumentos);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // A busca direta fala com a rede de verdade. Nos testes, uma URL que
        // escape do fingimento precisa estourar em vez de sair pela internet e
        // derrubar o isolamento.
        Http::preventStrayRequests();
    }

    public function test_buscar_le_o_link_de_conteudo_da_busca_interna(): void
    {
        Http::fake([
            'www.verpobreflix.net/*' => Http::response('<a href="/series/breaking-bad">Breaking Bad</a>'),
        ]);

        $paginas = app(BuscaAgregadores::class)->buscar(['Breaking Bad']);

        // O link relativo à raiz resolve contra o host da página de busca.
        $this->assertSame(
            ['https://www.verpobreflix.net/series/breaking-bad'],
            $paginas
        );
    }

    public function test_link_de_outro_dominio_e_descartado(): void
    {
        // O agregador não é o único host na página (menu, propaganda). Só o que
        // é do próprio site entra.
        $links = $this->invocar(
            'links',
            '<a href="https://outro.com/series/x">x</a>',
            'https://www.verpobreflix.net/search?q=x',
            'verpobreflix.net',
            '#^/series/#'
        );

        $this->assertSame([], $links);
    }

    public function test_link_sem_o_prefixo_de_conteudo_e_descartado(): void
    {
        $links = $this->invocar(
            'links',
            '<a href="/contato">Contato</a><a href="/series/bom">Bom</a>',
            'https://www.verpobreflix.net/search?q=x',
            'verpobreflix.net',
            '#^/series/#'
        );

        $this->assertSame(['https://www.verpobreflix.net/series/bom'], $links);
    }

    public function test_absolutizar_resolve_relativo_a_raiz(): void
    {
        $this->assertSame(
            'https://www.verpobreflix.net/series/x',
            $this->invocar('absolutizar', '/series/x', 'https://www.verpobreflix.net/search?q=x')
        );
    }

    public function test_absolutizar_mantem_endereco_absoluto(): void
    {
        $this->assertSame(
            'https://www.verpobreflix.net/series/1',
            $this->invocar('absolutizar', 'https://www.verpobreflix.net/series/1', 'https://www.verpobreflix.net/search?q=x')
        );
    }

    public function test_absolutizar_ignora_relativo_ao_caminho(): void
    {
        // "series/1" é relativo ao caminho: some da página de busca e evitá-lo
        // mantém a regra simples.
        $this->assertNull($this->invocar('absolutizar', 'series/1', 'https://www.verpobreflix.net/search?q=x'));
    }

    public function test_slug_sem_relacao_com_o_titulo_nao_entra(): void
    {
        /*
         * A página de resultado costuma vir salpicada de "veja também". O que não
         * carrega as palavras-chave do título no endereço não entra na varredura:
         * deixar passar custaria uma requisição e uma vaga do teto de páginas para
         * descobrir tarde que a página não era sobre o título.
         */
        Http::fake([
            'www.verpobreflix.net/*' => Http::response(
                '<a href="/series/american-horror-story">A</a><a href="/series/outra-serie">B</a>'
            ),
        ]);

        $paginas = app(BuscaAgregadores::class)->buscar(['American Horror Story']);

        $this->assertSame(['https://www.verpobreflix.net/series/american-horror-story'], $paginas);
    }

    public function test_busca_direta_desligada_nao_consulta(): void
    {
        config()->set('services.torrents.stream_direto_busca_direta', false);
        Http::fake();

        $this->assertSame([], app(BuscaAgregadores::class)->buscar(['Breaking Bad']));
        Http::assertNothingSent();
    }

    public function test_titulos_vazios_nao_consomem_requisicao(): void
    {
        config()->set('services.torrents.stream_direto_busca_direta', true);
        Http::fake();

        $this->assertSame([], app(BuscaAgregadores::class)->buscar(['', '   ']));
        Http::assertNothingSent();
    }
}
