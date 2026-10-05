<?php

namespace Tests\Feature;

use App\Enums\IdiomaFonte;
use App\Services\TorrentService;
use App\Services\Torrents\CatalogoProvedores;
use App\Services\Torrents\OrcamentoBusca;
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
        // O fallback direto é dublado vazio: estes testes olham a montagem dos
        // torrents, e um caso deles termina com a lista final vazia — sem esta
        // expectativa, o gatilho do `TorrentService` chamaria o método real.
        $catalogo->shouldReceive('buscarFallbackDireto')->andReturn([]);
        $catalogo->shouldReceive('reconciliarCenso')->andReturnNull();
        $catalogo->shouldReceive('fecharOrcamento')->andReturnNull();
        $catalogo->shouldReceive('temDublado')->andReturnUsing(
            fn (array $lista): bool => collect($lista)->contains(
                fn (array $f): bool => in_array($f['idioma'], [IdiomaFonte::DUBLADO->value, IdiomaFonte::DUAL_AUDIO->value], true)
            )
        );

        return new TorrentService($catalogo, new OrcamentoBusca());
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

        $fontes = $servico->fontes('American Horror Story', 2024, 'tt1320771', null, 1, 1);

        $this->assertCount(2, $fontes, 'A lista final deve ser só o áudio PT-BR provado.');
        $this->assertSame(
            [IdiomaFonte::DUBLADO->value, IdiomaFonte::DUAL_AUDIO->value],
            array_column($fontes, 'idioma'),
            'O dublado vem antes do dual e nenhum original entra.'
        );
    }

    /**
     * O corte duro de idioma não abre exceção para a reserva: sem PT-BR nenhum,
     * a lista sai **vazia**, mesmo com fontes vivas (seeds > 0, magnet válido).
     *
     * Antes, o `ordenar()` revertia o corte e devolvia o original/legendado "para
     * o player não ficar sem nada" — e era isso que impedia o fallback de stream
     * direto de disparar, porque a lista nunca ficava vazia. A reserva em inglês
     * não é resposta para quem pediu português: quem resolve o conteúdo raro é o
     * stream direto, acionado por [`TorrentService::fontes()`] ao ver a lista
     * vazia. A reserva só volta com `somente_pt_br_ou_legendado = false`.
     */
    public function test_lista_sai_vazia_quando_nao_ha_pt_br(): void
    {
        $servico = $this->servicoComFontes([
            $this->fonte('original1', 'apibay', IdiomaFonte::ORIGINAL->value, 5),
            $this->fonte('legendado1', 'knaben', IdiomaFonte::LEGENDADO->value, 3),
        ]);

        $fontes = $servico->fontes('American Horror Story', 2024, 'tt1320771', null, 1, 1);

        $this->assertCount(0, $fontes, 'Sem PT-BR, a lista sai vazia para o fallback direto assumir.');
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

        $fontes = $servico->fontes('American Horror Story', 2024, 'tt1320771', null, 1, 1);

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

        $fontes = $servico->fontes('American Horror Story', 2024, 'tt1320771', null, 1, 1);

        $this->assertCount(1, $fontes);
        $this->assertSame('ok', $fontes[0]['id'], 'A fonte sem magnet não pode entrar na lista.');
    }

    /**
     * O pack de idioma não provado sobrevive ao **filtro** de `packs_qualquer_idioma`
     * — ele não é descartado por não declarar PT-BR. Mas o corte duro de idioma
     * continua valendo depois: sem nenhuma fonte PT-BR, a lista sai vazia e o
     * fallback direto assume. A exceção do pack abre a porta do filtro, não a da
     * lista final.
     */
    public function test_pack_sem_pt_br_nao_segura_a_lista_sozinho(): void
    {
        $servico = $this->servicoComFontes([
            $this->fonte('pack_original', 'torrentio', IdiomaFonte::ORIGINAL->value, 20, pack: true),
        ]);

        $fontes = $servico->fontes('American Horror Story', 2024, 'tt1320771', null, 1, 1);

        $this->assertCount(0, $fontes, 'O pack sem PT-BR não segura a lista: o corte de idioma a esvazia.');
    }

    /**
     * A última linha de defesa: um pack que **declara** a temporada errada não
     * entra, mesmo sendo dublado e tendo mais seeds. A exceção do gate na
     * cascata não abre espaço para o pack da 2ª numa busca da 1ª — quem o barra
     * aqui é [`TorrentService::packDaTemporadaErrada()`].
     */
    public function test_pack_da_temporada_errada_e_descartado_na_montagem(): void
    {
        $pack = $this->fonte('pack_2a', 'torrentio', IdiomaFonte::DUBLADO->value, 50, pack: true);
        $pack['titulo'] = 'American Horror Story 2ª Temporada Dublado 1080p';
        $pack['release'] = 'American Horror Story 2ª Temporada Dublado 1080p';

        $servico = $this->servicoComFontes([$pack]);

        $fontes = $servico->fontes('American Horror Story', 2024, 'tt1320771', null, 1, 1);

        $this->assertCount(0, $fontes, 'O pack que declara a temporada errada não pode entrar na lista.');
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
        $resultado = $servico->fontes('American Horror Story', 2024, 'tt1320771', null, 1, 1);

        $this->assertCount(
            MensagensTorrent::LIMITE_FONTES,
            $resultado,
            'A lista final não pode passar do teto de fontes.'
        );
    }
}
