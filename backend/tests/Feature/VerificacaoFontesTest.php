<?php

namespace Tests\Feature;

use App\Enums\OrigemVerificacao;
use App\Enums\StatusVerificacao;
use App\Enums\TipoMidia;
use App\Models\Disponibilidade;
use App\Models\VerificacaoExecucao;
use App\Models\VerificacaoHome;
use App\Services\TmdbService;
use App\Services\TorrentService;
use App\Services\Verificacao\CatalogoVerificado;
use App\Services\Verificacao\VerificadorFontes;
use App\Services\Verificacao\VerificadorHome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Concerns\UsaBancoDeTeste;
use Tests\TestCase;

/**
 * Trava a rotina de verificação de fontes sem tocar em rede.
 *
 * O TMDB e a cascata de torrents são substituídos por dublês. O banco é um
 * **banco de teste isolado** (`stretor_testing`, no mesmo servidor), criado pelo
 * [`UsaBancoDeTeste`] — o `RefreshDatabase` roda `migrate:fresh` e esse comando
 * não pode encostar no banco de desenvolvimento, que carrega os dados reais.
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
    use UsaBancoDeTeste;

    protected function tearDown(): void
    {
        /*
         * O `finally` não é enfeite: sem ele, uma expectativa de mock não casada
         * faz o `Mockery::close()` lançar e o `parent::tearDown()` — que devolve a
         * transação do `RefreshDatabase` — nunca roda. A conexão fica `idle in
         * transaction` segurando lock e o teste seguinte trava no primeiro insert,
         * trocando uma falha que devia ser reportada por uma suíte pendurada.
         */
        try {
            Mockery::close();
        } finally {
            parent::tearDown();
        }
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
                        // Como o real: uma tentativa que chega a um veredito não é
                        // inconclusiva e não pede nova chance, então o dublê limpa
                        // as duas marcas ao gravar.
                        'inconclusivo' => false,
                        'deve_retentar' => false,
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

    /**
     * Grava um veredito como o real faria. `deveRetentar` é a política que a
     * marca carrega: `true` só para a busca que não pôde concluir.
     */
    private function disponibilidade(
        int $id,
        bool $temFonte,
        ?string $horasAtras = null,
        bool $inconclusivo = false,
        bool $deveRetentar = false,
    ): void {
        Disponibilidade::create([
            'tipo' => TipoMidia::FILME->value,
            'tmdb_id' => $id,
            'tem_fonte' => $temFonte,
            'inconclusivo' => $inconclusivo,
            'deve_retentar' => $deveRetentar,
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
     *
     * O vencido aqui é um veredito definitivo, então a janela dele é a longa: o
     * título só volta depois de `VERIFICACAO_VALIDADE_DIAS_DEFINITIVO` dias.
     */
    public function test_prova_valida_poupa_e_prova_vencida_volta_a_fila(): void
    {
        $itens = [$this->item(1, 'movie', 'Válida'), $this->item(2, 'movie', 'Vencida')];

        $this->disponibilidade(1, true);           // verificada agora
        $this->disponibilidade(2, false, '192');   // sem fonte, vencida há 8 dias

        $chamados = [];
        $verificador = $this->verificadorFalso([2 => true], $chamados);

        (new VerificadorHome($this->tmdbComPagina($itens), $verificador))
            ->executar(limitePaginas: 1, pausas: false);

        $this->assertSame([2], $chamados, 'Só o título com prova vencida volta à fila.');
    }

    /**
     * O resgate horário não faz nada quando as provas estão frescas.
     *
     * É o caso normal do dia: a rodada das 04:00 passou, todas as páginas estão
     * dentro da validade e o `--se-vencido` sai em silêncio. Nenhuma execução
     * pode ser registrada — se fosse, o status passaria a mostrar rodadas de
     * hora em hora sem trabalho nenhum.
     */
    public function test_resgate_nao_roda_sem_prova_vencida(): void
    {
        VerificacaoHome::create([
            'pagina' => 1,
            'status' => StatusVerificacao::OK->value,
            'itens' => [$this->item(1, 'movie', 'A')],
            'total_paginas' => 25,
            'itens_tmdb' => 20,
            'verificada_em' => now()->subHours(2),
        ]);

        $verificador = Mockery::mock(VerificadorHome::class);
        $verificador->shouldNotReceive('executar');
        $this->instance(VerificadorHome::class, $verificador);

        $this->artisan('fontes:verificar', ['--se-vencido' => true])
            ->expectsOutputToContain('nenhuma prova vencida')
            ->assertSuccessful();

        $this->assertSame(0, VerificacaoExecucao::count());
    }

    /**
     * Prova vencida aciona a rodada completa.
     *
     * Foi a situação criada por um deploy depois das 04:00: o `schedule:work`
     * perde a tarefa diária do dia (`isDue()` olha o minuto corrente) e as provas
     * vencem sem substituição. O resgate horário é o que devolve a Home ao
     * catálogo verificado.
     */
    public function test_resgate_roda_quando_a_prova_venceu(): void
    {
        VerificacaoHome::create([
            'pagina' => 1,
            'status' => StatusVerificacao::OK->value,
            'itens' => [$this->item(1, 'movie', 'A')],
            'total_paginas' => 25,
            'itens_tmdb' => 20,
            'verificada_em' => now()->subHours(30),
        ]);

        $execucao = VerificacaoExecucao::create([
            'origem' => OrigemVerificacao::COMANDO->value,
            'status' => VerificacaoExecucao::STATUS_OK,
            'iniciada_em' => now(),
            'finalizada_em' => now(),
            'paginas_processadas' => 1,
            'titulos_testados' => 1,
            'titulos_com_fonte' => 1,
        ]);

        $verificador = Mockery::mock(VerificadorHome::class);
        $verificador->shouldReceive('executar')->once()->andReturn($execucao);
        $this->instance(VerificadorHome::class, $verificador);

        $this->artisan('fontes:verificar', ['--se-vencido' => true])
            ->expectsOutputToContain('1 página(s) com prova vencida')
            ->assertSuccessful();
    }

    /**
     * Rodada em curso bloqueia o resgate, mesmo com prova vencida.
     *
     * A diária e o resgate são entradas de agenda diferentes, com travas
     * diferentes; sem esta checagem, o resgate das 04:20 começaria por cima da
     * rodada completa que ainda está rodando — duas varreduras disputando os
     * mesmos títulos.
     */
    public function test_resgate_espera_a_rodada_em_curso(): void
    {
        VerificacaoHome::create([
            'pagina' => 1,
            'status' => StatusVerificacao::OK->value,
            'itens' => [$this->item(1, 'movie', 'A')],
            'total_paginas' => 25,
            'itens_tmdb' => 20,
            'verificada_em' => now()->subHours(30),
        ]);

        VerificacaoExecucao::create([
            'origem' => OrigemVerificacao::COMANDO->value,
            'status' => VerificacaoExecucao::STATUS_EM_ANDAMENTO,
            'iniciada_em' => now()->subMinutes(10),
        ]);

        $verificador = Mockery::mock(VerificadorHome::class);
        $verificador->shouldNotReceive('executar');
        $this->instance(VerificadorHome::class, $verificador);

        $this->artisan('fontes:verificar', ['--se-vencido' => true])
            ->expectsOutputToContain('execução em andamento')
            ->assertSuccessful();
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

    /**
     * Prova vencida não é prova: a página `ok` de dois dias atrás deixa de valer
     * e a Home volta ao TMDB do dia.
     *
     * Foi a falta desta validade que congelou a Home: a rotina diária parou de
     * rodar, as páginas 1 e 2 continuaram `ok` e a lista de 07/10 seguiu sendo
     * servida por semanas, como se fosse a de hoje.
     */
    public function test_pagina_pronta_alem_da_validade_volta_ao_tmdb(): void
    {
        VerificacaoHome::create([
            'pagina' => 1,
            'status' => StatusVerificacao::OK->value,
            'itens' => [$this->item(1, 'movie', 'A')],
            'total_paginas' => 25,
            'itens_tmdb' => 20,
            'verificada_em' => now()->subHours(48),
        ]);

        $this->assertNull(
            (new CatalogoVerificado())->itensDaPagina(1),
            'Página verificada há 48 h não pode continuar respondendo pela Home.'
        );
    }

    /** Validade zero desliga o prazo: a página vale até ser regravada. */
    public function test_validade_zero_mantem_a_pagina_pronta(): void
    {
        config(['services.verificacao.validade_horas' => 0]);

        VerificacaoHome::create([
            'pagina' => 1,
            'status' => StatusVerificacao::OK->value,
            'itens' => [$this->item(1, 'movie', 'A')],
            'total_paginas' => 25,
            'itens_tmdb' => 20,
            'verificada_em' => now()->subDays(30),
        ]);

        $this->assertNotNull((new CatalogoVerificado())->itensDaPagina(1));
    }

    /**
     * Página travada em `em andamento` por uma execução que morreu no meio volta
     * a `pendente` no começo da rodada seguinte — inclusive quando está fora do
     * recorte de páginas desta rodada.
     *
     * A marca órfã não bloqueia o trabalho (o laço sempre recomeça na página 1),
     * mas envenena o `fontes:status`: a página aparecia "em curso" para sempre.
     * A que está em andamento **agora** não pode ser tocada — seria roubar de uma
     * rodada em curso.
     */
    public function test_rodada_libera_pagina_travada_e_preserva_a_em_curso(): void
    {
        VerificacaoHome::create([
            'pagina' => 5,
            'status' => StatusVerificacao::EM_ANDAMENTO->value,
        ]);
        VerificacaoHome::create([
            'pagina' => 6,
            'status' => StatusVerificacao::EM_ANDAMENTO->value,
        ]);

        // O `updated_at` do Eloquent é sempre "agora"; o envelhecimento precisa
        // ser feito no query builder cru, que não mexe na coluna de controle.
        DB::table('verificacoes_home')
            ->where('pagina', 5)
            ->update(['updated_at' => now()->subHours(48)]);

        $chamados = [];
        $verificador = $this->verificadorFalso([], $chamados);

        (new VerificadorHome($this->tmdbComPagina([$this->item(1, 'movie', 'A')]), $verificador))
            ->executar(limitePaginas: 1, pausas: false);

        $this->assertSame(
            StatusVerificacao::PENDENTE,
            VerificacaoHome::find(5)->status,
            'Página em andamento há dois dias é resíduo e precisa voltar à fila.'
        );
        $this->assertSame(
            StatusVerificacao::EM_ANDAMENTO,
            VerificacaoHome::find(6)->status,
            'A página em andamento agora pertence a uma rodada em curso.'
        );
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
        // A busca correu até o fim e não achou nada: veredito definitivo.
        $torrents->shouldReceive('buscaInconclusiva')->once()->andReturn(false);

        $disponibilidade = (new VerificadorFontes($torrents))->verificar(
            ['id' => 550, 'tipo' => 'movie', 'titulo' => 'Clube da Luta', 'ano' => 1999],
            OrigemVerificacao::COMANDO,
        );

        $this->assertFalse($disponibilidade->tem_fonte, 'Lista vazia é sem fonte.');
        $this->assertFalse($disponibilidade->inconclusivo, 'Busca que perguntou a todos conclui.');
        $this->assertFalse($disponibilidade->deve_retentar, 'Veredito definitivo espera a janela longa.');
    }

    /**
     * Lista vazia com a busca inconclusiva **não** vira "sem fonte": fica marcada
     * como "não deu para concluir".
     *
     * É a diferença que o guard existe para travar. Um provedor fora do ar, um
     * orçamento que cortou a cascata no meio ou nenhum provedor configurado
     * devolvem vazio sem prova; gravar negativo aqui travaria o título por 24 h
     * por um erro de rede, e não por ausência real de fonte.
     */
    public function test_lista_vazia_com_busca_inconclusiva_nao_vira_veredito(): void
    {
        $torrents = Mockery::mock(TorrentService::class);
        $torrents->shouldReceive('fontes')->once()->andReturn([]);
        $torrents->shouldReceive('buscaInconclusiva')->once()->andReturn(true);

        $disponibilidade = (new VerificadorFontes($torrents))->verificar(
            ['id' => 550, 'tipo' => 'movie', 'titulo' => 'Clube da Luta', 'ano' => 1999],
            OrigemVerificacao::COMANDO,
        );

        $this->assertFalse($disponibilidade->tem_fonte);
        $this->assertTrue($disponibilidade->inconclusivo, 'Busca sem como perguntar a todos não é veredito.');
        $this->assertTrue($disponibilidade->deve_retentar, 'Quem não pôde concluir pede nova chance na virada do dia.');
    }

    /**
     * Quem pediu nova chance espera a virada do dia — e não a rodada seguinte.
     *
     * O inconclusivo é o título que voltava à fila **dentro do mesmo dia**, a cada
     * rodada: a tentativa que não valeu como prova o devolvia à fila na hora, e
     * era isso que enchia a rodada de títulos sem fonte se repetindo. A marca
     * `deve_retentar` muda o ritmo sem mudar o sentido: continua merecendo outra
     * tentativa, mas a próxima virada de dia é que a autoriza.
     */
    public function test_inconclusivo_volta_na_virada_do_dia_e_nao_na_mesma_rodada(): void
    {
        $itens = [$this->item(1, 'movie', 'Vencido'), $this->item(2, 'movie', 'Recém-tentado')];

        // O 1 foi tentado ontem sem concluir; o 2 acabou de ser tentado, também sem
        // concluir. Os dois pedem nova chance, mas só o primeiro já tem o dia virado.
        $this->disponibilidade(1, false, '25', inconclusivo: true, deveRetentar: true);
        $this->disponibilidade(2, false, inconclusivo: true, deveRetentar: true);

        $chamados = [];
        $verificador = $this->verificadorFalso([1 => true], $chamados);

        (new VerificadorHome($this->tmdbComPagina($itens), $verificador))
            ->executar(limitePaginas: 1, pausas: false);

        $this->assertSame([1], $chamados, 'O inconclusivo recém-tentado não pode voltar no mesmo dia.');
    }

    /**
     * O veredito definitivo é o lado paciente da regra: perguntou-se a todos os
     * provedores e não havia nada, e isso não muda de um dia para o outro.
     */
    public function test_veredito_definitivo_so_volta_depois_da_janela_longa(): void
    {
        $itens = [$this->item(1, 'movie', 'Ontem'), $this->item(2, 'movie', 'Semana passada')];

        // O 1 venceu a validade curta, mas não a janela longa; o 2 já passou dela.
        $this->disponibilidade(1, false, '25');
        $this->disponibilidade(2, false, '192');

        $chamados = [];
        $verificador = $this->verificadorFalso([2 => true], $chamados);

        (new VerificadorHome($this->tmdbComPagina($itens), $verificador))
            ->executar(limitePaginas: 1, pausas: false);

        $this->assertSame([2], $chamados, 'Só o veredito além da janela longa volta à fila.');
    }
}
