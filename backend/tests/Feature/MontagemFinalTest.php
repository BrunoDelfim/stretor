<?php

namespace Tests\Feature;

use App\Enums\IdiomaFonte;
use App\Services\TorrentService;
use App\Services\Torrents\CatalogoProvedores;
use App\Support\MensagensTorrent;
use Mockery;
use Tests\TestCase;

/**
 * Trava a regressão da montagem final da lista de fontes.
 *
 * O bug que originou estes testes: uma busca por S01E01 de "American Horror
 * Story" devolvia só duas fontes, e o APIBay aparecia no censo como `com_fonte`
 * sem ter nenhuma fonte na lista. A causa era dupla — o corte de seeds zero
 * removia as fontes do APIBay (que informa seeds reais, muitas vezes 0) e o corte
 * duro de idioma descartava a reserva inteira quando havia qualquer PT-BR.
 *
 * A diretriz do usuário é clara: **se tem fonte, tem que estar na array de fontes
 * pra tentar** — mas a lista final é só áudio PT-BR provado, sem preenchimento
 * com reserva, e uma fonte sem seeds ou sem magnet não entra. Estes testes fixam
 * esse contrato sem tocar em rede nem em banco: o catálogo é substituído por um
 * dublê que devolve fontes controladas.
 */
class MontagemFinalTest extends TestCase
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
    private function fonte(
        string $id,
        string $provedor,
        string $idioma,
        int $seeds,
        bool $pack = false,
    ): array {
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
            'provedor' => $provedor,
            'provedor_rotulo' => $provedor,
            'pack' => $pack,
        ];
    }

    /**
     * Substitui o catálogo por um dublê que devolve as fontes dadas e mantém um
     * censo mínimo para a reconciliação.
     *
     * @param  array<int, array<string, mixed>>  $fontes
     */
    private function servicoComFontes(array $fontes): TorrentService
    {
        $catalogo = Mockery::mock(CatalogoProvedores::class);
        $catalogo->shouldReceive('buscar')->andReturn($fontes);
        $catalogo->shouldReceive('reconciliarCenso')->andReturnNull();
        $catalogo->shouldReceive('temDublado')->andReturnUsing(
            fn (array $lista): bool => collect($lista)->contains(
                fn (array $f): bool => in_array($f['idioma'], [IdiomaFonte::DUBLADO->value, IdiomaFonte::DUAL_AUDIO->value], true)
            )
        );

        return new TorrentService($catalogo);
    }

    /**
     * O caso central da diretriz: com PT-BR na lista, a reserva (original) não
     * entra. A lista para quando as PT-BR acabam — não há preenchimento.
     */
    public function test_lista_final_e_so_pt_br_quando_ha_dublado(): void
    {
        $servico = $this->servicoComFontes([
            $this->fonte('dublado1', 'knaben', IdiomaFonte::DUBLADO->value, 10),
            $this->fonte('dual1', 'torrentio', IdiomaFonte::DUAL_AUDIO->value, 8),
            $this->fonte('original1', 'apibay', IdiomaFonte::ORIGINAL->value, 50),
            $this->fonte('legendado1', 'bt4g', IdiomaFonte::LEGENDADO->value, 30),
        ]);

        $fontes = $servico->fontes('American Horror Story', 2011, 'tt1320771', null, 1, 1);

        $this->assertCount(2, $fontes, 'A lista final deve ser só o áudio PT-BR provado.');
        $this->assertSame(
            [IdiomaFonte::DUBLADO->value, IdiomaFonte::DUAL_AUDIO->value],
            array_column($fontes, 'idioma'),
            'O dublado vem antes do dual e nenhum original entra.'
        );
    }

    /**
     * O invariante da diretriz: uma fonte com seeds > 0 e magnet válido não pode
     * ser descartada pela montagem final só por não ser PT-BR quando **não há**
     * PT-BR nenhum. Sem isso, a lista ficaria vazia e o player não teria o que
     * tentar.
     */
    public function test_fonte_com_seeds_sobrevive_quando_nao_ha_pt_br(): void
    {
        $servico = $this->servicoComFontes([
            $this->fonte('original1', 'apibay', IdiomaFonte::ORIGINAL->value, 5),
            $this->fonte('legendado1', 'knaben', IdiomaFonte::LEGENDADO->value, 3),
        ]);

        $fontes = $servico->fontes('American Horror Story', 2011, 'tt1320771', null, 1, 1);

        $this->assertCount(2, $fontes, 'Sem PT-BR, a reserva volta para não deixar a lista vazia.');
        // A ordem é por idioma antes de provedor: o legendado (prioridade 2) vem
        // antes do original (prioridade 3), mesmo com menos seeds.
        $this->assertSame('legendado1', $fontes[0]['id'], 'O legendado vem antes do original.');
        $this->assertSame('original1', $fontes[1]['id']);
    }

    /**
     * Fonte sem seeds é morta e não entra — nem como reserva. É o corte que
     * protege o player de tentar um release que não tem peer nenhum.
     */
    public function test_fonte_sem_seeds_e_descartada(): void
    {
        $servico = $this->servicoComFontes([
            $this->fonte('morta', 'apibay', IdiomaFonte::ORIGINAL->value, 0),
            $this->fonte('viva', 'knaben', IdiomaFonte::DUBLADO->value, 2),
        ]);

        $fontes = $servico->fontes('American Horror Story', 2011, 'tt1320771', null, 1, 1);

        $this->assertCount(1, $fontes);
        $this->assertSame('viva', $fontes[0]['id'], 'A fonte sem seeds não pode entrar na lista.');
    }

    /**
     * Fonte sem magnet é inútil mesmo com seeds: não há como o player abrir a
     * sessão. O corte de magnet vazio é a segunda barreira contra fonte quebrada.
     */
    public function test_fonte_sem_magnet_e_descartada(): void
    {
        $semMagnet = $this->fonte('quebrada', 'apibay', IdiomaFonte::DUBLADO->value, 10);
        $semMagnet['magnet'] = '';

        $servico = $this->servicoComFontes([
            $semMagnet,
            $this->fonte('ok', 'knaben', IdiomaFonte::DUBLADO->value, 1),
        ]);

        $fontes = $servico->fontes('American Horror Story', 2011, 'tt1320771', null, 1, 1);

        $this->assertCount(1, $fontes);
        $this->assertSame('ok', $fontes[0]['id'], 'A fonte sem magnet não pode entrar na lista.');
    }

    /**
     * O teto de fontes é curto: mesmo com muitas PT-BR, a lista não passa do
     * limite. O usuário quer poucas opções boas, não um catálogo.
     */
    public function test_lista_respeita_o_teto_de_fontes(): void
    {
        $fontes = [];

        for ($i = 0; $i < 10; $i++) {
            $fontes[] = $this->fonte("dublado{$i}", 'knaben', IdiomaFonte::DUBLADO->value, 10 - $i);
        }

        $servico = $this->servicoComFontes($fontes);
        $resultado = $servico->fontes('American Horror Story', 2011, 'tt1320771', null, 1, 1);

        $this->assertCount(
            MensagensTorrent::LIMITE_FONTES,
            $resultado,
            'A lista final não pode passar do teto de fontes.'
        );
    }
}
