<?php

namespace Tests\Feature;

use App\Enums\TipoMidia;
use App\Models\FonteReproduzida;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsaBancoDeTeste;
use Tests\TestCase;

/**
 * O comando que limpa o histórico de links comprovados.
 *
 * O histórico é o que faz a fonte que tocou ontem voltar na frente da fila. Ele
 * é útil e não expira, então uma correção na busca pode continuar esbarrando num
 * resultado antigo — o caso que originou o comando: o magnet do Torrentio salvo
 * antes de o Pobreflix passar a resolver o StreamTape. O que se trava aqui é o
 * recorte: limpar tudo, limpar um título, limpar um episódio, e recusar filtro
 * malformado em vez de apagar mais do que o pedido.
 */
class LimparFontesTest extends TestCase
{
    use RefreshDatabase;
    use UsaBancoDeTeste;

    /** Grava uma comprovada direto na tabela, sem passar pela busca. */
    private function comprovada(
        string $fonteId,
        TipoMidia $tipo = TipoMidia::FILME,
        int $tmdbId = 550,
        int $temporada = 0,
        int $episodio = 0,
    ): void {
        FonteReproduzida::create([
            'tipo' => $tipo->value,
            'tmdb_id' => $tmdbId,
            'temporada' => $temporada,
            'episodio' => $episodio,
            'fonte_id' => $fonteId,
            'tipo_fonte' => 'torrent',
            'titulo' => 'Clube da Luta 1999 1080p Dublado',
            'idioma' => 'pt-BR',
            'magnet' => 'magnet:?xt=urn:btih:'.str_pad(substr(md5($fonteId), 0, 40), 40, 'a'),
            'reproducoes' => 1,
            'confirmada_em' => now(),
        ]);
    }

    public function test_limpa_o_historico_inteiro(): void
    {
        $this->comprovada('um');
        $this->comprovada('dois', TipoMidia::SERIE, 1399, 2, 5);

        $this->artisan('fontes:limpar')
            ->expectsOutputToContain('2 registro(s) apagado(s)')
            ->assertSuccessful();

        $this->assertSame(0, FonteReproduzida::count());
    }

    public function test_limpa_so_o_titulo_pedido(): void
    {
        $this->comprovada('do-filme', TipoMidia::FILME, 550);
        $this->comprovada('da-serie', TipoMidia::SERIE, 1399, 2, 5);

        $this->artisan('fontes:limpar', ['--tmdb' => 550])->assertSuccessful();

        $this->assertSame(['da-serie'], FonteReproduzida::pluck('fonte_id')->all());
    }

    public function test_limpa_so_o_episodio_pedido(): void
    {
        $this->comprovada('ep-5', TipoMidia::SERIE, 1399, 2, 5);
        $this->comprovada('ep-6', TipoMidia::SERIE, 1399, 2, 6);

        $this->artisan('fontes:limpar', [
            '--tmdb' => 1399,
            '--temporada' => 2,
            '--episodio' => 5,
        ])->assertSuccessful();

        $this->assertSame(['ep-6'], FonteReproduzida::pluck('fonte_id')->all());
    }

    public function test_tipo_invalido_nao_apaga_nada(): void
    {
        $this->comprovada('ficou');

        $this->artisan('fontes:limpar', ['--tipo' => 'filmes'])
            ->expectsOutputToContain('Tipo inválido')
            ->assertFailed();

        $this->assertSame(1, FonteReproduzida::count());
    }

    public function test_episodio_sem_titulo_nao_apaga_nada(): void
    {
        $this->comprovada('ficou', TipoMidia::SERIE, 1399, 2, 5);

        $this->artisan('fontes:limpar', ['--temporada' => 2, '--episodio' => 5])
            ->expectsOutputToContain('Informe o título')
            ->assertFailed();

        $this->assertSame(1, FonteReproduzida::count());
    }

    public function test_episodio_sem_temporada_nao_apaga_nada(): void
    {
        $this->comprovada('ficou', TipoMidia::SERIE, 1399, 2, 5);

        $this->artisan('fontes:limpar', ['--tmdb' => 1399, '--episodio' => 5])
            ->expectsOutputToContain('Informe a temporada')
            ->assertFailed();

        $this->assertSame(1, FonteReproduzida::count());
    }

    /** Filme não tem episódio: recusar aqui evita apagar a ficha errada. */
    public function test_filme_com_temporada_nao_apaga_nada(): void
    {
        $this->comprovada('ficou');

        $this->artisan('fontes:limpar', [
            '--tipo' => 'filme',
            '--tmdb' => 550,
            '--temporada' => 1,
        ])->assertFailed();

        $this->assertSame(1, FonteReproduzida::count());
    }

    public function test_historico_vazio_termina_bem(): void
    {
        $this->artisan('fontes:limpar')
            ->expectsOutputToContain('0 registro(s) apagado(s)')
            ->assertSuccessful();
    }
}
