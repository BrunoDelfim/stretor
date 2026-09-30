<?php

namespace Tests\Feature;

use App\Services\Torrents\OrcamentoBusca;
use App\Services\Torrents\ProvedorTorrentio;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Trava a regressão da consulta dupla do Torrentio.
 *
 * O `language=portuguese` embutido na URL é um corte na origem: o Torrentio só
 * devolve o stream que ele próprio já classificou como português. Para o pack de
 * temporada isso é um problema — muitos vêm rotulados de um jeito que o filtro
 * não reconhece e o pack some antes de o nosso parser olhar o nome do release,
 * que é quem decide o idioma de fato. A correção dispara uma segunda consulta,
 * sem o filtro, só para série, e funde as duas por infohash.
 *
 * Estes testes fixam esse contrato sem tocar em rede: a fachada `Http` é
 * substituída por uma dublê que devolve streams controlados.
 */
class ProvedorTorrentioTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.torrents.torrentio_url' => 'https://torrentio.strem.fun',
            'services.torrents.torrentio_idiomas' => 'portuguese',
            'services.torrents.torrentio_busca_ampla' => true,
            'services.torrents.tempo_limite' => 15,
        ]);
    }

    /**
     * Orçamento sem busca em curso: `restante()` devolve `null` e vale o teto
     * próprio do provedor, sem prazo global encurtando a consulta.
     */
    private function provedor(): ProvedorTorrentio
    {
        return new ProvedorTorrentio(new OrcamentoBusca());
    }

    /**
     * Monta um stream no formato do Torrentio: os metadados vêm no rótulo, e o
     * nome do release mora na primeira linha.
     *
     * @return array<string, mixed>
     */
    private function stream(string $hash, string $release, int $seeds = 10): array
    {
        return [
            'name' => "Torrentio\n1080p",
            'title' => $release."\n👤 {$seeds} 💾 1.4 GB ⚙️ ThePirateBay",
            'infoHash' => $hash,
        ];
    }

    /**
     * Indexa a lista de fontes pelo infohash, como o provedor faz internamente.
     *
     * @param  array<int, array<string, mixed>>  $fontes
     * @return array<string, array<string, mixed>>
     */
    private function porId(array $fontes): array
    {
        return array_column($fontes, null, 'id');
    }

    /**
     * O caso central: para série, a busca dispara as duas consultas — a filtrada
     * e a ampla — e funde as respostas por infohash.
     */
    public function test_serie_dispara_consulta_dupla_e_funde_por_infohash(): void
    {
        Http::fake(function (Request $pedido) {
            if (str_contains($pedido->url(), 'language=portuguese')) {
                return Http::response(['streams' => [
                    $this->stream('aaaa', 'American Horror Story S01E01 Dublado 720p', 10),
                ]]);
            }

            return Http::response(['streams' => [
                // Mesmo infohash da consulta filtrada, com outro rótulo: a filtrada
                // é quem manda, por ser a leitura com o idioma mais provável.
                $this->stream('aaaa', 'American Horror Story S01E01 Legendado 720p', 10),
                // Pack que só a consulta ampla enxerga.
                $this->stream('bbbb', 'American Horror Story S01 Completa Dublado 1080p', 30),
            ]]);
        });

        $fontes = $this->provedor()->buscar('American Horror Story', 2011, 'tt1234567', 1, 1);

        Http::assertSentCount(2);

        // Uma consulta leva o filtro embutido na URL; a outra, não.
        Http::assertSent(fn (Request $r): bool => str_contains(
            $r->url(),
            '/language=portuguese/stream/series/tt1234567:1:1.json'
        ));
        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/stream/series/tt1234567:1:1.json')
            && ! str_contains($r->url(), 'language='));

        $porId = $this->porId($fontes);

        $this->assertCount(2, $porId, 'As duas consultas devem se fundir num só conjunto, sem repetir o infohash.');
        $this->assertArrayHasKey('bbbb', $porId, 'O pack que só a consulta ampla enxerga precisa entrar.');
        $this->assertSame(
            'American Horror Story S01E01 Dublado 720p',
            $porId['aaaa']['titulo'],
            'Em conflito de infohash, a consulta filtrada tem prioridade.'
        );
    }

    /**
     * Filme não paga a segunda consulta: o catálogo de filme é bem servido pelo
     * filtro, e a consulta extra só encheria a lista de originais em inglês.
     */
    public function test_filme_nao_paga_a_segunda_consulta(): void
    {
        Http::fake(fn () => Http::response(['streams' => [
            $this->stream('cccc', 'American Horror Story 2011 Dublado 1080p', 12),
        ]]));

        $fontes = $this->provedor()->buscar('American Horror Story', 2011, 'tt1234567');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r): bool => str_ends_with(
            $r->url(),
            '/language=portuguese/stream/movie/tt1234567.json'
        ));

        $this->assertCount(1, $fontes);
    }

    /**
     * A chave de config desliga a consulta ampla sem reverter código.
     */
    public function test_chave_desliga_a_consulta_ampla(): void
    {
        config(['services.torrents.torrentio_busca_ampla' => false]);

        Http::fake(fn () => Http::response(['streams' => [
            $this->stream('dddd', 'American Horror Story S01E01 Dublado 720p', 10),
        ]]));

        $this->provedor()->buscar('American Horror Story', 2011, 'tt1234567', 1, 1);

        Http::assertSentCount(1);
    }

    /**
     * Sem o identificador do IMDb não há como consultar: o Torrentio não aceita
     * busca por nome, e nenhuma requisição deve sair.
     */
    public function test_sem_imdb_id_nao_consulta(): void
    {
        Http::fake();

        $this->assertSame([], $this->provedor()->buscar('American Horror Story', 2011));

        Http::assertNothingSent();
    }
}
