<?php

namespace Tests\Feature;

use App\Enums\IdiomaFonte;
use App\Services\TorrentService;
use App\Services\Torrents\CatalogoProvedores;
use App\Services\Torrents\OrcamentoBusca;
use Mockery;
use Tests\TestCase;

/**
 * Trava a regressão da busca em duas fases.
 *
 * O comportamento que estes testes fixam: a busca pergunta primeiro pelo título
 * traduzido e só repete pelo original quando a primeira fase não juntou PT-BR
 * suficiente. Antes as duas fases eram uma lista única, e o título original era
 * consultado sempre — o que gastava orçamento e trazia releases em inglês mesmo
 * quando o dublado já tinha vindo.
 *
 * O catálogo é substituído por um dublê que registra os títulos de cada chamada,
 * para provar qual fase foi disparada sem tocar em rede nem em banco.
 */
class SegundaTentativaTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * Monta uma fonte já no contrato do frontend, com os campos que a montagem lê.
     *
     * @return array<string, mixed>
     */
    private function fonte(string $id, string $idioma, int $seeds = 10): array
    {
        return [
            'id' => $id,
            'titulo' => "Release {$id}",
            'qualidade' => '1080P',
            'idioma' => $idioma,
            'idioma_rotulo' => $idioma,
            'tamanho' => '1,0 GB',
            'seeds' => $seeds,
            'peers' => 0,
            'magnet' => 'magnet:?xt=urn:btih:'.str_pad($id, 40, 'a'),
            'provedor' => 'apibay',
            'provedor_rotulo' => 'APIBay',
            'pack' => false,
        ];
    }

    /**
     * Substitui o catálogo por um dublê que responde por título.
     *
     * O mapa liga cada título consultado à lista de fontes que ele devolve. O
     * dublê também guarda os títulos de cada chamada em `$chamadas`, para os
     * testes conferirem quais fases rodaram.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $respostas
     * @param  array<int, array<int, string>>  $chamadas
     */
    private function servicoComRespostas(array $respostas, array &$chamadas): TorrentService
    {
        $catalogo = Mockery::mock(CatalogoProvedores::class);

        $catalogo->shouldReceive('buscar')->andReturnUsing(
            function (array $titulos) use ($respostas, &$chamadas): array {
                $chamadas[] = $titulos;

                $fontes = [];

                foreach ($titulos as $titulo) {
                    foreach ($respostas[$titulo] ?? [] as $fonte) {
                        $fontes[] = $fonte;
                    }
                }

                return $fontes;
            }
        );

        $catalogo->shouldReceive('reconciliarCenso')->andReturnNull();
        $catalogo->shouldReceive('fecharOrcamento')->andReturnNull();

        // Estes testes olham as fases de título, não o fallback: o stream direto
        // devolve vazio para que a lista final reflita só o que a cascata juntou.
        $catalogo->shouldReceive('buscarFallbackDireto')->andReturn([]);

        $catalogo->shouldReceive('ptBrSuficiente')->andReturnUsing(
            function (array $fontes): bool {
                $ptBr = count(array_filter(
                    $fontes,
                    fn (array $f): bool => in_array(
                        $f['idioma'] ?? '',
                        [IdiomaFonte::DUBLADO->value, IdiomaFonte::DUAL_AUDIO->value],
                        true
                    )
                ));

                return $ptBr >= (int) config('services.torrents.meta_pt_br_coleta', 6);
            }
        );

        $catalogo->shouldReceive('temDublado')->andReturnUsing(
            fn (array $lista): bool => collect($lista)->contains(
                fn (array $f): bool => in_array(
                    $f['idioma'] ?? '',
                    [IdiomaFonte::DUBLADO->value, IdiomaFonte::DUAL_AUDIO->value],
                    true
                )
            )
        );

        return new TorrentService($catalogo, new OrcamentoBusca());
    }

    public function test_a_segunda_fase_nao_e_disparada_com_a_meta_batida(): void
    {
        $chamadas = [];

        $dubladas = [];
        for ($i = 0; $i < 6; $i++) {
            $dubladas[] = $this->fonte("pt{$i}", IdiomaFonte::DUBLADO->value);
        }

        $servico = $this->servicoComRespostas([
            'Homem-Aranha' => $dubladas,
            'Spider-Man' => [$this->fonte('en1', IdiomaFonte::ORIGINAL->value)],
        ], $chamadas);

        $servico->fontes('Homem-Aranha', 2024, 'tt0145487', 'Spider-Man');

        $this->assertCount(1, $chamadas, 'A segunda fase não deveria ter sido disparada.');
        $this->assertSame(['Homem-Aranha'], $chamadas[0]);
    }

    public function test_dispara_a_segunda_fase_quando_a_primeira_fica_abaixo_da_meta(): void
    {
        $chamadas = [];

        $servico = $this->servicoComRespostas([
            'Homem-Aranha' => [$this->fonte('pt1', IdiomaFonte::DUBLADO->value)],
            'Spider-Man' => [$this->fonte('en1', IdiomaFonte::ORIGINAL->value)],
        ], $chamadas);

        $servico->fontes('Homem-Aranha', 2024, 'tt0145487', 'Spider-Man');

        $this->assertCount(2, $chamadas, 'A segunda fase deveria ter sido disparada.');
        $this->assertSame(['Homem-Aranha'], $chamadas[0]);
        $this->assertSame(['Spider-Man'], $chamadas[1]);
    }

    public function test_a_mescla_nao_duplica_a_fonte_repetida(): void
    {
        $chamadas = [];

        $repetida = $this->fonte('pt1', IdiomaFonte::DUBLADO->value);

        $servico = $this->servicoComRespostas([
            'Homem-Aranha' => [$repetida],
            'Spider-Man' => [$repetida, $this->fonte('en1', IdiomaFonte::ORIGINAL->value)],
        ], $chamadas);

        $fontes = $servico->fontes('Homem-Aranha', 2024, 'tt0145487', 'Spider-Man');

        $ids = array_column($fontes, 'id');

        $this->assertSame(
            count($ids),
            count(array_unique($ids)),
            'A fonte repetida nas duas fases não pode aparecer duas vezes.'
        );
    }

    public function test_chave_desligada_nao_dispara_a_segunda_fase(): void
    {
        config(['services.torrents.titulo_original_segunda_tentativa' => false]);

        $chamadas = [];

        $servico = $this->servicoComRespostas([
            'Homem-Aranha' => [$this->fonte('pt1', IdiomaFonte::DUBLADO->value)],
            'Spider-Man' => [$this->fonte('en1', IdiomaFonte::ORIGINAL->value)],
        ], $chamadas);

        $servico->fontes('Homem-Aranha', 2024, 'tt0145487', 'Spider-Man');

        $this->assertCount(1, $chamadas, 'Com a chave desligada, só a primeira fase roda.');
        $this->assertSame(['Homem-Aranha'], $chamadas[0]);
    }

    public function test_sem_titulo_original_nao_ha_segunda_fase(): void
    {
        $chamadas = [];

        $servico = $this->servicoComRespostas([
            'Homem-Aranha' => [$this->fonte('pt1', IdiomaFonte::DUBLADO->value)],
        ], $chamadas);

        $servico->fontes('Homem-Aranha', 2024, 'tt0145487', null);

        $this->assertCount(1, $chamadas, 'Sem título original distinto, não há segunda fase.');
        $this->assertSame(['Homem-Aranha'], $chamadas[0]);
    }
}
