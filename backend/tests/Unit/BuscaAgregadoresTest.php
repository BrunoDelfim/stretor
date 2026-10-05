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

        $this->assertSame(['https://www.verpobreflix.net/series/bom'], array_keys($links));
    }

    /**
     * O texto do cartão acompanha o endereço, e não é enfeite: é ele que permite
     * julgar a relevância de um endereço sem uma palavra do título. A busca do
     * superflix devolve `/serie/693`, e sem o texto ao lado a candidata certa
     * seria descartada por "sem relação com o título".
     *
     * O cartão do fixture imita o do site: a capa **antes** do endereço, com o nome
     * no `alt` — que é onde o nome de fato está no superflix, a 1.847 caracteres do
     * botão "copiar link", e que o `strip_tags` puro apagaria junto com a tag.
     */
    public function test_o_texto_do_cartao_acompanha_o_endereco(): void
    {
        $links = $this->invocar(
            'links',
            '<img alt="Donas de Casa Desesperadas" src="capa.jpg">'
                .'<a href="/serie/693"><img src="capa.jpg"></a>',
            'https://superflixapi.quest/pesquisar?s=Donas+de+Casa+Desesperadas',
            'superflixapi.quest',
            '#^/(serie|filme)/\d+(/|$)#',
            ['data-copy']
        );

        $this->assertArrayHasKey('https://superflixapi.quest/serie/693', $links);
        $this->assertStringContainsString(
            'Donas de Casa Desesperadas',
            $links['https://superflixapi.quest/serie/693']
        );
    }

    /**
     * O endereço bom do cartão do superflix não está no `href`: está num
     * `data-copy`. No `href` mora um slug que responde 404 — e é por isso que o
     * agregador declara o atributo uma segunda vez.
     */
    public function test_atributo_declarado_entrega_o_endereco_numerico(): void
    {
        $links = $this->invocar(
            'links',
            '<a href="https://superflixapi.quest/serie/donas-de-casa-desesperadas">Abrir</a>'
                .'<button data-copy="https://superflixapi.quest/serie/693" data-msg="Link copiado!">',
            'https://superflixapi.quest/pesquisar?s=Donas+de+Casa+Desesperadas',
            'superflixapi.quest',
            '#^/(serie|filme)/\d+(/|$)#',
            ['data-copy']
        );

        // O slug do `href` não tem id e não passa no padrão de conteúdo; o
        // endereço numérico do `data-copy` entra.
        $this->assertSame(['https://superflixapi.quest/serie/693'], array_keys($links));
    }

    /**
     * O superflix separa a ficha do episódio e a busca só devolve a ficha —
     * `/serie/693`, sem player nenhum. Como o id dele é o do TMDB, a descida até o
     * episódio é montar a URL, e sem ela o provedor abriria a ficha e descartaria
     * a candidata certa por "sem prova de mídia".
     */
    public function test_desce_da_ficha_do_superflix_para_a_pagina_do_episodio(): void
    {
        $agregador = [
            'busca' => 'https://superflixapi.quest/pesquisar?s={termo}',
            'conteudo' => '#^/(serie|filme)/\d+(/|$)#',
            'episodio' => 'https://superflixapi.quest/serie/{id}/{temporada}/{episodio}',
        ];

        $this->assertSame(
            'https://superflixapi.quest/serie/693/4/17',
            $this->invocar(
                'descerParaEpisodio',
                'https://superflixapi.quest/serie/693',
                $agregador,
                4,
                17
            )
        );

        /*
         * Filme não tem temporada nem episódio, e a ficha dele já é a página do
         * conteúdo: a descida devolve o endereço intacto em vez de inventar uma
         * URL que não existe no host.
         */
        $this->assertSame(
            'https://superflixapi.quest/filme/958186',
            $this->invocar(
                'descerParaEpisodio',
                'https://superflixapi.quest/filme/958186',
                $agregador,
                null,
                null
            )
        );
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

    /**
     * Lê uma linha do censo pelo domínio, como o relatório de cobertura faz.
     *
     * @return array<string, mixed>
     */
    private function linhaDoCenso(BuscaAgregadores $servico, string $dominio): array
    {
        foreach ($servico->censo() as $linha) {
            if ($linha['agregador'] === $dominio) {
                return $linha;
            }
        }

        return [];
    }

    /**
     * O censo diz quem entregou e quem nem foi perguntado.
     *
     * É a pergunta que a linha única do stream direto na cobertura não responde: o
     * fallback voltou vazio, mas o superflix chegou a ser consultado? Consumir só o
     * primeiro resultado do gerador replica o comportamento do provedor, que para a
     * varredura assim que junta fontes — e é por isso que o verpobreflix precisa
     * aparecer como `nao_consultado`, e não como uma linha vazia sem explicação.
     */
    public function test_censo_registra_quem_entregou_e_quem_nao_foi_perguntado(): void
    {
        Http::fake([
            'superflixapi.quest/*' => Http::response(
                '<img alt="Donas de Casa Desesperadas" src="capa.jpg">'
                .'<button data-copy="https://superflixapi.quest/serie/693" data-msg="Link copiado!">'
            ),
        ]);

        $servico = app(BuscaAgregadores::class);
        $primeiro = null;

        foreach ($servico->candidatas(['Donas de Casa Desesperadas']) as $dominio => $paginas) {
            $primeiro = [$dominio, $paginas];
            break;
        }

        $this->assertSame('superflixapi.quest', $primeiro[0]);

        $superflix = $this->linhaDoCenso($servico, 'superflixapi.quest');

        $this->assertSame('com_pagina', $superflix['situacao']);
        $this->assertSame(1, $superflix['consultas']);
        $this->assertSame(1, $superflix['paginas']);
        $this->assertSame(['https://superflixapi.quest/serie/693'], $superflix['encontradas']);
        $this->assertSame('Donas de Casa Desesperadas', $superflix['titulo']);

        $verpobreflix = $this->linhaDoCenso($servico, 'verpobreflix.net');

        $this->assertSame('nao_consultado', $verpobreflix['situacao']);
        $this->assertSame(0, $verpobreflix['consultas']);
        $this->assertSame(0, $verpobreflix['ms']);
    }

    /**
     * Agregador que não responde fica `sem_resposta`, e não `sem_resultado`.
     *
     * A distinção é de operação: um 500 do site é bloqueio ou queda, e não a prova
     * de que o episódio não está no acervo dele. Sem ela, o relatório culparia o
     * acervo por um problema de rede.
     */
    public function test_censo_marca_sem_resposta_quando_o_agregador_falha(): void
    {
        Http::fake([
            'superflixapi.quest/*' => Http::response('', 500),
            'verpobreflix.net/*' => Http::response('', 500),
        ]);

        $servico = app(BuscaAgregadores::class);

        $this->assertSame([], $servico->buscar(['Donas de Casa Desesperadas']));

        $this->assertSame(
            'sem_resposta',
            $this->linhaDoCenso($servico, 'superflixapi.quest')['situacao']
        );
        $this->assertSame(
            'sem_resposta',
            $this->linhaDoCenso($servico, 'verpobreflix.net')['situacao']
        );
    }

    /**
     * Agregador que responde sem o título — o "veja também" da busca — é
     * `sem_resultado`: foi perguntado e não tinha o conteúdo.
     */
    public function test_censo_marca_sem_resultado_quando_a_busca_nao_traz_o_titulo(): void
    {
        Http::fake([
            'superflixapi.quest/*' => Http::response(
                '<a href="https://superflixapi.quest/serie/999">Outra Série Qualquer</a>'
            ),
            'verpobreflix.net/*' => Http::response(
                '<a href="/series/outra-serie-qualquer">Outra Série Qualquer</a>'
            ),
        ]);

        $servico = app(BuscaAgregadores::class);

        $this->assertSame([], $servico->buscar(['Donas de Casa Desesperadas']));

        $this->assertSame(
            'sem_resultado',
            $this->linhaDoCenso($servico, 'superflixapi.quest')['situacao']
        );
        $this->assertSame(
            'sem_resultado',
            $this->linhaDoCenso($servico, 'verpobreflix.net')['situacao']
        );
    }

    /**
     * Com a busca direta desligada, o relatório diz `desligada`.
     *
     * É a diferença entre "o agregador não tem acervo" e "a chave está off": sem
     * esta situação, cada agregador viraria um silêncio sem causa e a configuração
     * passaria a se esconder atrás de um `nao_consultado` igual para todos.
     */
    public function test_censo_marca_desligada_quando_a_busca_direta_esta_off(): void
    {
        config()->set('services.torrents.stream_direto_busca_direta', false);
        Http::fake();

        $servico = app(BuscaAgregadores::class);

        $this->assertSame([], $servico->buscar(['Donas de Casa Desesperadas']));

        $this->assertNotSame([], $servico->censo());
        $this->assertSame(
            'desligada',
            $this->linhaDoCenso($servico, 'superflixapi.quest')['situacao']
        );
        $this->assertSame(
            'desligada',
            $this->linhaDoCenso($servico, 'verpobreflix.net')['situacao']
        );
        Http::assertNothingSent();
    }
}
