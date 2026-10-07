<?php

namespace Tests\Unit;

use App\Enums\IdiomaFonte;
use App\Support\IndiciosPtBr;
use PHPUnit\Framework\TestCase;

/**
 * Trava a regressão da classificação de idioma pelo nome do release.
 *
 * A regra é conservadora de propósito: só a **marca** que fala do áudio (ou da
 * origem brasileira) promove uma fonte a dublado/dual. Dois indícios ficam de
 * fora, e os testes cobrem ambos:
 *
 * - O marcador genérico de multi-faixa ("dual", "multi áudio"), que diz que o
 *   arquivo carrega mais de uma faixa mas não que uma delas é português — num
 *   pack de anime quase sempre é japonês + inglês. O caso que originou estes
 *   testes foi um release alemão de "Attack on Titan" ("... (Multi-Audio)
 *   (German/Deutsch) ...") que chegava à lista como "Dublado".
 * - O "pt" solto, que em "Legendado pt BR" descreve a **legenda**, não o áudio.
 *
 * Sem marca, a fonte cai para `original`: o corte de idioma a rebaixa à reserva e
 * o fallback legendado a serve com legenda (ou a descarta, se não houver legenda).
 * Estes testes cobrem a função pura que sustenta a regra, sem tocar em rede nem
 * em banco.
 */
class ClassificacaoIdiomaTest extends TestCase
{
    /**
     * O caso exato do bug: a tag genérica não pode promover um release alemão.
     */
    public function test_release_alemao_multi_audio_nao_e_dublado(): void
    {
        $release = '[PHTMini] Attack on Titan - S01 (BD 1080p AV1 Opus 2.0) '
            .'(Multi-Audio) (German/Deutsch) | Shingeki no Kyojin (Season 1)';

        $this->assertSame(
            IdiomaFonte::ORIGINAL,
            IdiomaFonte::deduzirDoTitulo($release),
            'Um "(Multi-Audio) (German/Deutsch)" sem português não pode ser dublado.'
        );
    }

    /**
     * A mesma regra vale para qualquer idioma estrangeiro explícito, por extenso
     * ou em código.
     */
    public function test_multi_audio_com_idioma_estrangeiro_vira_original(): void
    {
        $this->assertSame(
            IdiomaFonte::ORIGINAL,
            IdiomaFonte::deduzirDoTitulo('Anime (Multi-Audio) (English/Japanese)')
        );

        $this->assertSame(
            IdiomaFonte::ORIGINAL,
            IdiomaFonte::deduzirDoTitulo('Attack on Titan S01 Multi-Audio JPN')
        );
    }

    /**
     * O marcador genérico de multi-faixa, sozinho, não prova português. O "dual"
     * de um anime japonês é japonês + inglês, então o release cai para o idioma
     * original e é servido com legenda.
     */
    public function test_dual_audio_sem_marca_pt_br_vira_original(): void
    {
        $this->assertSame(
            IdiomaFonte::ORIGINAL,
            IdiomaFonte::deduzirDoTitulo('Attack on Titan S01 [Dual Audio] 1080p')
        );
    }

    /**
     * Com a marca de áudio ao lado, o "dual" continua sendo o que sempre foi: o
     * release carrega as duas faixas e merece a prioridade do dual.
     */
    public function test_dual_audio_com_marca_pt_br_continua_dual(): void
    {
        $this->assertSame(
            IdiomaFonte::DUAL_AUDIO,
            IdiomaFonte::deduzirDoTitulo('American Horror Story S01 1080p DUAL ÁUDIO Dublado')
        );
    }

    /**
     * A prova forte de PT-BR vence a menção a idioma estrangeiro: quando o nome já
     * diz "Dublado" ou "Português", a outra faixa é só a segunda língua do arquivo.
     */
    public function test_prova_forte_vence_idioma_estrangeiro(): void
    {
        $this->assertSame(
            IdiomaFonte::DUBLADO,
            IdiomaFonte::deduzirDoTitulo('Filme Dublado Português/English 1080p')
        );

        $this->assertSame(
            IdiomaFonte::DUBLADO,
            IdiomaFonte::deduzirDoTitulo('Filme Nacional Multi-Áudio 1080p')
        );
    }

    /**
     * O código de PT-BR **colado** (pt-br, ptbr) é marca explícita e prova o
     * áudio — com o "dual" ao lado, a fonte é dual.
     */
    public function test_codigo_pt_br_colado_conta_como_marca(): void
    {
        $this->assertSame(
            IdiomaFonte::DUBLADO,
            IdiomaFonte::deduzirDoTitulo('Filme PT-BR 1080p WEB-DL')
        );

        $this->assertSame(
            IdiomaFonte::DUAL_AUDIO,
            IdiomaFonte::deduzirDoTitulo('Filme (Dual Áudio) (PT-BR)')
        );
    }

    /**
     * O "pt" solto, esse não: em "(PT/ESP)" ele é ambíguo demais para provar
     * dublagem e a fonte cai para original.
     */
    public function test_codigo_pt_solto_nao_prova_dublado(): void
    {
        $this->assertSame(
            IdiomaFonte::ORIGINAL,
            IdiomaFonte::deduzirDoTitulo('Filme (Dual Áudio) (PT/ESP)')
        );
    }

    /**
     * Legendado com idioma estrangeiro não vira original nem dublado: a tag de
     * legenda continua mandando.
     */
    public function test_legendado_com_idioma_estrangeiro_continua_legendado(): void
    {
        $this->assertSame(
            IdiomaFonte::LEGENDADO,
            IdiomaFonte::deduzirDoTitulo('Release Legendado English 720p')
        );
    }

    /**
     * Um release sem nenhuma tag de áudio ou idioma continua original — a regra
     * nova não pode promover o que antes era silencioso.
     */
    public function test_release_sem_tag_continua_original(): void
    {
        $this->assertSame(
            IdiomaFonte::ORIGINAL,
            IdiomaFonte::deduzirDoTitulo('Generic Movie 1080p Web-DL')
        );
    }

    /**
     * O "pt" solto continua valendo na leitura de **conteúdo** (pastas e nomes
     * internos), onde não há uma tag de legenda no título para desempatar.
     */
    public function test_pt_solto_vale_na_leitura_de_conteudo(): void
    {
        $this->assertTrue(
            IndiciosPtBr::contem('Temporada 1/pt'),
            'No conteúdo, o "pt" solto ainda marca um pack nacional.'
        );
    }
}
