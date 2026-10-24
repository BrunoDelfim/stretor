<?php

namespace Tests\Feature;

use App\Enums\IdiomaFonte;
use App\Enums\TipoMidia;
use App\Models\FonteReproduzida;
use App\Services\FontesReproduzidas;
use App\Services\TorrentService;
use App\Services\Torrents\CatalogoProvedores;
use App\Services\Torrents\OrcamentoBusca;
use App\Services\Torrents\TesteDeFontes;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\Concerns\UsaBancoDeTeste;
use Tests\TestCase;

/**
 * Trava o ciclo da fonte comprovada: o relato do player e o que a busca faz com ele.
 *
 * A prova que este ciclo carrega é diferente da do teste de malha. A malha mede se
 * a fonte *deve* conectar; o relato diz que ela **serviu o título até o fim**. O
 * que se ganha com isso é o tempo de quem assiste: o tentativa-e-erro da busca
 * (cascata, peneira de malha, conexão no media-service) não é pago duas vezes no
 * mesmo título.
 *
 * O contrato fixado aqui: o relato é gravado com a chave certa (título + episódio),
 * repetir a mesma fonte reforça em vez de duplicar, histórico malformado é
 * recusado, a comprovada vem na frente da fila e — o mais importante — se ela
 * morreu desde então, cai como qualquer outra e o resto da lista segue.
 *
 * Como nos testes de montagem, o catálogo é um dublê e a única rede tocada é a do
 * media-service, falsificada com `Http::fake`.
 */
class FontesReproduzidasTest extends TestCase
{
    use RefreshDatabase;
    use UsaBancoDeTeste;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /** Hash estável no formato que o contrato exige (infohash de 40 caracteres). */
    private function hash(string $semente): string
    {
        return str_pad(substr(md5($semente), 0, 40), 40, 'a');
    }

    private function magnet(string $semente): string
    {
        return 'magnet:?xt=urn:btih:'.$this->hash($semente);
    }

    /**
     * Fonte já no contrato do frontend, como a busca devolve.
     *
     * @return array<string, mixed>
     */
    private function fonte(string $id, string $magnet): array
    {
        return [
            'id' => $id,
            'tipo' => 'torrent',
            'titulo' => 'Clube da Luta 1999 1080p Dublado',
            'qualidade' => '1080P',
            'idioma' => IdiomaFonte::DUBLADO->value,
            'idioma_rotulo' => 'Dublado',
            'tamanho' => '1,4 GB',
            'seeds' => 9,
            'peers' => 2,
            'magnet' => $magnet,
            'stream' => '',
            'provedor' => 'apibay',
            'provedor_rotulo' => 'APIBay',
            'legendas' => [],
        ];
    }

    /**
     * Serviço de busca montado sobre um catálogo dublê, com o histórico real.
     *
     * @param  array<int, array<string, mixed>>  $fontes  O que a cascata devolve.
     */
    private function servicoComFontes(array $fontes, ?TesteDeFontes $teste = null): TorrentService
    {
        $catalogo = Mockery::mock(CatalogoProvedores::class);
        $catalogo->shouldReceive('buscar')->andReturn($fontes);
        $catalogo->shouldReceive('buscarDiretasDeAddons')->andReturn([]);
        $catalogo->shouldReceive('buscarFallbackDireto')->andReturn([]);
        $catalogo->shouldReceive('reconciliarCenso')->andReturnNull();
        $catalogo->shouldReceive('fecharOrcamento')->andReturnNull();
        // A segunda fase fica desligada: o que estes testes olham é a ordem da
        // lista montada na primeira fase.
        $catalogo->shouldReceive('ptBrSuficiente')->andReturnTrue();
        $catalogo->shouldReceive('temDublado')->andReturnTrue();
        $catalogo->shouldReceive('algumDisponivel')->andReturnTrue();
        $catalogo->shouldReceive('cobertura')->andReturn([]);

        return new TorrentService($catalogo, new OrcamentoBusca(), null, $teste, app(FontesReproduzidas::class));
    }

    /** Grava uma comprovada direto no histórico, com uso e data controlados. */
    private function comprovada(string $semente, int $reproducoes, DateTimeInterface $quando): void
    {
        FonteReproduzida::create([
            'tipo' => TipoMidia::FILME->value,
            'tmdb_id' => 550,
            'fonte_id' => $this->hash($semente),
            'tipo_fonte' => 'torrent',
            'titulo' => 'Clube da Luta 1999 1080p Dublado',
            'idioma' => 'pt-BR',
            'magnet' => $this->magnet($semente),
            'reproducoes' => $reproducoes,
            'confirmada_em' => $quando,
        ]);
    }

    /** O relato do player vira linha no histórico, com a chave do título. */
    public function test_relato_do_player_grava_a_fonte_comprovada(): void
    {
        $hash = $this->hash('comprovada');

        $this->postJson('/api/v1/movies/550/fontes/reproduzida', [
            'fonte_id' => $hash,
            'tipo' => 'torrent',
            'titulo' => 'Clube da Luta 1999 1080p Dublado',
            'qualidade' => '1080P',
            'tamanho' => '1,4 GB',
            'idioma' => IdiomaFonte::DUBLADO->value,
            'provedor' => 'apibay',
            'provedor_rotulo' => 'APIBay',
            'seeds' => 9,
            'peers' => 2,
            'magnet' => 'magnet:?xt=urn:btih:'.$hash,
            'legendas' => [],
        ])
            ->assertOk()
            ->assertJsonPath('data.registrada', true);

        $this->assertDatabaseHas('fontes_reproduzidas', [
            'tipo' => 'filme',
            'tmdb_id' => 550,
            'temporada' => 0,
            'episodio' => 0,
            'fonte_id' => $hash,
            'tipo_fonte' => 'torrent',
            'idioma' => IdiomaFonte::DUBLADO->value,
            'reproducoes' => 1,
        ]);
    }

    /**
     * Episódio é gravado com a numeração — é ela que faz a comprovada ser achada
     * de volta na abertura do mesmo episódio, e não de outro.
     */
    public function test_episodio_e_gravado_com_a_numeracao(): void
    {
        $hash = $this->hash('episodio');

        $this->postJson('/api/v1/movies/1399/fontes/reproduzida', [
            'fonte_id' => $hash,
            'tipo' => 'torrent',
            'titulo' => 'Contra a Correnteza S02E05 1080p Dublado',
            'idioma' => IdiomaFonte::DUBLADO->value,
            'magnet' => 'magnet:?xt=urn:btih:'.$hash,
            'temporada' => 2,
            'episodio' => 5,
        ])->assertOk();

        $this->assertDatabaseHas('fontes_reproduzidas', [
            'tipo' => 'serie',
            'tmdb_id' => 1399,
            'temporada' => 2,
            'episodio' => 5,
        ]);
    }

    /** A mesma fonte vista de novo reforça a linha: é o contador que dá a ordem. */
    public function test_repetir_a_fonte_soma_reproducao_em_vez_de_duplicar(): void
    {
        $hash = $this->hash('repetida');
        $relato = [
            'fonte_id' => $hash,
            'tipo' => 'torrent',
            'titulo' => 'Clube da Luta 1999 1080p Dublado',
            'idioma' => IdiomaFonte::DUBLADO->value,
            'magnet' => 'magnet:?xt=urn:btih:'.$hash,
        ];

        $this->postJson('/api/v1/movies/550/fontes/reproduzida', $relato)->assertOk();
        $this->postJson('/api/v1/movies/550/fontes/reproduzida', $relato)->assertOk();

        $this->assertSame(1, FonteReproduzida::count(), 'A mesma fonte não pode virar duas linhas.');
        $this->assertSame(2, FonteReproduzida::first()->reproducoes);
    }

    /**
     * Histórico malformado é recusado na porta.
     *
     * Uma linha inválida não é inofensiva: ela iria para a **frente** da lista (é
     * o que o histórico faz) e atrasaria a reprodução de quem abriu o título,
     * porque uma fonte que não conecta não entrega nem o primeiro trecho.
     */
    public function test_relato_fora_de_forma_e_recusado(): void
    {
        // Torrent sem magnet: o media-service não teria o que abrir.
        $this->postJson('/api/v1/movies/550/fontes/reproduzida', [
            'fonte_id' => $this->hash('sem-magnet'),
            'tipo' => 'torrent',
        ])->assertStatus(422)->assertJsonValidationErrors('magnet');

        // Magnet que não é magnet.
        $this->postJson('/api/v1/movies/550/fontes/reproduzida', [
            'fonte_id' => $this->hash('magnet-falso'),
            'tipo' => 'torrent',
            'magnet' => 'https://exemplo.test/nao-e-magnet',
        ])->assertStatus(422)->assertJsonValidationErrors('magnet');

        // Fonte direta com endereço que não é URL tocável.
        $this->postJson('/api/v1/movies/550/fontes/reproduzida', [
            'fonte_id' => $this->hash('stream-falso'),
            'tipo' => 'direto',
            'stream' => 'nao-e-url',
        ])->assertStatus(422)->assertJsonValidationErrors('stream');

        // Idioma fora do vocabulário do sistema.
        $this->postJson('/api/v1/movies/550/fontes/reproduzida', [
            'fonte_id' => $this->hash('idioma-falso'),
            'tipo' => 'torrent',
            'magnet' => $this->magnet('idioma-falso'),
            'idioma' => 'klingon',
        ])->assertStatus(422)->assertJsonValidationErrors('idioma');

        // Episódio sem temporada: a numeração é um par, e um lado só não
        // identifica o episódio.
        $this->postJson('/api/v1/movies/550/fontes/reproduzida', [
            'fonte_id' => $this->hash('episodio-solto'),
            'tipo' => 'torrent',
            'magnet' => $this->magnet('episodio-solto'),
            'episodio' => 3,
        ])->assertStatus(422)->assertJsonValidationErrors('temporada');

        $this->assertSame(0, FonteReproduzida::count(), 'Nenhum relato inválido pode virar histórico.');
    }

    /**
     * A comprovada vem na frente — e a cópia que a busca achou de novo não se
     * repete na lista.
     */
    public function test_fonte_comprovada_vem_na_frente_da_fila(): void
    {
        $comprovada = $this->hash('salgada');

        app(FontesReproduzidas::class)->registrar(
            [
                'fonte_id' => $comprovada,
                'tipo' => 'torrent',
                'magnet' => 'magnet:?xt=urn:btih:'.$comprovada,
                'idioma' => IdiomaFonte::DUBLADO->value,
            ],
            TipoMidia::FILME,
            550,
        );

        $servico = $this->servicoComFontes([
            $this->fonte($this->hash('nova'), $this->magnet('nova')),
            $this->fonte($comprovada, $this->magnet('salgada')),
        ]);

        $fontes = $servico->fontes('Clube da Luta', 1999, 'tt0137523', null, null, null, '550');

        $this->assertCount(2, $fontes, 'A fonte que voltou da busca não pode aparecer duas vezes.');
        $this->assertSame($comprovada, $fontes[0]['id'], 'A comprovada tem de estar na frente.');
    }

    /**
     * A comprovada não é um caminho à parte: ela passa pela malha como as demais.
     *
     * Sem isso, um histórico confiável viraria o único caminho oferecido — e uma
     * fonte que morreu desde a última sessão seguraria a reprodução até o usuário
     * desistir. Medida e reprovada, ela cai e o resto da lista segue.
     */
    public function test_comprovada_que_morreu_cai_e_o_resto_da_lista_segue(): void
    {
        $morta = $this->hash('morta');
        $viva = $this->hash('viva');

        app(FontesReproduzidas::class)->registrar(
            [
                'fonte_id' => $morta,
                'tipo' => 'torrent',
                'magnet' => 'magnet:?xt=urn:btih:'.$morta,
                'idioma' => IdiomaFonte::DUBLADO->value,
            ],
            TipoMidia::FILME,
            550,
        );

        Http::fake(fn (Request $requisicao) => ($requisicao->data()['magnet'] ?? '') === 'magnet:?xt=urn:btih:'.$morta
            ? Http::response(['ok' => false, 'peers' => 0, 'seeds' => 0, 'velocidade' => 0])
            : Http::response(['ok' => true, 'peers' => 7, 'seeds' => 7, 'velocidade' => 1024]));

        $servico = $this->servicoComFontes([$this->fonte($viva, $this->magnet('viva'))], new TesteDeFontes());

        $fontes = $servico->fontes('Clube da Luta', 1999, 'tt0137523', null, null, null, '550');

        $this->assertSame([$viva], array_column($fontes, 'id'), 'A comprovada morta sai; a descoberta de agora fica.');
    }

    /**
     * O histórico tem teto, e quem sai é a fonte que serviu menos vezes — empatada,
     * a que tocou há mais tempo. Sem a poda, um título aberto muitas vezes
     * acumularia linhas e a busca gastaria a sondagem de malha com histórico velho.
     */
    public function test_teto_do_historico_poda_as_menos_usadas(): void
    {
        $this->comprovada('antiga-e-muito-usada', reproducoes: 5, quando: now()->subDays(3));
        $this->comprovada('antiga', reproducoes: 1, quando: now()->subDays(2));
        $this->comprovada('recente', reproducoes: 1, quando: now()->subDay());

        app(FontesReproduzidas::class)->registrar(
            [
                'fonte_id' => $this->hash('nova'),
                'tipo' => 'torrent',
                'magnet' => $this->magnet('nova'),
                'idioma' => IdiomaFonte::DUBLADO->value,
            ],
            TipoMidia::FILME,
            550,
        );

        $sobreviventes = FonteReproduzida::query()->pluck('fonte_id')->all();

        $this->assertCount(3, $sobreviventes, 'O teto do histórico é o mesmo do que a busca lembra.');
        $this->assertNotContains($this->hash('antiga'), $sobreviventes, 'Sai a menos usada mais antiga.');
        $this->assertContains($this->hash('antiga-e-muito-usada'), $sobreviventes, 'A mais usada fica, mesmo antiga.');
    }
}
