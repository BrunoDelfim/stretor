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

    public function test_plataformas_padrao_ancoram_a_busca_em_sites_de_video(): void
    {
        config()->set('services.torrents.stream_direto_plataformas', []);

        $plataformas = $this->termos('plataformasDeVideo');

        // A âncora `site:` restringe o motor a domínios que de fato hospedam
        // vídeo, tirando do resultado os catálogos e fóruns que a lista negra
        // teria de descartar depois.
        $this->assertSame('site:tokyvideo.com', $plataformas[0]);
        $this->assertContains('site:dailymotion.com', $plataformas);
        $this->assertContains('site:ok.ru', $plataformas);
    }

    public function test_plataformas_configuradas_substituem_o_padrao(): void
    {
        config()->set('services.torrents.stream_direto_plataformas', ['site:meusite.com']);

        $plataformas = $this->termos('plataformasDeVideo');

        $this->assertSame(['site:meusite.com'], $plataformas);
    }

    public function test_plataformas_vazias_sao_descartadas(): void
    {
        config()->set('services.torrents.stream_direto_plataformas', ['site:x.com', '', '   ']);

        $plataformas = $this->termos('plataformasDeVideo');

        $this->assertSame(['site:x.com'], $plataformas);
    }

    public function test_termo_ancorado_em_plataforma_vem_primeiro(): void
    {
        config()->set('services.torrents.stream_direto_max_termos', 0);

        $termos = $this->termos('termosDeStreaming', 'Cidade de Deus');

        // O termo ancorado numa plataforma de vídeo é o mais preciso: não depende
        // do ranqueamento do motor para achar a página do player.
        $this->assertSame('Cidade de Deus site:tokyvideo.com', $termos[0]);
    }

    public function test_episodio_ancora_a_plataforma_com_a_numeracao(): void
    {
        config()->set('services.torrents.stream_direto_max_termos', 0);

        $termos = $this->termos('termosDeStreaming', 'Donas de Casa Desesperadas', 1, 1);

        // A numeração entra junto da âncora para o episódio não virar a série
        // inteira na página da plataforma.
        $this->assertContains('Donas de Casa Desesperadas 1x01 site:tokyvideo.com', $termos);
        $this->assertContains('Donas de Casa Desesperadas S01E01 site:tokyvideo.com', $termos);
    }

    public function test_filme_usa_os_termos_de_agregador(): void
    {
        config()->set('services.torrents.stream_direto_termos', []);
        // O teto alto isola a montagem do corte: as intenções genéricas ficam
        // depois das âncoras de plataforma e o teto padrão as descartaria.
        config()->set('services.torrents.stream_direto_max_termos', 0);

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
        // mais específico precisa ser o primeiro da lista — e o mais específico
        // é o ancorado numa plataforma de vídeo.
        $this->assertSame('Donas de Casa Desesperadas 1x01 site:tokyvideo.com', $termos[0]);
    }

    public function test_numeracao_de_dois_digitos_e_preservada(): void
    {
        config()->set('services.torrents.stream_direto_max_termos', 0);

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
        // O teto alto isola a montagem do corte: o título original é o último da
        // lista e o teto padrão o descartaria antes de chegar aqui.
        config()->set('services.torrents.stream_direto_max_termos', 0);

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
        // cauda genérica e mantém as âncoras de plataforma — que são as que
        // mais rendem página de player por consulta. A plataforma mais forte
        // (Tokyvideo) vem primeiro, com todas as grafias de numeração.
        $this->assertSame('Donas de Casa Desesperadas 1x01 site:tokyvideo.com', $termos[0]);
        $this->assertSame('Donas de Casa Desesperadas S01E01 site:tokyvideo.com', $termos[1]);
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

        // 4 âncoras de plataforma + 5 intenções de streaming.
        $this->assertCount(9, $termos);
    }
}
