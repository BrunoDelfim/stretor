<?php

namespace Tests\Feature;

use App\Enums\TipoMidia;
use App\Models\Disponibilidade;
use App\Models\DisponibilidadeEpisodio;
use App\Services\Verificacao\ProvaDePlayback;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsaBancoDeTeste;
use Tests\TestCase;

/**
 * Trava a prova do playback e a validade curta de série.
 *
 * O vídeo abrir vale mais que qualquer sonda: o episódio ganha o selo sem gastar
 * busca. E o que falha não pode continuar verde — o usuário acabou de ver que
 * não abre. A prova de episódio também vence mais cedo que a de filme, porque
 * fonte de série cai do ar muito mais rápido.
 */
class ProvaDePlaybackTest extends TestCase
{
    use RefreshDatabase;
    use UsaBancoDeTeste;

    public function test_episodio_que_tocou_ganha_o_selo(): void
    {
        (new ProvaDePlayback)->registrar(1405, 1, 4, true);

        $prova = DisponibilidadeEpisodio::query()
            ->where('tmdb_id', 1405)->where('temporada', 1)->where('episodio', 4)->first();

        $this->assertNotNull($prova);
        $this->assertTrue($prova->tem_fonte);
        $this->assertFalse($prova->expirado());
    }

    public function test_episodio_que_falhou_perde_o_selo_na_hora(): void
    {
        (new ProvaDePlayback)->registrar(1405, 1, 4, true);
        (new ProvaDePlayback)->registrar(1405, 1, 4, false);

        $prova = DisponibilidadeEpisodio::query()
            ->where('tmdb_id', 1405)->where('temporada', 1)->where('episodio', 4)->first();

        $this->assertFalse($prova->tem_fonte);
        // Recente: não expirada por tempo — o selo fica cinza pelo próprio veredito.
        $this->assertFalse($prova->expirado());
    }

    public function test_filme_que_tocou_ganha_o_selo(): void
    {
        (new ProvaDePlayback)->registrar(1400940, null, null, true);

        $prova = Disponibilidade::query()->where('tmdb_id', 1400940)->first();

        $this->assertNotNull($prova);
        $this->assertSame(TipoMidia::FILME, $prova->tipo);
        $this->assertTrue($prova->tem_fonte);
    }

    /**
     * Episódio vence em dois dias; filme continua na janela longa.
     */
    public function test_serie_vence_antes_do_filme(): void
    {
        $episodio = new DisponibilidadeEpisodio([
            'tmdb_id' => 1, 'temporada' => 1, 'episodio' => 1,
            'tem_fonte' => true, 'deve_retentar' => false,
            'verificado_em' => now()->subDays(3),
        ]);

        $filme = new Disponibilidade([
            'tipo' => 'filme', 'tmdb_id' => 1,
            'tem_fonte' => true, 'deve_retentar' => false,
            'verificado_em' => now()->subDays(3),
        ]);

        $this->assertTrue($episodio->expirado());
        $this->assertFalse($filme->expirado());
    }
}
