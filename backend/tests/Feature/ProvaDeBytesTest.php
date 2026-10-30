<?php

namespace Tests\Feature;

use App\Services\Torrents\TesteDeFontes;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Trava a prova de bytes da verificação.
 *
 * O selo promete que o vídeo toca. A malha responde "há peers?"; esta prova
 * responde "o arquivo abre?" — e é o que separa as duas coisas. O veredito é
 * tri-estado de propósito: só uma recusa definitiva reprova a fonte, porque
 * timeout e `403` de anti-bot não provam que ela está morta.
 */
class ProvaDeBytesTest extends TestCase
{
    private function fonte(): array
    {
        return [
            'id' => 'abc123',
            'tipo' => 'torrent',
            'titulo' => 'Lupin S01E01',
            'idioma' => 'pt-BR',
            'magnet' => 'magnet:?xt=urn:btih:abc',
            'stream' => '',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.media_service.url' => 'http://media-service:3000',
            'services.verificacao.prova_bytes' => true,
            'services.verificacao.prova_max_fontes' => 2,
        ]);
    }

    public function test_ok_do_media_service_vira_prova(): void
    {
        Http::fake([
            '*/api/media/provar' => Http::response(['ok' => true, 'motivo' => null, 'bytes' => 300000, 'ms' => 2400]),
        ]);

        $this->assertTrue((new TesteDeFontes)->provar($this->fonte(), 1, 1));

        Http::assertSent(fn ($requisicao) => $requisicao['magnet'] === 'magnet:?xt=urn:btih:abc'
            && $requisicao['temporada'] === 1
            && $requisicao['episodio'] === 1
            && $requisicao['bytes_minimos'] > 0);
    }

    public function test_html_servido_com_200_reprova_a_fonte(): void
    {
        Http::fake([
            '*/api/media/provar' => Http::response(['ok' => false, 'motivo' => 'nao_e_midia', 'bytes' => 577]),
        ]);

        $this->assertFalse((new TesteDeFontes)->provar($this->fonte(), 1, 1));
    }

    public function test_torrent_sem_o_episodio_reprova(): void
    {
        Http::fake([
            '*/api/media/provar' => Http::response(['ok' => false, 'motivo' => 'sem_arquivo_de_video', 'bytes' => 0]),
        ]);

        $this->assertFalse((new TesteDeFontes)->provar($this->fonte(), 4, 2));
    }

    /**
     * `403` de anti-bot, timeout e queda do media-service são "não sei".
     * Reprová-los trocaria um falso-positivo por um falso-negativo pior: a fonte
     * poderia tocar e sairia do ar por causa de uma sonda mal-educada.
     */
    public function test_inconclusivo_nao_reprova(): void
    {
        foreach (['http_403', 'sem_bytes', 'sem_metadados', 'erro_de_rede'] as $motivo) {
            Http::fake([
                '*/api/media/provar' => Http::response(['ok' => false, 'motivo' => $motivo, 'bytes' => 0]),
            ]);

            $this->assertNull((new TesteDeFontes)->provar($this->fonte(), 1, 1), "Motivo: {$motivo}");
        }

        Http::fake(['*/api/media/provar' => Http::response('falhou', 500)]);

        $this->assertNull((new TesteDeFontes)->provar($this->fonte(), 1, 1));
    }

    public function test_chave_desligada_nao_gasta_requisicao(): void
    {
        config(['services.verificacao.prova_bytes' => false]);

        Http::fake();

        $this->assertNull((new TesteDeFontes)->provar($this->fonte(), 1, 1));

        Http::assertNothingSent();
    }

    /**
     * A lista tem alternativas: uma fonte morta no topo não pode condenar o
     * título quando a segunda entrega.
     */
    public function test_prova_a_segunda_fonte_quando_a_primeira_recusa(): void
    {
        Http::fakeSequence('*/api/media/provar')
            ->push(['ok' => false, 'motivo' => 'nao_e_midia', 'bytes' => 20])
            ->push(['ok' => true, 'motivo' => null, 'bytes' => 300000]);

        $outra = $this->fonte();
        $outra['id'] = 'def456';

        $this->assertTrue((new TesteDeFontes)->provarMelhor([$this->fonte(), $outra], 1, 1));
    }

    public function test_todas_recusando_de_forma_definitiva_reprovam(): void
    {
        Http::fake([
            '*/api/media/provar' => Http::response(['ok' => false, 'motivo' => 'nao_e_midia', 'bytes' => 20]),
        ]);

        $this->assertFalse((new TesteDeFontes)->provarMelhor([$this->fonte()], 1, 1));
    }

    public function test_uma_inconclusiva_segura_o_veredito(): void
    {
        Http::fakeSequence('*/api/media/provar')
            ->push(['ok' => false, 'motivo' => 'nao_e_midia', 'bytes' => 20])
            ->push(['ok' => false, 'motivo' => 'sem_bytes', 'bytes' => 0]);

        $outra = $this->fonte();
        $outra['id'] = 'def456';

        $this->assertNull((new TesteDeFontes)->provarMelhor([$this->fonte(), $outra], 1, 1));
    }
}
