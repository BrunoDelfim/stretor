<?php

namespace Tests\Feature;

use App\Contracts\ProvedorPorLote;
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
 * Trava a regressão do bypass de cache das consultas externas.
 *
 * O cache das consultas por provedor é o que mantém o /fontes barato, mas ele
 * também prende um resultado limitado por até um TTL inteiro: a lista de fontes
 * de uma série antiga pode ter sido montada quando os termos de busca eram
 * restritos e continuar sendo servida depois da correção. Com
 * `TORRENTS_CACHE_BYPASS=true`, a leitura do cache é ignorada nas consultas
 * externas, o provedor é reconsultado e o resultado novo grava por cima.
 *
 * Estes testes exercitam os dois caminhos de cache do catálogo — termo a termo
 * (`buscarComCache`) e em lote (`buscarLoteComCache`) — com um provedor dublê
 * que conta quantas vezes foi consultado de fato.
 */
class CacheBypassTest extends TestCase
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
     * deles é consultado: os testes chamam os métodos de cache por reflexão.
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
     * Encapsula a chamada a um método privado do catálogo.
     */
    private function invocador(CatalogoProvedores $catalogo, string $metodo): Closure
    {
        $reflexao = new ReflectionMethod($catalogo, $metodo);
        $reflexao->setAccessible(true);

        return static fn (...$argumentos) => $reflexao->invoke($catalogo, ...$argumentos);
    }

    /**
     * Provedor de termo com contador de consultas reais.
     *
     * O `identificador()` devolve "torrentio" de propósito: é um dos ids já
     * presentes no censo, para que a contabilização não crie uma chave nova.
     */
    private function provedorContado(): ProvedorTorrents
    {
        return new class implements ProvedorTorrents {
            public int $chamadas = 0;

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

                return [['id' => 'hash-'.$this->chamadas, 'titulo' => 'Release '.$this->chamadas]];
            }
        };
    }

    /**
     * Provedor de lote com contador de rodadas reais.
     */
    private function provedorDeLote(): ProvedorTorrents
    {
        return new class implements ProvedorTorrents, ProvedorPorLote {
            public int $lotes = 0;

            public function identificador(): string
            {
                return 'knaben';
            }

            public function rotulo(): string
            {
                return 'Knaben';
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
                $this->lotes++;

                return [['id' => 'termo-'.$this->lotes, 'titulo' => 'Termo '.$this->lotes]];
            }

            public function buscarVarios(
                array $termos,
                ?int $ano = null,
                ?string $imdbId = null,
                ?int $temporada = null,
                ?int $episodio = null,
            ): array {
                $this->lotes++;

                return [['id' => 'lote-'.$this->lotes, 'titulo' => 'Lote '.$this->lotes]];
            }
        };
    }

    public function test_leitura_repetida_vem_do_cache(): void
    {
        Cache::flush();
        config(['services.torrents.cache_bypass' => false, 'services.torrents.cache_ttl' => 1800]);

        $catalogo = $this->catalogo();
        $provedor = $this->provedorContado();
        $buscar = $this->invocador($catalogo, 'buscarComCache');

        $primeira = $buscar($provedor, 'American Horror Story', 2011, 'tt1234567', 1, 1);
        $segunda = $buscar($provedor, 'American Horror Story', 2011, 'tt1234567', 1, 1);

        $this->assertSame(1, $provedor->chamadas, 'A segunda leitura precisa vir do cache.');
        $this->assertSame($primeira, $segunda);

        $linha = collect($catalogo->cobertura())->firstWhere('provedor', 'torrentio');
        $this->assertSame(2, $linha['consultas']);
        $this->assertSame(1, $linha['do_cache'], 'Só a segunda leitura veio do cache.');
    }

    public function test_bypass_ignora_a_leitura_e_regrava_o_resultado(): void
    {
        Cache::flush();
        config(['services.torrents.cache_bypass' => false, 'services.torrents.cache_ttl' => 1800]);

        $catalogo = $this->catalogo();
        $provedor = $this->provedorContado();
        $buscar = $this->invocador($catalogo, 'buscarComCache');

        // Primeira busca: consulta o provedor e grava.
        $buscar($provedor, 'American Horror Story', 2011, 'tt1234567', 1, 1);
        $this->assertSame(1, $provedor->chamadas);

        // Com o bypass ligado, a leitura é ignorada e o provedor é reconsultado.
        config(['services.torrents.cache_bypass' => true]);
        $nova = $buscar($provedor, 'American Horror Story', 2011, 'tt1234567', 1, 1);

        $this->assertSame(2, $provedor->chamadas, 'O bypass precisa forçar a reconsulta.');
        $this->assertSame('hash-2', $nova[0]['id'], 'O resultado novo grava por cima do antigo.');

        // Desligado o bypass, a próxima leitura já enxerga o resultado regravado.
        config(['services.torrents.cache_bypass' => false]);
        $terceira = $buscar($provedor, 'American Horror Story', 2011, 'tt1234567', 1, 1);

        $this->assertSame(2, $provedor->chamadas, 'Sem bypass, volta a ler do cache.');
        $this->assertSame('hash-2', $terceira[0]['id']);
    }

    public function test_bypass_vale_tambem_para_o_lote(): void
    {
        Cache::flush();
        config(['services.torrents.cache_bypass' => false, 'services.torrents.cache_ttl' => 1800]);

        $catalogo = $this->catalogo();
        $provedor = $this->provedorDeLote();
        $buscar = $this->invocador($catalogo, 'buscarLoteComCache');

        $termos = ['American Horror Story S01', 'American Horror Story temporada 1'];

        $buscar($provedor, $termos, 2011, 'tt1234567', 1, 1);
        $buscar($provedor, $termos, 2011, 'tt1234567', 1, 1);

        $this->assertSame(1, $provedor->lotes, 'A segunda rodada do mesmo lote vem do cache.');

        config(['services.torrents.cache_bypass' => true]);
        $nova = $buscar($provedor, $termos, 2011, 'tt1234567', 1, 1);

        $this->assertSame(2, $provedor->lotes, 'O bypass precisa reconsultar o lote.');
        $this->assertSame('lote-2', $nova[0]['id']);
    }
}
