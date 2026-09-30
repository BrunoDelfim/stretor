<?php

namespace Tests\Unit;

use App\Services\Torrents\TermosBusca;
use PHPUnit\Framework\TestCase;

/**
 * Trava a regressão do gate de temporada.
 *
 * O bug que originou estes testes: uma busca por S01E01 de "American Horror
 * Story" voltava com o pack da 2ª temporada no topo. O release
 * "American Horror Story.2ª.Temporada.Dual.Áudio.720p." declara a temporada pelo
 * ordinal, mas o marcador vinha depois de um ponto — a regex antiga, que exigia
 * espaço, não enxergava o "2ª" e o pack passava pelo gate. Como tinha mais seeds
 * e áudio dual, subia ao topo e o player abria o episódio de outra temporada.
 *
 * Os testes cobrem as três funções puras que sustentam o gate, sem tocar em rede
 * nem em banco: `correspondeAoEpisodio()`, `temporadaDoTitulo()` e
 * `temporadaNoRelease()`.
 */
class TermosBuscaTest extends TestCase
{
    /**
     * O caso exato do bug: o pack da 2ª temporada não pode entrar numa busca da 1ª.
     */
    public function test_pack_da_segunda_temporada_e_reprovado_na_busca_da_primeira(): void
    {
        $release = 'American Horror Story.2ª.Temporada.Dual.Áudio.720p.';

        $this->assertFalse(
            TermosBusca::correspondeAoEpisodio($release, 1, 1),
            'O pack da 2ª temporada não pode ser aceito numa busca de S01E01.'
        );
    }

    /**
     * O pack legítimo da 1ª temporada precisa continuar passando — o gate não
     * pode ser um corte cego que apague fontes corretas.
     */
    public function test_pack_da_primeira_temporada_e_aceito_na_busca_da_primeira(): void
    {
        $release = 'American Horror Story 1ª Temporada [2011 DUAL AUDIO] 720p P';

        $this->assertTrue(
            TermosBusca::correspondeAoEpisodio($release, 1, 1),
            'O pack da 1ª temporada deve ser aceito numa busca de S01E01.'
        );
    }

    /**
     * O ano do release ("2011") não pode ser lido como temporada. Sem essa
     * garantia, o pack legítimo da 1ª seria reprovado por "declarar" a temporada
     * 2011.
     */
    public function test_ano_do_release_nao_e_lido_como_temporada(): void
    {
        $this->assertSame(
            1,
            TermosBusca::temporadaDoTitulo('American Horror Story 1ª Temporada [2011 DUAL AUDIO] 720p P'),
            'A temporada declarada pelo ordinal deve ser lida como 1, e não o ano 2011.'
        );

        $this->assertNull(
            TermosBusca::temporadaDoTitulo('American Horror Story [2011] DUAL AUDIO 720p'),
            'Um título só com o ano, sem ordinal nem palavra-chave, não declara temporada.'
        );
    }

    /**
     * A leitura da temporada declarada precisa cobrir as grafias que os trackers
     * usam, inclusive com ponto no lugar do espaço.
     */
    public function test_temporada_do_titulo_le_as_grafias_dos_trackers(): void
    {
        $this->assertSame(2, TermosBusca::temporadaDoTitulo('American Horror Story.2ª.Temporada.Dual.Áudio.720p.'));
        $this->assertSame(2, TermosBusca::temporadaDoTitulo('American Horror Story 2ª Temporada'));
        $this->assertSame(2, TermosBusca::temporadaDoTitulo('American Horror Story Temporada 2'));
        $this->assertSame(2, TermosBusca::temporadaDoTitulo('American Horror Story Season 2'));
        $this->assertSame(2, TermosBusca::temporadaDoTitulo('American Horror Story S02'));
    }

    /**
     * Um título sem numeração nenhuma não pode ser reprovado: são os nomes
     * nacionais legítimos, que não têm como provar a temporada.
     */
    public function test_titulo_sem_numeracao_passa_pelo_gate(): void
    {
        $this->assertNull(TermosBusca::temporadaDoTitulo('American Horror Story Dublado 720p'));

        $this->assertTrue(
            TermosBusca::correspondeAoEpisodio('American Horror Story Dublado 720p', 1, 1),
            'Título sem numeração não pode ser descartado pelo gate.'
        );
    }

    /**
     * O gate não pode reprovar o episódio certo nem aceitar o episódio errado da
     * mesma temporada.
     */
    public function test_episodio_da_temporada_certa_e_conferido(): void
    {
        $this->assertTrue(TermosBusca::correspondeAoEpisodio('American.Horror.Story.S01E01.720p', 1, 1));
        $this->assertFalse(TermosBusca::correspondeAoEpisodio('American.Horror.Story.S01E02.720p', 1, 1));
        $this->assertTrue(TermosBusca::correspondeAoEpisodio('American Horror Story 1x01 720p', 1, 1));
        $this->assertTrue(TermosBusca::correspondeAoEpisodio('American Horror Story Temporada 1 Episódio 1', 1, 1));
    }

    /**
     * Packs multi-temporada que incluem a pedida precisam passar. A leitura
     * simples devolveria só o primeiro número e reprovaria um pack legítimo.
     */
    public function test_pack_multi_temporada_que_inclui_a_pedida_passa(): void
    {
        $this->assertTrue(
            TermosBusca::correspondeAoEpisodio('American Horror Story 1ª 2ª 3ª Temporadas 720p', 2, 1),
            'O pack que cobre a 1ª, 2ª e 3ª deve passar numa busca da 2ª.'
        );

        $this->assertTrue(
            TermosBusca::correspondeAoEpisodio('American Horror Story Seasons 1 to 8 720p', 5, 1),
            'O pack "Seasons 1 to 8" deve passar numa busca da 5ª.'
        );

        $this->assertFalse(
            TermosBusca::correspondeAoEpisodio('American Horror Story Seasons 1 to 8 720p', 9, 1),
            'O pack "Seasons 1 to 8" não pode passar numa busca da 9ª.'
        );
    }

    /**
     * A leitura de faixa de temporada no nome do release precisa reconhecer os
     * formatos que os trackers publicam.
     */
    public function test_temporada_no_release_reconhece_faixas(): void
    {
        $this->assertTrue(TermosBusca::temporadaNoRelease('American Horror Story S01 1080p', 1));
        $this->assertTrue(TermosBusca::temporadaNoRelease('American Horror Story S01-S05 1080p', 3));
        $this->assertTrue(TermosBusca::temporadaNoRelease('American Horror Story Temporada 1', 1));
        $this->assertTrue(TermosBusca::temporadaNoRelease('American Horror Story 1ª Temporada', 1));
        $this->assertFalse(TermosBusca::temporadaNoRelease('American Horror Story S02 1080p', 1));
    }

    /**
     * O caso exato dos logs: o `release` é o nome do arquivo ("2x13 - Madness
     * Ends") e o `titulo` é o nome do torrent ("2ª.Temporada"). A temporada
     * declarada no título precisa vencer a numeração de episódio do arquivo —
     * era o contrário que deixava o pack da 2ª invadir a busca da 1ª.
     */
    public function test_temporada_dos_nomes_le_a_temporada_do_titulo_quando_o_release_e_o_arquivo(): void
    {
        $nomes = [
            '2x13 - Madness Ends (Season Finale)',
            'American Horror Story.2ª.Temporada.Dual.Áudio.720p.By.Luan.Harper',
        ];

        $this->assertSame(
            2,
            TermosBusca::temporadaDosNomes($nomes),
            'A temporada do nome do torrent deve vencer a numeração de episódio do arquivo.'
        );

        $this->assertFalse(
            TermosBusca::algumNomeCobreTemporada($nomes, 1),
            'O pack da 2ª não pode cobrir a temporada 1.'
        );

        $this->assertTrue(
            TermosBusca::algumNomeCobreTemporada($nomes, 2),
            'O pack da 2ª cobre a temporada 2.'
        );
    }

    /**
     * Quando nenhum nome declara temporada, a leitura devolve `null` e quem
     * decide é a numeração de episódio — o comportamento de antes.
     */
    public function test_temporada_dos_nomes_devolve_nulo_sem_temporada_declarada(): void
    {
        $this->assertNull(
            TermosBusca::temporadaDosNomes(['S01E01.mkv', 'American Horror Story Dublado 720p'])
        );

        $this->assertNull(TermosBusca::temporadaDosNomes(['', null]));
    }

    /**
     * O pack multi-temporada precisa ser reconhecido mesmo quando o outro nome
     * traz só a numeração de um episódio.
     */
    public function test_algum_nome_cobre_temporada_com_pack_multi(): void
    {
        $nomes = ['2x13 - Madness Ends', 'American Horror Story 1ª 2ª 3ª Temporadas 720p'];

        $this->assertTrue(TermosBusca::algumNomeCobreTemporada($nomes, 1));
        $this->assertTrue(TermosBusca::algumNomeCobreTemporada($nomes, 3));
        $this->assertFalse(TermosBusca::algumNomeCobreTemporada($nomes, 4));
    }

    /**
     * O marcador textual de pack é o socorro do nome que não numera a temporada —
     * "A Série Completa Dublado", comum nos trackers nacionais. Sem ele o pack
     * não era sequer etiquetado e morria no gate de temporada, o que produzia o
     * `na_lista: 0` dos packs compactados.
     */
    public function test_marcador_de_pack_reconhece_nomes_sem_temporada(): void
    {
        $this->assertTrue(TermosBusca::temMarcadorDePack('American Horror Story - A Série Completa Dublado 1080p'));
        $this->assertTrue(TermosBusca::temMarcadorDePack('American Horror Story Boxset 1080p'));
        $this->assertTrue(TermosBusca::temMarcadorDePack('American Horror Story Coleção Completa'));
        $this->assertTrue(TermosBusca::temMarcadorDePack('American Horror Story Temporadas Completas'));
    }

    /**
     * O marcador não pode disparar num episódio solto: sem "completa", "boxset"
     * ou "coleção" no nome, não há pacote a reconhecer.
     */
    public function test_marcador_de_pack_nao_dispara_em_episodio_solto(): void
    {
        $this->assertFalse(TermosBusca::temMarcadorDePack('American Horror Story Dublado 720p'));
        $this->assertFalse(TermosBusca::temMarcadorDePack('American.Horror.Story.S01E01.720p'));
    }

    /**
     * O termo de pack continua exigindo marcador **e** temporada juntos, para que
     * um filme de título "The Complete ..." não seja tomado por pacote de série.
     */
    public function test_termo_de_pack_exige_marcador_e_temporada(): void
    {
        $this->assertTrue(TermosBusca::eTermoDePack('American Horror Story S01 completa'));
        $this->assertTrue(TermosBusca::eTermoDePack('American Horror Story Temporada 1 completa dublada'));
        $this->assertFalse(TermosBusca::eTermoDePack('American Horror Story completa'));
        $this->assertFalse(TermosBusca::eTermoDePack('American Horror Story Dublado 720p'));
    }

    /**
     * O termo amplo é o que destrava o meta-buscador. O Knaben casa **todas** as
     * palavras do termo (`search_type=100%`), então a numeração de episódio e a
     * tag de áudio, juntas, cortavam o recall do pack nacional. Tirando as duas,
     * sobra o nome da série com a temporada — o formato em que o pack é publicado.
     */
    public function test_termo_amplo_de_serie_nao_carrega_tag_de_audio(): void
    {
        $termos = TermosBusca::serieAmpla('American Horror Story', 1);

        $this->assertSame([
            'American Horror Story S01',
            'American Horror Story temporada 1',
            'American Horror Story season 1',
            'American Horror Story',
        ], $termos);

        foreach ($termos as $termo) {
            $this->assertFalse(
                TermosBusca::jaEDublado($termo),
                "O termo amplo \"{$termo}\" não pode carregar tag de áudio — é ela que corta o recall."
            );
        }
    }

    /**
     * A pontuação do título sai pelo `limpar()`: dois-pontos e hífen atrapalham a
     * busca por palavra-chave em vários indexadores.
     */
    public function test_termo_amplo_de_serie_limpa_a_pontuacao_do_titulo(): void
    {
        $this->assertSame([
            'Homem Aranha Sem Volta para Casa S03',
            'Homem Aranha Sem Volta para Casa temporada 3',
            'Homem Aranha Sem Volta para Casa season 3',
            'Homem Aranha Sem Volta para Casa',
        ], TermosBusca::serieAmpla('Homem-Aranha: Sem Volta para Casa', 3));
    }
}
