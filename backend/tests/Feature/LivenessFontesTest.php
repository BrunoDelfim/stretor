<?php

namespace Tests\Feature;

use App\Enums\IdiomaFonte;
use App\Services\Torrents\CatalogoProvedores;
use App\Services\Torrents\OrcamentoBusca;
use App\Services\Torrents\TesteDeFontes;
use App\Services\TorrentService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Trava o teste de malha: a peneira que descarta fonte que existe mas não conecta.
 *
 * O bug que originou estes testes: um título aparecia como disponível — o release
 * existia, com legenda anexada —, mas os torrents tinham 0–1 peers e nunca
 * entregaram metadados. O player tentava conectar, não tocava e passava; a busca
 * achava, a malha não sustentava.
 *
 * Como nos demais testes de montagem, o catálogo é um dublê e a única rede
 * tocada é a do media-service — e mesmo ela é falsificada com `Http::fake`.
 */
class LivenessFontesTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * Fonte de torrent já no contrato do frontend.
     *
     * @return array<string, mixed>
     */
    private function fonte(string $id, string $magnet, string $idioma = IdiomaFonte::DUBLADO->value): array
    {
        return [
            'id' => $id,
            'titulo' => 'Contra a Correnteza S01E01 1080p '.$idioma,
            'release' => 'Contra a Correnteza S01E01 1080p '.$idioma,
            'qualidade' => '1080P',
            'idioma' => $idioma,
            'idioma_rotulo' => $idioma,
            'tamanho' => '1,0 GB',
            'seeds' => 5,
            'peers' => 0,
            'magnet' => $magnet,
            'provedor' => 'apibay',
            'provedor_rotulo' => 'APIBay',
            'pack' => false,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $fontes
     */
    private function servicoComFontes(array $fontes): TorrentService
    {
        $catalogo = Mockery::mock(CatalogoProvedores::class);
        $catalogo->shouldReceive('buscar')->andReturn($fontes);
        $catalogo->shouldReceive('buscarDiretasDeAddons')->andReturn([]);
        $catalogo->shouldReceive('buscarFallbackDireto')->andReturn([]);
        $catalogo->shouldReceive('reconciliarCenso')->andReturnNull();
        $catalogo->shouldReceive('fecharOrcamento')->andReturnNull();
        // A segunda fase fica desligada: o que estes testes olham é o teste de
        // malha sobre a lista montada na primeira fase.
        $catalogo->shouldReceive('ptBrSuficiente')->andReturnTrue();
        $catalogo->shouldReceive('temDublado')->andReturnTrue();
        $catalogo->shouldReceive('algumDisponivel')->andReturnTrue();
        $catalogo->shouldReceive('cobertura')->andReturn([]);

        return new TorrentService($catalogo, new OrcamentoBusca(), null, new TesteDeFontes());
    }

    /** O magnet morto é reprovado pela malha; o vivo permanece. */
    public function test_fonte_sem_peer_e_descartada_e_fonte_viva_fica(): void
    {
        $vivo = 'magnet:?xt=urn:btih:'.str_pad('vivo', 40, 'a');
        $morto = 'magnet:?xt=urn:btih:'.str_pad('morto', 40, 'b');

        Http::fake(function (Request $request) use ($morto) {
            if (($request->data()['magnet'] ?? '') === $morto) {
                return Http::response(['ok' => false, 'peers' => 0, 'seeds' => 0, 'velocidade' => 0]);
            }

            return Http::response(['ok' => true, 'peers' => 8, 'seeds' => 8, 'velocidade' => 2048]);
        });

        $servico = $this->servicoComFontes([
            $this->fonte('vivo', $vivo),
            $this->fonte('morto', $morto),
        ]);

        $fontes = $servico->fontes('Contra a Correnteza', 2026, 'tt0000000', '兰香如故', 1, 1);

        $this->assertCount(1, $fontes);
        $this->assertSame('vivo', $fontes[0]['id'], 'Só a fonte viva pode sobreviver à malha.');

        Http::assertSent(fn (Request $request) => $request['espera_ms'] === 6000);
    }

    /** Media-service sem URL configurada: fail-open, a lista passa intacta. */
    public function test_sem_media_service_a_lista_passa_intacta(): void
    {
        config(['services.media_service.url' => '']);

        Http::fake();

        $servico = $this->servicoComFontes([
            $this->fonte('a', 'magnet:?xt=urn:btih:'.str_pad('a', 40, 'a')),
        ]);

        $fontes = $servico->fontes('Contra a Correnteza', 2026, 'tt0000000', '兰香如故', 1, 1);

        $this->assertCount(1, $fontes);
        Http::assertNothingSent();
    }

    /** Resposta de erro do media-service é "não sei": mantém a fonte. */
    public function test_erro_do_media_service_mantem_a_fonte(): void
    {
        Http::fake(fn () => Http::response('erro', 500));

        $servico = $this->servicoComFontes([
            $this->fonte('a', 'magnet:?xt=urn:btih:'.str_pad('a', 40, 'a')),
        ]);

        $fontes = $servico->fontes('Contra a Correnteza', 2026, 'tt0000000', '兰香如故', 1, 1);

        $this->assertCount(1, $fontes, 'Sem veredito definitivo, a fonte não pode ser descartada.');
    }

    /** Desligado, o teste nem toca o media-service. */
    public function test_liveness_desligado_nao_sonda(): void
    {
        config(['services.torrents.liveness_fontes' => false]);

        Http::fake();

        $servico = $this->servicoComFontes([
            $this->fonte('a', 'magnet:?xt=urn:btih:'.str_pad('a', 40, 'a')),
        ]);

        $fontes = $servico->fontes('Contra a Correnteza', 2026, 'tt0000000', '兰香如故', 1, 1);

        $this->assertCount(1, $fontes);
        Http::assertNothingSent();
    }

    /**
     * A busca achou fonte, mas todas morreram na malha: a lista sai vazia e a
     * busca é marcada inconclusiva — não é "sem fonte", é "morto agora".
     */
    public function test_todas_mortas_marca_busca_inconclusiva(): void
    {
        Http::fake(fn () => Http::response(['ok' => false, 'peers' => 0, 'seeds' => 0, 'velocidade' => 0]));

        $servico = $this->servicoComFontes([
            $this->fonte('a', 'magnet:?xt=urn:btih:'.str_pad('a', 40, 'a')),
            $this->fonte('b', 'magnet:?xt=urn:btih:'.str_pad('b', 40, 'b')),
        ]);

        $fontes = $servico->fontes('Contra a Correnteza', 2026, 'tt0000000', '兰香如故', 1, 1);

        $this->assertSame([], $fontes);
        $this->assertTrue($servico->buscaInconclusiva(), 'Fonte achada e morta não é veredito de "sem fonte".');
    }
}
