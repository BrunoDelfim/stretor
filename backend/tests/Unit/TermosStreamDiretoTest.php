<?php

namespace Tests\Unit;

use App\Services\Torrents\TermosStreamDireto;
use Tests\TestCase;

/**
 * A trait monta a query do scraper, e a query é o que decide se a página certa
 * aparece no resultado. Um termo mal montado (sem numeração, ou sem a intenção
 * de streaming) traz a página errada — ou nenhuma. Estes testes fixam o formato
 * que o provedor espera receber.
 */
class TermosStreamDiretoTest extends TestCase
{
    /**
     * Invoca um método protegido da trait sem expor a implementação.
     */
    private function termos(string $metodo, mixed ...$argumentos): mixed
    {
        $anonimo = new class {
            use TermosStreamDireto;
        };

        $reflexao = new \ReflectionMethod($anonimo, $metodo);
        $reflexao->setAccessible(true);

        return $reflexao->invoke($anonimo, ...$argumentos);
    }

    public function test_filme_monta_titulo_com_intencao(): void
    {
        $termos = $this->termos('termosDeStreaming', 'Cidade de Deus');

        $this->assertContains('Cidade de Deus assistir online dublado', $termos);
        $this->assertContains('Cidade de Deus assistir online legendado', $termos);
        $this->assertContains('Cidade de Deus assistir online', $termos);
    }

    public function test_intencoes_padrao_priorizam_agregadores_de_video(): void
    {
        config()->set('services.torrents.stream_direto_termos', []);

        $intencoes = $this->termos('intencoesDeStreaming');

        // O motor de busca ordena por relevância e, para um título conhecido,
        // empurra catálogos (JustWatch, IMDb) para o topo. Os termos que puxam
        // agregadores de vídeo precisam vir antes dos genéricos.
        $this->assertSame('assistir online dublado', $intencoes[0]);
        $this->assertContains('filme completo dublado', $intencoes);
        $this->assertContains('serie completa dublada', $intencoes);
    }

    public function test_filme_usa_os_termos_de_agregador(): void
    {
        config()->set('services.torrents.stream_direto_termos', []);

        $termos = $this->termos('termosDeStreaming', 'Cidade de Deus');

        $this->assertContains('Cidade de Deus filme completo dublado', $termos);
        $this->assertContains('Cidade de Deus serie completa dublada', $termos);
    }

    public function test_episodio_carrega_a_numeracao_antes_da_intencao(): void
    {
        // O teto alto isola a montagem da query do corte: aqui interessa o
        // formato dos termos, não quantos sobrevivem.
        config()->set('services.torrents.stream_direto_max_termos', 0);

        $termos = $this->termos('termosDeStreaming', 'Donas de Casa Desesperadas', 1, 1);

        $this->assertContains('Donas de Casa Desesperadas 1x01 assistir online dublado', $termos);
        $this->assertContains('Donas de Casa Desesperadas S01E01 assistir online dublado', $termos);
        $this->assertContains('Donas de Casa Desesperadas Temporada 1 Episódio 1 assistir online dublado', $termos);
    }

    public function test_episodio_tambem_pergunta_sem_a_intencao(): void
    {
        config()->set('services.torrents.stream_direto_max_termos', 0);

        $termos = $this->termos('termosDeStreaming', 'Donas de Casa Desesperadas', 1, 1);

        // A numeração sozinha alcança os sites que não repetem "assistir online".
        $this->assertContains('Donas de Casa Desesperadas 1x01', $termos);
        $this->assertContains('Donas de Casa Desesperadas S01E01', $termos);
    }

    public function test_o_termo_mais_preciso_vem_primeiro(): void
    {
        $termos = $this->termos('termosDeStreaming', 'Donas de Casa Desesperadas', 1, 1);

        // O provedor para assim que junta páginas suficientes, então o termo
        // mais específico precisa ser o primeiro da lista.
        $this->assertSame('Donas de Casa Desesperadas 1x01 assistir online dublado', $termos[0]);
    }

    public function test_numeracao_de_dois_digitos_e_preservada(): void
    {
        $termos = $this->termos('termosDeStreaming', 'Serie Qualquer', 2, 10);

        $this->assertContains('Serie Qualquer 2x10 assistir online dublado', $termos);
        $this->assertContains('Serie Qualquer S02E10 assistir online dublado', $termos);
    }

    public function test_titulo_vazio_nao_gera_termo(): void
    {
        $this->assertSame([], $this->termos('termosDeStreaming', '   '));
    }

    public function test_termos_nao_se_repetem(): void
    {
        $termos = $this->termos('termosDeStreaming', 'Cidade de Deus');

        $this->assertSame(array_values(array_unique($termos)), $termos);
    }

    public function test_termos_configurados_substituem_o_padrao(): void
    {
        config()->set('services.torrents.stream_direto_termos', ['assistir online gratis']);

        $termos = $this->termos('termosDeStreaming', 'Cidade de Deus');

        $this->assertContains('Cidade de Deus assistir online gratis', $termos);
        $this->assertNotContains('Cidade de Deus assistir online dublado', $termos);
    }

    public function test_titulo_original_entra_como_rede_de_seguranca(): void
    {
        config()->set('services.torrents.stream_direto_max_termos', 0);

        $termos = $this->termos(
            'termosDeStreaming',
            'Donas de Casa Desesperadas',
            1,
            1,
            ['Desperate Housewives']
        );

        // O título original entra sem a intenção de idioma: a ideia é achar
        // qualquer página de vídeo e deixar a normalização filtrar.
        $this->assertContains('Desperate Housewives 1x01', $termos);
        $this->assertContains('Desperate Housewives S01E01', $termos);
        $this->assertNotContains('Desperate Housewives 1x01 assistir online dublado', $termos);
    }

    public function test_titulo_original_vem_depois_do_titulo_principal(): void
    {
        config()->set('services.torrents.stream_direto_max_termos', 0);

        $termos = $this->termos(
            'termosDeStreaming',
            'Donas de Casa Desesperadas',
            1,
            1,
            ['Desperate Housewives']
        );

        $posicaoPrincipal = array_search('Donas de Casa Desesperadas 1x01 assistir online dublado', $termos, true);
        $posicaoOriginal = array_search('Desperate Housewives 1x01', $termos, true);

        $this->assertNotFalse($posicaoPrincipal);
        $this->assertNotFalse($posicaoOriginal);
        $this->assertLessThan($posicaoOriginal, $posicaoPrincipal);
    }

    public function test_filme_usa_o_titulo_original_solto(): void
    {
        $termos = $this->termos('termosDeStreaming', 'Cidade de Deus', null, null, ['City of God']);

        $this->assertContains('City of God', $termos);
    }

    public function test_titulos_alternativos_vazios_sao_ignorados(): void
    {
        $termos = $this->termos('termosDeStreaming', 'Cidade de Deus', null, null, ['', '   ']);

        $this->assertNotContains('', $termos);
        $this->assertSame(array_values(array_unique($termos)), $termos);
    }

    public function test_lista_de_termos_e_cortada_no_teto(): void
    {
        config()->set('services.torrents.stream_direto_max_termos', 3);

        $termos = $this->termos('termosDeStreaming', 'Donas de Casa Desesperadas', 1, 1);

        // Sem o corte, um episódio gera dezenas de termos — e cada um é uma
        // requisição que o buscador pode punir como rajada.
        $this->assertCount(3, $termos);
    }

    public function test_o_corte_preserva_os_termos_mais_precisos(): void
    {
        config()->set('services.torrents.stream_direto_max_termos', 2);

        $termos = $this->termos('termosDeStreaming', 'Donas de Casa Desesperadas', 1, 1);

        // A lista vem do mais preciso ao mais amplo, então o corte descarta a
        // cauda genérica e mantém o que casa com a página certa.
        $this->assertSame('Donas de Casa Desesperadas 1x01 assistir online dublado', $termos[0]);
        $this->assertSame('Donas de Casa Desesperadas 1x01 assistir online legendado', $termos[1]);
    }

    public function test_teto_zero_desliga_o_corte(): void
    {
        config()->set('services.torrents.stream_direto_max_termos', 0);

        $termos = $this->termos('termosDeStreaming', 'Donas de Casa Desesperadas', 1, 1);

        $this->assertGreaterThan(3, count($termos));
    }

    public function test_lista_menor_que_o_teto_passa_intacta(): void
    {
        config()->set('services.torrents.stream_direto_max_termos', 50);

        $termos = $this->termos('termosDeStreaming', 'Cidade de Deus');

        $this->assertCount(5, $termos);
    }
}
