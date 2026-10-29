<?php

namespace Tests\Feature;

use App\Enums\OrigemVerificacao;
use App\Enums\StatusVerificacao;
use App\Enums\TipoMidia;
use App\Models\Disponibilidade;
use App\Models\VerificacaoHome;
use App\Services\TmdbService;
use App\Services\Verificacao\SeloVerificacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\UsaBancoDeTeste;
use Tests\TestCase;

/**
 * Trava o selo de verificação dos cards da Home.
 *
 * O selo existe para o usuário saber, antes de clicar, se aquele título já passou
 * pelo agendador **e** se a prova ainda vale. A regra é a mesma do portão: prova
 * com fonte dentro do prazo conta como verificada; prova vencida, veredito "sem
 * fonte" e título nunca testado aparecem como não verificados.
 */
class SeloVerificacaoTest extends TestCase
{
    use RefreshDatabase;
    use UsaBancoDeTeste;

    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Grava um veredito como a rotina faria.
     *
     * @param  array<string, mixed>  $extra
     */
    private function disponibilidade(
        int $id,
        TipoMidia $tipo,
        bool $temFonte,
        ?int $horasAtras = null,
        array $extra = [],
    ): Disponibilidade {
        return Disponibilidade::create(array_merge([
            'tipo' => $tipo->value,
            'tmdb_id' => $id,
            'tem_fonte' => $temFonte,
            'inconclusivo' => false,
            'deve_retentar' => false,
            'fonte_idioma' => $temFonte ? 'pt-BR' : null,
            'origem' => OrigemVerificacao::COMANDO->value,
            'verificado_em' => $horasAtras === null ? now() : now()->subHours($horasAtras),
        ], $extra));
    }

    /** @return array<string, mixed> */
    private function item(int $id, string $tipo = 'movie', string $titulo = 'Filme'): array
    {
        return ['id' => $id, 'tipo' => $tipo, 'titulo' => $titulo];
    }

    public function test_prova_de_fonte_dentro_do_prazo_fica_verificada(): void
    {
        $this->disponibilidade(101, TipoMidia::FILME, true, 3);

        $itens = (new SeloVerificacao())->marcar([$this->item(101)]);

        $this->assertTrue($itens[0]['verificado']);
        $this->assertNotNull($itens[0]['verificado_em']);
    }

    public function test_veredito_sem_fonte_e_nao_verificado(): void
    {
        $this->disponibilidade(202, TipoMidia::FILME, false);

        $itens = (new SeloVerificacao())->marcar([$this->item(202)]);

        $this->assertFalse($itens[0]['verificado']);
        $this->assertNull($itens[0]['verificado_em']);
    }

    public function test_prova_vencida_deixa_de_ser_verificada(): void
    {
        // Fonte provada não volta à fila na virada do dia: `expirado()` usa a
        // janela longa (7 dias) para quem tem fonte. Oito dias atrás já passou.
        $this->disponibilidade(303, TipoMidia::FILME, true, 8 * 24);

        $itens = (new SeloVerificacao())->marcar([$this->item(303)]);

        $this->assertFalse($itens[0]['verificado']);
    }

    public function test_titulo_nunca_testado_e_nao_verificado(): void
    {
        $itens = (new SeloVerificacao())->marcar([$this->item(404)]);

        $this->assertFalse($itens[0]['verificado']);
        $this->assertNull($itens[0]['verificado_em']);
    }

    /**
     * O mesmo id pode existir como filme e como série no TMDB. A prova de um não
     * pode acender o selo do outro — é o mesmo cuidado da chave de classificação.
     */
    public function test_filme_e_serie_com_o_mesmo_id_nao_se_confundem(): void
    {
        $this->disponibilidade(555, TipoMidia::FILME, true);

        $itens = (new SeloVerificacao())->marcar([
            $this->item(555, 'movie'),
            $this->item(555, 'tv', 'Série'),
        ]);

        $this->assertTrue($itens[0]['verificado']);
        $this->assertFalse($itens[1]['verificado']);
    }

    /** Item sem id reconhecível não quebra a lista nem inventa selo. */
    public function test_item_sem_id_fica_nao_verificado(): void
    {
        $itens = (new SeloVerificacao())->marcar([
            ['id' => null, 'tipo' => 'movie', 'titulo' => 'Sem id'],
        ]);

        $this->assertFalse($itens[0]['verificado']);
    }

    /**
     * O endpoint da Home entrega o selo item a item, inclusive quando a lista
     * vem do TMDB cru (página ainda não verificada).
     */
    public function test_endpoint_da_home_entrega_o_selo(): void
    {
        $this->disponibilidade(101, TipoMidia::FILME, true, 2);

        $tmdb = Mockery::mock(TmdbService::class);
        $tmdb->shouldReceive('tendenciasDoDia')->andReturn([
            'resultados' => [
                $this->item(101, 'movie', 'Filme com fonte'),
                $this->item(202, 'movie', 'Filme sem fonte'),
            ],
            'pagina' => 4,
            'total_paginas' => 25,
        ]);
        $this->instance(TmdbService::class, $tmdb);

        $resposta = $this->getJson('/api/v1/movies/trending?page=4')->assertOk();

        $itens = $resposta->json('data');

        $this->assertTrue($itens[0]['verificado']);
        $this->assertFalse($itens[1]['verificado']);
        $this->assertFalse($resposta->json('meta.verificada'), 'A página 4 não está verificada: a lista é do TMDB.');
    }

    /**
     * A página verificada também passa pelo selo — a prova pode ter vencido
     * depois da rodada, e o card precisa refletir isso.
     */
    public function test_pagina_verificada_tambem_recebe_o_selo(): void
    {
        $this->disponibilidade(101, TipoMidia::FILME, true, 8 * 24);

        VerificacaoHome::create([
            'pagina' => 2,
            'status' => StatusVerificacao::OK->value,
            'itens' => [$this->item(101)],
            'total_paginas' => 25,
            'itens_tmdb' => 20,
            'verificada_em' => now(),
        ]);

        $resposta = $this->getJson('/api/v1/movies/trending?page=2')->assertOk();

        $this->assertTrue($resposta->json('meta.verificada'));
        $this->assertFalse($resposta->json('data.0.verificado'), 'Prova vencida não pode aparecer como verificada.');
    }
}
