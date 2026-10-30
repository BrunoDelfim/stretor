<?php

namespace Tests\Feature;

use App\Enums\OrigemVerificacao;
use App\Models\DisponibilidadeEpisodio;
use App\Services\Verificacao\SeloVerificacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsaBancoDeTeste;
use Tests\TestCase;

/**
 * Trava o selo por episódio do modal.
 *
 * O selo do card responde pela série inteira, e a resposta dela é a do primeiro
 * episódio. Repetir esse verde em todas as linhas do modal seria prometer o que
 * ninguém testou: uma temporada pode ter o E01 no ar e o resto morto. Aqui a
 * régua é a mesma do card, mas por linha — `tem_fonte` verdadeiro e prova dentro
 * do prazo.
 */
class SeloEpisodiosTest extends TestCase
{
    use RefreshDatabase;
    use UsaBancoDeTeste;

    /** @return array<string, mixed> */
    private function episodio(int $numero): array
    {
        return ['numero' => $numero, 'temporada' => 1, 'titulo' => 'Episódio '.$numero];
    }

    private function prova(
        int $episodio,
        bool $temFonte = true,
        int $horasAtras = 0,
        bool $deveRetentar = false,
        int $temporada = 1,
    ): void {
        DisponibilidadeEpisodio::create([
            'tmdb_id' => 96677,
            'temporada' => $temporada,
            'episodio' => $episodio,
            'tem_fonte' => $temFonte,
            'inconclusivo' => false,
            'deve_retentar' => $deveRetentar,
            'fonte_idioma' => $temFonte ? 'pt-BR' : null,
            'fonte_tipo' => $temFonte ? 'torrent' : null,
            'origem' => OrigemVerificacao::PLAY->value,
            'verificado_em' => now()->subHours($horasAtras),
        ]);
    }

    /** O caso normal: o episódio provado há pouco aparece verde, o resto cinza. */
    public function test_apenas_episodio_com_prova_valida_ganha_selo(): void
    {
        $this->prova(1);

        $episodios = (new SeloVerificacao)->marcarEpisodios(
            [$this->episodio(1), $this->episodio(2)],
            96677,
            1,
        );

        $this->assertTrue($episodios[0]['verificado']);
        $this->assertNotNull($episodios[0]['verificado_em']);
        $this->assertFalse($episodios[1]['verificado']);
        $this->assertNull($episodios[1]['verificado_em']);
    }

    /** Veredito "sem fonte" não vira selo, mesmo sendo recente. */
    public function test_episodio_sem_fonte_nao_ganha_selo(): void
    {
        $this->prova(3, temFonte: false);

        $episodios = (new SeloVerificacao)->marcarEpisodios([$this->episodio(3)], 96677, 1);

        $this->assertFalse($episodios[0]['verificado']);
    }

    /**
     * A prova vencida derruba o selo: um release que tinha peers há uma semana
     * pode estar morto hoje, e o modal não pode repetir o verde de ontem.
     */
    public function test_prova_vencida_derruba_o_selo(): void
    {
        $this->prova(4, horasAtras: 8 * 24);

        $episodios = (new SeloVerificacao)->marcarEpisodios([$this->episodio(4)], 96677, 1);

        $this->assertFalse($episodios[0]['verificado']);
    }

    /**
     * Quem pediu nova chance usa a janela curta: passadas as 24 h, o selo cai
     * mesmo antes da janela longa do veredito definitivo.
     */
    public function test_prova_inconclusiva_vence_na_virada_do_dia(): void
    {
        $this->prova(5, horasAtras: 30, deveRetentar: true);

        $episodios = (new SeloVerificacao)->marcarEpisodios([$this->episodio(5)], 96677, 1);

        $this->assertFalse($episodios[0]['verificado']);
    }

    /** A prova de outra temporada não pode pintar a temporada aberta. */
    public function test_temporada_diferente_nao_interfere(): void
    {
        $this->prova(1, temporada: 2);

        $episodios = (new SeloVerificacao)->marcarEpisodios([$this->episodio(1)], 96677, 1);

        $this->assertFalse($episodios[0]['verificado']);
    }
}
