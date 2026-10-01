<?php

namespace Tests\Unit;

use App\Support\RoteadorBusca;
use Tests\TestCase;

/**
 * O roteador por idade decide por onde a busca começa, e a decisão é o que
 * separa o timeout da resposta rápida: série antiga que começa pelos torrents
 * gasta o orçamento inteiro num catálogo que já não tem o release.
 *
 * O que importa testar aqui é a fronteira do limiar (inclusivo), a ausência do
 * ano (que não pode virar "antiga") e o desligamento da estratégia. Os anos são
 * calculados a partir do ano corrente para o teste não quebrar na virada.
 */
class RoteadorBuscaTest extends TestCase
{
    private function anoCorrente(): int
    {
        return (int) date('Y');
    }

    public function test_serie_recente_comeca_pelos_torrents(): void
    {
        config()->set('services.torrents.busca_por_idade_habilitada', true);
        config()->set('services.torrents.busca_idade_limite_anos', 2);

        $this->assertSame(
            RoteadorBusca::CANAL_TORRENTS,
            RoteadorBusca::canalPreferido($this->anoCorrente())
        );
    }

    public function test_serie_no_limite_ainda_e_recente(): void
    {
        // O limiar é inclusivo: com 2 anos configurados, uma série de exatamente
        // dois anos ainda é recente. É o que o operador espera ao escrever "2".
        config()->set('services.torrents.busca_por_idade_habilitada', true);
        config()->set('services.torrents.busca_idade_limite_anos', 2);

        $this->assertSame(
            RoteadorBusca::CANAL_TORRENTS,
            RoteadorBusca::canalPreferido($this->anoCorrente() - 2)
        );
    }

    public function test_serie_fora_do_limiar_comeca_pelo_stream_direto(): void
    {
        config()->set('services.torrents.busca_por_idade_habilitada', true);
        config()->set('services.torrents.busca_idade_limite_anos', 2);

        $this->assertSame(
            RoteadorBusca::CANAL_STREAM_DIRETO,
            RoteadorBusca::canalPreferido($this->anoCorrente() - 3)
        );
    }

    public function test_serie_muito_antiga_tambem_vai_para_o_stream_direto(): void
    {
        // O caso que motivou a estratégia: uma série de 2004 não tem release
        // fresco nos indexadores, e insistir neles é o caminho do timeout.
        config()->set('services.torrents.busca_por_idade_habilitada', true);
        config()->set('services.torrents.busca_idade_limite_anos', 2);

        $this->assertSame(
            RoteadorBusca::CANAL_STREAM_DIRETO,
            RoteadorBusca::canalPreferido(2004)
        );
    }

    public function test_sem_ano_conhecido_a_aposta_e_o_fluxo_normal(): void
    {
        // Sem ano não há como medir idade, e tratar a ausência como "antiga"
        // mandaria para o scraper uma série recente que os torrents resolveriam
        // em um segundo. A aposta segura é o fluxo normal.
        config()->set('services.torrents.busca_por_idade_habilitada', true);
        config()->set('services.torrents.busca_idade_limite_anos', 2);

        $this->assertSame(RoteadorBusca::CANAL_TORRENTS, RoteadorBusca::canalPreferido(null));
        $this->assertSame(RoteadorBusca::CANAL_TORRENTS, RoteadorBusca::canalPreferido(0));
    }

    public function test_estrategia_desligada_volta_ao_fluxo_antigo(): void
    {
        // Desligada, o roteador devolve sempre torrents — mesmo para a série de
        // 2004 que, ligada, iria para o stream direto.
        config()->set('services.torrents.busca_por_idade_habilitada', false);
        config()->set('services.torrents.busca_idade_limite_anos', 2);

        $this->assertSame(RoteadorBusca::CANAL_TORRENTS, RoteadorBusca::canalPreferido(2004));
    }

    public function test_limiar_zero_desliga_o_corte_na_pratica(): void
    {
        // Limiar zero ou negativo não faz sentido como idade: nenhuma série fica
        // "fora" dele, e o canal preferido volta a ser sempre torrents.
        config()->set('services.torrents.busca_por_idade_habilitada', true);
        config()->set('services.torrents.busca_idade_limite_anos', 0);

        $this->assertSame(RoteadorBusca::CANAL_TORRENTS, RoteadorBusca::canalPreferido(2004));
    }

    public function test_limiar_configuravel_muda_a_fronteira(): void
    {
        // Com 10 anos de limiar, uma série de 2004 (mais de 20 anos) continua
        // antiga, mas uma de cinco anos passa a ser recente.
        config()->set('services.torrents.busca_por_idade_habilitada', true);
        config()->set('services.torrents.busca_idade_limite_anos', 10);

        $this->assertSame(
            RoteadorBusca::CANAL_TORRENTS,
            RoteadorBusca::canalPreferido($this->anoCorrente() - 5)
        );
        $this->assertSame(RoteadorBusca::CANAL_STREAM_DIRETO, RoteadorBusca::canalPreferido(2004));
    }

    public function test_canal_oposto_e_o_cruzamento_do_fallback(): void
    {
        // O fallback cruzado é o último recurso: quando o canal preferido não
        // devolve nada, o outro ainda pode ter a resposta.
        $this->assertSame(
            RoteadorBusca::CANAL_TORRENTS,
            RoteadorBusca::canalOposto(RoteadorBusca::CANAL_STREAM_DIRETO)
        );
        $this->assertSame(
            RoteadorBusca::CANAL_STREAM_DIRETO,
            RoteadorBusca::canalOposto(RoteadorBusca::CANAL_TORRENTS)
        );
    }

    public function test_e_antiga_nao_confunde_ano_invalido(): void
    {
        config()->set('services.torrents.busca_idade_limite_anos', 2);

        $this->assertFalse(RoteadorBusca::eAntiga(null));
        $this->assertFalse(RoteadorBusca::eAntiga(0));
        $this->assertFalse(RoteadorBusca::eAntiga(-5));
    }
}
