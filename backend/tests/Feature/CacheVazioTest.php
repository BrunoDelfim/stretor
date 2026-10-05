<?php

namespace Tests\Feature;

use App\Contracts\ProvedorTorrents;
use App\Services\Torrents\CatalogoProvedores;
use App\Services\Torrents\InspecaoPack;
use App\Services\Torrents\OrcamentoBusca;
use App\Services\Torrents\ProvedorAddonStremio;
use App\Services\Torrents\ProvedorApibay;
use App\Services\Torrents\ProvedorBt4g;
use App\Services\Torrents\ProvedorKnaben;
use App\Services\Torrents\ProvedorStreamDireto;
use App\Services\Torrents\ProvedorTorrentio;
use App\Services\Torrents\ProvedorTorznab;
use App\Services\Torrents\ProvedorTrackersBr;
use App\Services\Torrents\ProvedorYts;
use Closure;
use Illuminate\Support\Facades\Cache;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * O resultado vazio do provedor tem prazo curto, e é isso que devolve a chance à
 * tentativa seguinte.
 *
 * O vazio também é cacheado — para não martelar um site que não tem o título — mas
 * com o prazo cheio ele trancava a porta por meia hora quando o vazio era culpa do
 * caminho. No fallback de stream direto, um blip de rede, uma página sem resposta
 * ou o orçamento consumido por uma consulta lenta devolvem "nenhuma fonte" sem que
 * isso diga nada sobre o acervo: era esse o sintoma do episódio que voltava vazio
 * mesmo depois de fechar e reabrir o player.
 */
class CacheVazioTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * Dublê de um provedor do registro, só com o que o censo lê no construtor.
     */
    private function duble(string $classe, string $id): mixed
    {
        $duble = Mockery::mock($classe);
        $duble->shouldReceive('identificador')->andReturn($id);
        $duble->shouldReceive('rotulo')->andReturn($id);
        $duble->shouldReceive('disponivel')->andReturn(true);

        return $duble;
    }

    /**
     * Monta o catálogo com todos os provedores substituídos por dublês. Nenhum
     * deles é consultado: os testes chamam o método de cache por reflexão.
     */
    private function catalogo(): CatalogoProvedores
    {
        return new CatalogoProvedores(
            $this->duble(ProvedorTrackersBr::class, 'trackers-br'),
            $this->duble(ProvedorApibay::class, 'apibay'),
            $this->duble(ProvedorKnaben::class, 'knaben'),
            $this->duble(ProvedorTorrentio::class, 'torrentio'),
            $this->duble(ProvedorAddonStremio::class, 'addon-stremio'),
            $this->duble(ProvedorBt4g::class, 'bt4g'),
            $this->duble(ProvedorTorznab::class, 'torznab'),
            $this->duble(ProvedorYts::class, 'yts'),
            Mockery::mock(InspecaoPack::class),
            new OrcamentoBusca(),
            $this->duble(ProvedorStreamDireto::class, 'stream_direto'),
        );
    }

    /**
     * Encapsula a chamada de um método privado do catálogo.
     */
    private function invocador(CatalogoProvedores $catalogo, string $metodo): Closure
    {
        $reflexao = new ReflectionMethod($catalogo, $metodo);
        $reflexao->setAccessible(true);

        return static fn (...$argumentos) => $reflexao->invoke($catalogo, ...$argumentos);
    }

    /**
     * Provedor de termo com contador de consultas reais e resposta configurável.
     *
     * @param  array<int, array<string, mixed>>  $resposta
     */
    private function provedorContado(array $resposta): ProvedorTorrents
    {
        return new class($resposta) implements ProvedorTorrents
        {
            public int $chamadas = 0;

            /** @param  array<int, array<string, mixed>>  $resposta */
            public function __construct(private readonly array $resposta)
            {
            }

            public function identificador(): string
            {
                return 'torrentio';
            }

            public function rotulo(): string
            {
                return 'Torrentio';
            }

            public function disponivel(): bool
            {
                return true;
            }

            public function buscar(
                string $titulo,
                ?int $ano = null,
                ?string $imdbId = null,
                ?int $temporada = null,
                ?int $episodio = null,
            ): array {
                $this->chamadas++;

                return $this->resposta;
            }
        };
    }

    private function configurarPrazos(): void
    {
        /*
         * O store `array` entra no lugar do padrão (Redis) porque é o único que
         * expira pelo relógio do `Carbon::now()`: sem ele a viagem no tempo não
         * vence nada, e o cache segue lendo o que foi gravado. O que está em teste
         * é o prazo gravado, e ele é o mesmo nos dois stores.
         */
        config(['cache.default' => 'array']);

        Cache::flush();

        config([
            'services.torrents.cache_bypass' => false,
            'services.torrents.cache_ttl' => 1800,
            'services.torrents.cache_ttl_vazio' => 120,
        ]);
    }

    public function test_resultado_vazio_volta_a_ser_consultado_depois_do_prazo_curto(): void
    {
        $this->configurarPrazos();

        $catalogo = $this->catalogo();
        $provedor = $this->provedorContado([]);
        $buscar = $this->invocador($catalogo, 'buscarComCache');

        $buscar($provedor, 'American Horror Story', 2011, 'tt1234567', 1, 2);

        // Três minutos depois, o prazo curto venceu e o provedor é reconsultado.
        $this->travel(3)->minutes();

        $buscar($provedor, 'American Horror Story', 2011, 'tt1234567', 1, 2);

        $this->assertSame(2, $provedor->chamadas, 'O vazio não pode durar o TTL cheio.');
    }

    public function test_resultado_com_fonte_segue_o_prazo_cheio(): void
    {
        $this->configurarPrazos();

        $catalogo = $this->catalogo();
        $provedor = $this->provedorContado([['id' => 'hash-1', 'titulo' => 'Release']]);
        $buscar = $this->invocador($catalogo, 'buscarComCache');

        $buscar($provedor, 'American Horror Story', 2011, 'tt1234567', 1, 2);
        $this->travel(3)->minutes();
        $buscar($provedor, 'American Horror Story', 2011, 'tt1234567', 1, 2);

        $this->assertSame(1, $provedor->chamadas, 'Fonte encontrada continua no cache pelo TTL cheio.');
    }

    /**
     * A leitura do vazio não pode esticar o prazo.
     *
     * O `remember()` antigo reescrevia o TTL a cada consulta — e o player consulta
     * de novo a cada abertura. Com ele, três idas e voltas ao episódio manteriam o
     * "nenhuma fonte" vivo indefinidamente.
     */
    public function test_leitura_do_vazio_nao_renova_o_prazo_curto(): void
    {
        $this->configurarPrazos();

        $catalogo = $this->catalogo();
        $provedor = $this->provedorContado([]);
        $buscar = $this->invocador($catalogo, 'buscarComCache');

        $buscar($provedor, 'American Horror Story', 2011, 'tt1234567', 1, 2);

        $this->travel(1)->minutes();
        $buscar($provedor, 'American Horror Story', 2011, 'tt1234567', 1, 2);

        $this->travel(2)->minutes();
        $buscar($provedor, 'American Horror Story', 2011, 'tt1234567', 1, 2);

        $this->assertSame(2, $provedor->chamadas, 'A leitura intermediária não pode renovar o prazo.');
    }
}
