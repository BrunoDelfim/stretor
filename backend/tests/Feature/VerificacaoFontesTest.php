<?php

namespace Tests\Feature;

use App\Enums\OrigemVerificacao;
use App\Enums\StatusVerificacao;
use App\Enums\TipoMidia;
use App\Models\Disponibilidade;
use App\Models\VerificacaoHome;
use App\Services\TmdbService;
use App\Services\TorrentService;
use App\Services\Verificacao\CatalogoVerificado;
use App\Services\Verificacao\VerificadorFontes;
use App\Services\Verificacao\VerificadorHome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * Trava a rotina de verificação de fontes sem tocar em rede.
 *
 * O TMDB e a cascata de torrents são substituídos por dublês. O banco é um
 * **banco de teste isolado** (`stretor_testing`, no mesmo servidor), porque o
 * `RefreshDatabase` roda `migrate:fresh` — e esse comando não pode encostar no
 * banco de desenvolvimento, que carrega os dados reais da aplicação. A troca de
 * banco acontece em `refreshApplication()`, depois de o app existir (para o
 * `config()` valer) e antes de `setUpTraits()` migrar. O banco é criado uma única
 * vez em `setUpBeforeClass()`, então `php artisan test` funciona sem passo manual.
 *
 * O contrato fixado aqui: título já resolvido não é re-testado, a página só vira
 * `ok` quando é processada por inteiro, e a Home serve a lista verificada quando
 * ela existe. Os dois últimos casos provam que a unidade que decide "tem fonte"
 * pergunta à **mesma** `TorrentService::fontes()` que o playback usa — e com o
 * mesmo recorte (temporada/episódio só na série).
 */
class VerificacaoFontesTest extends TestCase
{
    use RefreshDatabase;

    /** Banco dos testes — nunca o de desenvolvimento. */
    private const BANCO_TESTE = 'stretor_testing';

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::garantirBancoDeTeste();
    }

    /**
     * Cria o banco de teste ao lado do de desenvolvimento, se ainda não existir.
     *
     * Conecta ao banco principal só para o `CREATE DATABASE`; a conexão de
     * trabalho é trocada em seguida. O código `42P04` é o "banco já existe" do
     * Postgres — tratado como sucesso, já que o banco é reutilizado nas execuções
     * seguintes.
     */
    private static function garantirBancoDeTeste(): void
    {
        $host = $_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? 'postgres';
        $port = $_ENV['DB_PORT'] ?? $_SERVER['DB_PORT'] ?? '5432';
        $usuario = $_ENV['DB_USERNAME'] ?? $_SERVER['DB_USERNAME'] ?? 'stretor';
        $senha = $_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'] ?? 'secret';
        $principal = $_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? 'stretor';

        try {
            $pdo = new PDO("pgsql:host={$host};port={$port};dbname={$principal}", $usuario, $senha);
            $pdo->exec(sprintf('CREATE DATABASE %s OWNER %s', self::BANCO_TESTE, $usuario));
        } catch (PDOException $erro) {
            if ($erro->getCode() !== '42P04') {
                throw $erro;
            }
        }
    }

    /**
     * Aponta a conexão para o banco de teste antes de a suíte migrar.
     *
     * O compose injeta `DB_*` no ambiente do processo e o repositório de `env` lê
     * `$_SERVER` primeiro — por isso a troca é feita por `config()`, e não por
     * `.env.testing`, que seria ignorado.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        config(['database.connections.pgsql.database' => self::BANCO_TESTE]);
    }

    /**
     * Uma página de tendências do TMDB, no formato que o serviço devolve.
     *
     * @param  array<int, array<string, mixed>>  $itens
     */
    private function tmdbComPagina(array $itens, int $totalPaginas = 25): TmdbService
    {
        $mock = Mockery::mock(TmdbService::class);
        $mock->shouldReceive('tendenciasDoDia')->andReturn([
            'resultados' => $itens,
            'pagina' => 1,
            'total_paginas' => $totalPaginas,
        ]);

        return $mock;
    }

    /**
     * Dublê do verificador que grava o veredito como o real faria e anota os ids
     * testados, para provar **quem** foi re-testado e quem foi poupado.
     *
     * @param  array<int, bool>  $vereditos  id do TMDB => tem fonte.
     * @param  array<int, int>  $chamados  Acumula os ids efetivamente testados.
     */
    private function verificadorFalso(array $vereditos, array &$chamados): VerificadorFontes
    {
        $mock = Mockery::mock(VerificadorFontes::class);
        $mock->shouldReceive('verificar')->andReturnUsing(
            function (array $item, OrigemVerificacao $origem) use ($vereditos, &$chamados): Disponibilidade {
                $id = (int) $item['id'];
                $chamados[] = $id;
                $temFonte = $vereditos[$id] ?? false;

                return Disponibilidade::updateOrCreate(
                    ['tipo' => TipoMidia::doTmdb($item['tipo'])->value, 'tmdb_id' => $id],
                    [
                        'tem_fonte' => $temFonte,
                        'fonte_idioma' => $temFonte ? 'pt-BR' : null,
                        'fonte_tipo' => $temFonte ? 'torrent' : null,
                        'origem' => $origem->value,
                        'verificado_em' => now(),
                    ],
                );
            }
        );

        return $mock;
    }

    /** @return array<string, mixed> */
    private function item(int $id, string $tipo, string $titulo): array
    {
        return ['id' => $id, 'tipo' => $tipo, 'titulo' => $titulo, 'ano' => 2024, 'titulo_original' => $titulo];
    }

    private function disponibilidade(int $id, bool $temFonte, ?string $horasAtras = null): void
    {
        Disponibilidade::create([
            'tipo' => TipoMidia::FILME->value,
            'tmdb_id' => $id,
            'tem_fonte' => $temFonte,
            'fonte_idioma' => $temFonte ? 'pt-BR' : null,
            'origem' => OrigemVerificacao::COMANDO->value,
            'verificado_em' => $horasAtras === null ? now() : now()->subHours((int) $horasAtras),
        ]);
    }

    /**
     * A página inteira vai para o banco com os itens que têm fonte, na ordem do
     * TMDB, e o título já resolvido antes não é re-testado.
     */
    public function test_pagina_completa_grava_ok_com_apenas_itens_com_fonte(): void
    {
        $itens = [
            $this->item(101, 'movie', 'Filme A'),
            $this->item(202, 'tv', 'Série B'),
            $this->item(303, 'movie', 'Filme C'),
        ];

        // O 101 já tinha fonte provada de uma rodada anterior.
        $this->disponibilidade(101, true);

        $chamados = [];
        $verificador = $this->verificadorFalso([202 => true, 303 => false], $chamados);

        $execucao = (new VerificadorHome($this->tmdbComPagina($itens), $verificador))
            ->executar(limitePaginas: 1, pausas: false);

        $this->assertSame([202, 303], $chamados, 'O título que já tinha fonte não pode ser re-testado.');

        $pagina = VerificacaoHome::find(1);
        $this->assertNotNull($pagina);
        $this->assertSame(StatusVerificacao::OK, $pagina->status);
        $this->assertSame([101, 202], array_column($pagina->itens, 'id'), 'Apenas os itens com fonte entram, na ordem do TMDB.');

        $this->assertSame(1, $execucao->paginas_processadas);
        $this->assertSame(2, $execucao->titulos_testados);
        $this->assertSame(1, $execucao->titulos_com_fonte);
    }

    /**
     * O teto de títulos não pode fechar uma página pela metade: uma página
     * parcialmente testada que virasse `ok` esconderia da Home o que não foi
     * verificado.
     */
    public function test_teto_de_titulos_nao_marca_pagina_ok(): void
    {
        $itens = [
            $this->item(1, 'movie', 'Um'),
            $this->item(2, 'movie', 'Dois'),
            $this->item(3, 'movie', 'Três'),
        ];

        $chamados = [];
        $verificador = $this->verificadorFalso([1 => true, 2 => true, 3 => true], $chamados);

        (new VerificadorHome($this->tmdbComPagina($itens), $verificador))
            ->executar(limitePaginas: 1, limiteTitulos: 1, pausas: false);

        $this->assertSame([1], $chamados);
        $this->assertNull(VerificacaoHome::find(1), 'Página parcial não pode ser gravada como pronta.');
    }

    /** Página vazia é fim de catálogo: encerra sem testar nem gravar nada. */
    public function test_pagina_vazia_encerra_sem_gravar(): void
    {
        $verificador = Mockery::mock(VerificadorFontes::class);
        $verificador->shouldNotReceive('verificar');

        (new VerificadorHome($this->tmdbComPagina([]), $verificador))
            ->executar(limitePaginas: 25, pausas: false);

        $this->assertSame(0, VerificacaoHome::count());
    }

    /**
     * A prova válida poupa o título; a vencida o devolve à fila. É o que separa
     * "já resolvido" de "precisa tentar de novo".
     */
    public function test_prova_valida_poupa_e_prova_vencida_volta_a_fila(): void
    {
        $itens = [$this->item(1, 'movie', 'Válida'), $this->item(2, 'movie', 'Vencida')];

        $this->disponibilidade(1, true);          // verificada agora
        $this->disponibilidade(2, false, '48');   // sem fonte, vencida há muito

        $chamados = [];
        $verificador = $this->verificadorFalso([2 => true], $chamados);

        (new VerificadorHome($this->tmdbComPagina($itens), $verificador))
            ->executar(limitePaginas: 1, pausas: false);

        $this->assertSame([2], $chamados, 'Só o título com prova vencida volta à fila.');
    }

    /** Página `ok` no banco vira a resposta da Home, no formato do TMDB. */
    public function test_catalogo_verificado_serve_pagina_pronta(): void
    {
        VerificacaoHome::create([
            'pagina' => 1,
            'status' => StatusVerificacao::OK->value,
            'itens' => [$this->item(1, 'movie', 'A'), $this->item(2, 'movie', 'B')],
            'total_paginas' => 25,
            'itens_tmdb' => 20,
            'verificada_em' => now(),
        ]);

        $resultado = (new CatalogoVerificado())->itensDaPagina(1);

        $this->assertNotNull($resultado);
        $this->assertCount(2, $resultado['resultados']);
        $this->assertSame(1, $resultado['pagina']);
        $this->assertSame(25, $resultado['total_paginas']);
    }

    /** Página ainda não pronta devolve `null` — o controller cai no TMDB cru. */
    public function test_catalogo_verificado_devolve_nulo_para_pagina_pendente(): void
    {
        $this->assertNull((new CatalogoVerificado())->itensDaPagina(1));
    }

    /** Com a verificação desligada, a Home volta ao comportamento antigo. */
    public function test_catalogo_verificado_respeita_desligamento(): void
    {
        VerificacaoHome::create([
            'pagina' => 1,
            'status' => StatusVerificacao::OK->value,
            'itens' => [$this->item(1, 'movie', 'A')],
            'total_paginas' => 25,
            'itens_tmdb' => 20,
            'verificada_em' => now(),
        ]);

        config(['services.verificacao.habilitada' => false]);

        $this->assertNull((new CatalogoVerificado())->itensDaPagina(1));
    }

    /**
     * O contrato de ponta a ponta: com a página verificada, a Home serve a lista
     * do banco e avisa no `meta` que ela passou pelo portão — sem tocar no TMDB.
     */
    public function test_home_serve_lista_verificada_quando_disponivel(): void
    {
        VerificacaoHome::create([
            'pagina' => 1,
            'status' => StatusVerificacao::OK->value,
            'itens' => [$this->item(1, 'movie', 'A'), $this->item(2, 'tv', 'B')],
            'total_paginas' => 25,
            'itens_tmdb' => 20,
            'verificada_em' => now(),
        ]);

        $this->getJson('/api/v1/movies/trending?page=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.verificada', true)
            ->assertJsonPath('meta.total_pages', 25);
    }

    /**
     * A verificação pergunta à **mesma** `TorrentService::fontes()` que o playback
     * usa — não existe um caminho paralelo de "tem fonte?".
     *
     * A série é sondada por um episódio concreto (S01E01), exatamente como o
     * frontend pede ao abrir o episódio: sem temporada e episódio o stream direto
     * não monta a rota do vídeo, o acervo web some da resposta e a série nascia
     * "sem fonte" mesmo estando no ar. Se o verificador deixasse de delegar a
     * `fontes()`, o dublê não teria chamada casada e este teste quebraria.
     */
    public function test_serie_e_sonada_com_temporada_e_episodio_como_no_playback(): void
    {
        $torrents = Mockery::mock(TorrentService::class);
        $torrents->shouldReceive('fontes')
            ->once()
            ->withArgs(function (...$args): bool {
                // O dublê recebe os argumentos na ordem declarada de `fontes()`.
                [$titulo, $ano, $imdbId, $tituloOriginal, $temporada, $episodio, $tmdbId] = array_values($args);

                return $titulo === 'Carrie, a Estranha'
                    && $ano === 2026
                    && $imdbId === null
                    && $tituloOriginal === 'Carrie'
                    && $temporada === 1
                    && $episodio === 1
                    && $tmdbId === '288673';
            })
            ->andReturn([['idioma' => 'pt-BR', 'tipo' => 'direto', 'titulo' => 'Carrie']]);

        $disponibilidade = (new VerificadorFontes($torrents))->verificar(
            [
                'id' => 288673,
                'tipo' => 'tv',
                'titulo' => 'Carrie, a Estranha',
                'titulo_original' => 'Carrie',
                'ano' => 2026,
                'imdb_id' => null,
            ],
            OrigemVerificacao::COMANDO,
        );

        $this->assertTrue($disponibilidade->tem_fonte, 'Lista não vazia é fonte.');
        $this->assertSame('pt-BR', $disponibilidade->fonte_idioma);
        $this->assertSame('direto', $disponibilidade->fonte_tipo);
    }

    /**
     * O filme não tem dimensão de temporada/episódio: a sondagem vai nula e a
     * busca é a mesma de sempre — a lista vazia continua sendo "sem fonte".
     */
    public function test_filme_e_sonado_sem_temporada_nem_episodio(): void
    {
        $torrents = Mockery::mock(TorrentService::class);
        $torrents->shouldReceive('fontes')
            ->once()
            ->withArgs(function (...$args): bool {
                [, , , , $temporada, $episodio] = array_values($args);

                return $temporada === null && $episodio === null;
            })
            ->andReturn([]);

        $disponibilidade = (new VerificadorFontes($torrents))->verificar(
            ['id' => 550, 'tipo' => 'movie', 'titulo' => 'Clube da Luta', 'ano' => 1999],
            OrigemVerificacao::COMANDO,
        );

        $this->assertFalse($disponibilidade->tem_fonte, 'Lista vazia é sem fonte.');
    }
}
