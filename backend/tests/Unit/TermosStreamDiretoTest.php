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

        // Medição, não preferência: o termo genérico "assistir online" é o que
        // alcança o agregador que de fato hospeda o episódio. O "dublado" faz o
        // motor devolver plataformas pagas (Prime Video, JustWatch, Disney+) e
        // clones de fachada que queimam o orçamento curto do fallback antes de a
        // página boa ser aberta. Por isso o genérico vem primeiro.
        $this->assertSame('assistir online', $intencoes[0]);
        $this->assertContains('assistir online dublado', $intencoes);
        $this->assertContains('filme completo dublado', $intencoes);
        $this->assertContains('serie completa dublada', $intencoes);
    }

    public function test_nenhum_termo_carrega_operador_de_dominio(): void
    {
        config()->set('services.torrents.stream_direto_max_termos', 0);

        $termos = $this->termos('termosDeStreaming', 'Donas de Casa Desesperadas', 1, 1);

        // A query é natural: a restrição de domínio saiu de cena para a busca não
        // ficar refém de um punhado de sites. Quem filtra é a lista negra e a
        // prova de mídia na extração, não o operador `site:`.
        foreach ($termos as $termo) {
            $this->assertStringNotContainsString('site:', $termo);
        }
    }

    public function test_termo_mais_especifico_vem_primeiro(): void
    {
        config()->set('services.torrents.stream_direto_max_termos', 0);

        $termos = $this->termos('termosDeStreaming', 'Cidade de Deus');

        // O termo de abertura é o genérico: é o que o motor responde com o
        // agregador que hospeda a mídia, e não com as plataformas pagas que o
        // "dublado" atrai. A intenção de idioma continua na lista, logo depois.
        $this->assertSame('Cidade de Deus assistir online', $termos[0]);
        $this->assertContains('Cidade de Deus assistir online dublado', $termos);
    }

    public function test_episodio_abre_com_titulo_numeracao_e_intencao(): void
    {
        config()->set('services.torrents.stream_direto_max_termos', 0);

        $termos = $this->termos('termosDeStreaming', 'Donas de Casa Desesperadas', 1, 1);

        // A numeração entra junto da intenção para o episódio não virar a série
        // inteira na página encontrada. A intenção de abertura é a genérica,
        // pelo mesmo motivo do filme: é a que alcança o agregador de verdade.
        $this->assertSame('Donas de Casa Desesperadas 1x01 assistir online', $termos[0]);
        $this->assertContains('Donas de Casa Desesperadas S01E01 assistir online dublado', $termos);
    }

    public function test_episodio_carrega_todas_as_grafias_de_numeracao(): void
    {
        config()->set('services.torrents.stream_direto_max_termos', 0);

        $termos = $this->termos('termosDeStreaming', 'Donas de Casa Desesperadas', 1, 1);

        // As quatro grafias de numeração precisam estar todas na lista: cada site
        // usa uma, e mandar só uma deixaria páginas boas de fora.
        $this->assertContains('Donas de Casa Desesperadas 1x01 assistir online dublado', $termos);
        $this->assertContains('Donas de Casa Desesperadas S01E01 assistir online dublado', $termos);
        $this->assertContains('Donas de Casa Desesperadas Temporada 1 Episódio 1 assistir online dublado', $termos);
        $this->assertContains('Donas de Casa Desesperadas 1ª Temporada Episódio 1 assistir online dublado', $termos);
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

        // O provedor para assim que junta páginas suficientes, então o primeiro
        // termo precisa ser o que rende página de player. A medição mostrou que
        // é o genérico — o "dublado" traz plataformas pagas e clones de fachada.
        $this->assertSame('Donas de Casa Desesperadas 1x01 assistir online', $termos[0]);
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

        // O corte mantém a cabeça da lista, que é a ordem de rendimento medida:
        // o genérico primeiro, a variação dublada em seguida. A cauda (as
        // grafias de numeração e o título original) é o que sobra para depois.
        $this->assertSame('Donas de Casa Desesperadas 1x01 assistir online', $termos[0]);
        $this->assertSame('Donas de Casa Desesperadas 1x01 assistir online dublado', $termos[1]);
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

        // Um filme gera só as 5 intenções de streaming: sem numeração, não há
        // variação de episódio para multiplicar a lista.
        $this->assertCount(5, $termos);
    }

    public function test_titulo_ja_numerado_nao_repete_a_numeracao(): void
    {
        config()->set('services.torrents.stream_direto_max_termos', 0);

        // O `TorrentService` entrega o primeiro título já numerado, porque a
        // mesma lista alimenta os provedores de torrent. O scraper montava a
        // numeração de novo e produzia "... S01E01 1x01 ...", termo que nenhum
        // motor casa e que ainda gastava orçamento antes dos termos úteis.
        $termos = $this->termos('termosDeStreaming', 'Donas de Casa Desesperadas S01E01', 1, 1);

        foreach ($termos as $termo) {
            $this->assertStringNotContainsString('S01E01 1x01', $termo);
            $this->assertStringNotContainsString('S01E01 S01E01', $termo);
        }

        $this->assertContains('Donas de Casa Desesperadas assistir online dublado', $termos);
    }

    public function test_titulo_numerado_de_outro_episodio_preserva_a_numeracao_pedida(): void
    {
        config()->set('services.torrents.stream_direto_max_termos', 0);

        // A numeração declarada é de outro episódio: aí ela é informação nova e
        // o título é usado como veio, com a numeração pedida anexada.
        $termos = $this->termos('termosDeStreaming', 'Donas de Casa Desesperadas S01E05', 1, 1);

        $this->assertContains('Donas de Casa Desesperadas S01E05 1x01 assistir online dublado', $termos);
    }

    public function test_titulo_numerado_em_outra_grafia_tambem_e_limpo(): void
    {
        config()->set('services.torrents.stream_direto_max_termos', 0);

        // A limpeza cobre as grafias que os títulos de release usam, não só a
        // canônica "S01E01".
        $termos = $this->termos('termosDeStreaming', 'Donas de Casa Desesperadas 1x01', 1, 1);

        $this->assertContains('Donas de Casa Desesperadas assistir online dublado', $termos);

        foreach ($termos as $termo) {
            $this->assertStringNotContainsString('1x01 1x01', $termo);
        }
    }

    /**
     * O título alternativo também chega numerado — e também precisa ser limpo.
     *
     * O `TorrentService` monta a lista inteira com `TermosBusca::episodio()`, então
     * o título original ("Desperate Housewives S01E01") chega tão numerado quanto
     * o principal. A limpeza só valia para o primeiro; o alternativo recebia a
     * numeração de novo e o termo saía com duas grafias, que nenhum motor casa.
     */
    public function test_titulo_alternativo_numerado_nao_repete_a_numeracao(): void
    {
        config()->set('services.torrents.stream_direto_max_termos', 0);

        $termos = $this->termos(
            'termosDeStreaming',
            'Donas de Casa Desesperadas S01E01',
            1,
            1,
            ['Desperate Housewives S01E01']
        );

        foreach ($termos as $termo) {
            $this->assertStringNotContainsString('S01E01 1x01', $termo);
            $this->assertStringNotContainsString('S01E01 S01E01', $termo);
        }

        // O alternativo limpo entra como rede de segurança, sem numeração dupla.
        $this->assertContains('Desperate Housewives', $termos);
    }
}
