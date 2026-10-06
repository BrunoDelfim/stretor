<?php

namespace Tests\Feature;

use App\Enums\IdiomaFonte;
use App\Services\TorrentService;
use App\Services\Torrents\CatalogoProvedores;
use App\Services\Torrents\NormalizaFonte;
use App\Services\Torrents\OrcamentoBusca;
use Mockery;
use Tests\TestCase;

/**
 * Trava o contrato da fonte direta (MP4/HLS) na montagem final.
 *
 * O stream direto é o primeiro método de indexação da busca: roda antes da
 * cascata de torrents e, quando acha, **encerra a busca** — a fonte direta não
 * depende de malha, então a cascata nem chega a ser consultada. É o corte que dá
 * sentido ao posto de primeiro método: perguntar aos trackers depois de ter uma
 * URL que toca na hora só atrasaria a exibição.
 *
 * O risco desta arquitetura é silencioso — a fonte direta não tem `seeds` nem
 * `magnet`, então os filtros que sempre valeram para torrent a descartariam sem
 * que ninguém percebesse. Estes testes fixam os dois pontos que a salvam: o ramo
 * `tipo === 'direto'` em `ordenar()` (para as diretas que chegam pela cascata) e a
 * montagem que preenche `stream` com `magnet` vazio.
 *
 * Como nos demais testes de montagem, o catálogo é substituído por um dublê e
 * nada aqui toca em rede ou banco.
 */
class StreamDiretoTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * Monta uma fonte direta já no contrato do frontend.
     *
     * @return array<string, mixed>
     */
    private function fonteDireta(string $id, string $idioma = 'pt-BR'): array
    {
        return [
            'id' => $id,
            'tipo' => 'direto',
            'titulo' => "Release {$id}",
            'qualidade' => '1080P',
            'idioma' => $idioma,
            'idioma_rotulo' => $idioma,
            'tamanho' => null,
            // Sem malha: a fonte direta usa o marcador de "não medido" para
            // sobreviver ao corte de seeds.
            'seeds' => 1,
            'peers' => 0,
            'magnet' => '',
            'stream' => "https://exemplo.test/{$id}.mp4",
            'provedor' => 'stream_direto',
            'provedor_rotulo' => 'Stream direto',
        ];
    }

    /**
     * Monta uma fonte de torrent comum, para o controle dos testes.
     *
     * @return array<string, mixed>
     */
    private function fonteTorrent(string $id, string $idioma = 'pt-BR'): array
    {
        return [
            'id' => $id,
            'tipo' => 'torrent',
            'titulo' => "Release {$id}",
            'qualidade' => '1080P',
            'idioma' => $idioma,
            'idioma_rotulo' => $idioma,
            'tamanho' => '1,0 GB',
            'seeds' => 10,
            'peers' => 2,
            'magnet' => 'magnet:?xt=urn:btih:'.str_pad($id, 40, 'a'),
            'stream' => '',
            'provedor' => 'apibay',
            'provedor_rotulo' => 'APIBay',
        ];
    }

    /**
     * Substitui o catálogo por um dublê que devolve as fontes dadas.
     *
     * `buscarFallbackDireto()` devolve o stream direto e `buscar()` devolve a
     * cascata de torrents. Com fonte direta na mão, só o primeiro é chamado — a
     * cascata é dispensada e `dispensarCascata()` entra no lugar dela, zerando o
     * censo dos trackers que não foram consultados. O dublê declara os dois
     * caminhos para o teste poder escolher qual deles aconteceu.
     *
     * @param  array<int, array<string, mixed>>  $fontes
     * @param  array<int, array<string, mixed>>  $diretas
     */
    private function servicoComFontes(array $fontes, array $diretas = []): TorrentService
    {
        $catalogo = Mockery::mock(CatalogoProvedores::class);
        $catalogo->shouldReceive('buscar')->andReturn($fontes);
        $catalogo->shouldReceive('buscarFallbackDireto')->andReturn($diretas);
        $catalogo->shouldReceive('dispensarCascata')->andReturnNull();
        $catalogo->shouldReceive('reconciliarCenso')->andReturnNull();
        $catalogo->shouldReceive('fecharOrcamento')->andReturnNull();
        $catalogo->shouldReceive('temDublado')->andReturn(true);
        $catalogo->shouldReceive('temDublado')->andReturnUsing(
            fn (array $lista): bool => collect($lista)->contains(
                fn (array $f): bool => in_array($f['idioma'], [IdiomaFonte::DUBLADO->value, IdiomaFonte::DUAL_AUDIO->value], true)
            )
        );

        return new TorrentService($catalogo, new OrcamentoBusca());
    }

    /**
     * O caso central: uma fonte direta sem magnet precisa atravessar a montagem.
     *
     * Sem o ramo `tipo === 'direto'` em `ordenar()`, o filtro `magnet === ''`
     * descartaria a fonte e o fallback inteiro viraria trabalho perdido.
     */
    public function test_fonte_direta_sem_magnet_sobrevive_a_montagem(): void
    {
        $servico = $this->servicoComFontes([$this->fonteDireta('direta_1')]);

        $fontes = $servico->fontes('Filme Raro');

        $this->assertCount(1, $fontes, 'A fonte direta não pode ser descartada pelo filtro de magnet.');
        $this->assertSame('direto', $fontes[0]['tipo']);
        $this->assertSame('https://exemplo.test/direta_1.mp4', $fontes[0]['stream']);
        $this->assertSame('', $fontes[0]['magnet']);
    }

    /**
     * O controle: uma fonte de torrent sem magnet continua sendo descartada.
     *
     * É isso que prova que o ramo novo é específico do `tipo === 'direto'`, e
     * não um furo genérico que deixaria passar qualquer fonte quebrada.
     */
    public function test_torrent_sem_magnet_continua_descartado(): void
    {
        $quebrada = $this->fonteTorrent('quebrada');
        $quebrada['magnet'] = '';

        $servico = $this->servicoComFontes([$quebrada]);

        $fontes = $servico->fontes('Filme Raro');

        $this->assertCount(0, $fontes, 'Torrent sem magnet continua fora da lista.');
    }

    /**
     * Com fonte direta na mão, a cascata de torrents é dispensada.
     *
     * O torrent vivo existe e está saudável, mas não é consultado: a fonte direta
     * toca sem esperar malha, então perguntar aos trackers depois dela só somaria
     * latência a uma exibição que já pode começar. O `never()` no `buscar()` é o
     * que trava esse contrato — sem ele, uma futura "soma das duas origens" voltaria
     * a arrastar a espera dos trackers para o caminho rápido.
     */
    public function test_stream_direto_dispensa_a_cascata_quando_ha_fonte_direta(): void
    {
        $catalogo = Mockery::mock(CatalogoProvedores::class);
        $catalogo->shouldReceive('buscarFallbackDireto')->andReturn([$this->fonteDireta('socorro')]);
        $catalogo->shouldReceive('buscar')->never();
        $catalogo->shouldReceive('dispensarCascata')->once()->andReturnNull();
        $catalogo->shouldReceive('reconciliarCenso')->andReturnNull();
        $catalogo->shouldReceive('fecharOrcamento')->andReturnNull();
        $catalogo->shouldReceive('temDublado')->andReturn(true);

        $servico = new TorrentService($catalogo, new OrcamentoBusca());

        $fontes = $servico->fontes('Filme Raro');

        $this->assertCount(1, $fontes, 'A lista é só a fonte direta: a cascata nem entrou.');
        $this->assertSame('direto', $fontes[0]['tipo']);
        $this->assertSame('https://exemplo.test/socorro.mp4', $fontes[0]['stream']);
    }

    /**
     * O outro lado do corte: sem fonte direta, a cascata é a busca.
     *
     * A dispensa da cascata não pode virar um caminho sem volta — quando o acervo
     * web não tem o título, é ela que responde, e é ela que precisa ser consultada
     * uma única vez e ter o censo preservado.
     */
    public function test_cascata_roda_quando_o_direto_volta_vazio(): void
    {
        $catalogo = Mockery::mock(CatalogoProvedores::class);
        $catalogo->shouldReceive('buscarFallbackDireto')->andReturn([]);
        $catalogo->shouldReceive('buscar')->once()->andReturn([$this->fonteTorrent('viva')]);
        $catalogo->shouldReceive('dispensarCascata')->never();
        $catalogo->shouldReceive('reconciliarCenso')->andReturnNull();
        $catalogo->shouldReceive('fecharOrcamento')->andReturnNull();
        $catalogo->shouldReceive('temDublado')->andReturn(true);

        $servico = new TorrentService($catalogo, new OrcamentoBusca());

        $fontes = $servico->fontes('Filme Raro');

        $this->assertCount(1, $fontes, 'Sem fonte direta, o torrent vivo responde pela busca.');
        $this->assertSame('torrent', $fontes[0]['tipo']);
    }

    /**
     * O caso do conteúdo PT-BR raro: só veio original.
     *
     * Quando o Torrentio devolve apenas releases em inglês, o corte duro de
     * idioma esvaziaria a lista de torrents — o original não é resposta para quem
     * pediu português. Com a fonte direta na mão, porém, a cascata nem é
     * consultada: o stream direto responde sozinho, e a lista final tem uma única
     * fonte, tocável, em PT-BR.
     */
    public function test_stream_direto_e_a_unica_fonte_quando_so_veio_original(): void
    {
        $original = $this->fonteTorrent('original', 'en');
        $original['idioma'] = 'original';
        $original['pt_br'] = false;

        $servico = $this->servicoComFontes([$original], [$this->fonteDireta('web')]);

        $fontes = $servico->fontes('Filme Raro');

        $this->assertCount(1, $fontes, 'O original em inglês não entra, e a cascata nem chega a ser ouvida.');
        $this->assertSame('direto', $fontes[0]['tipo']);
        $this->assertSame('https://exemplo.test/web.mp4', $fontes[0]['stream']);
    }

    /**
     * O controle do caso acima: sem PT-BR e sem fonte direta, a lista sai vazia.
     *
     * O original em inglês não volta como consolação — o corte duro de idioma o
     * descarta, e o stream direto, tendo voltado vazio, não repõe nada. A lista
     * vazia é a resposta correta: o cliente mostra "sem fontes" em vez de oferecer
     * um release que o usuário não pediu.
     */
    public function test_lista_sai_vazia_quando_nao_ha_pt_br_nem_stream_direto(): void
    {
        $original = $this->fonteTorrent('original', 'en');
        $original['idioma'] = 'original';
        $original['pt_br'] = false;

        $servico = $this->servicoComFontes([$original], []);

        $fontes = $servico->fontes('Filme Raro');

        $this->assertCount(0, $fontes, 'Sem PT-BR e sem fallback, a lista sai vazia — o original não volta.');
    }

    /**
     * A montagem direta preenche os campos do contrato e deriva o id da URL.
     *
     * Chamamos a trait por reflexão sobre um objeto anônimo que a usa: é o mesmo
     * caminho que o provedor percorre, sem montar as dependências dele.
     */
    public function test_montar_fonte_direta_preenche_contrato(): void
    {
        $objeto = new class {
            use NormalizaFonte;
        };

        $metodo = new \ReflectionMethod($objeto, 'montarFonteDireta');
        $metodo->setAccessible(true);

        $fonte = $metodo->invoke($objeto, [
            'titulo' => 'Filme Raro Dublado 1080p',
            'url' => 'https://exemplo.test/video.mp4?token=abc',
            'idioma' => 'pt-BR',
        ], 'stream_direto', 'Stream direto');

        $this->assertSame('direto', $fonte['tipo']);
        $this->assertSame('https://exemplo.test/video.mp4?token=abc', $fonte['stream']);
        $this->assertSame('', $fonte['magnet']);
        $this->assertSame('stream_direto', $fonte['provedor']);
        $this->assertNotSame('', $fonte['id'], 'O id precisa ser derivado da URL quando não vem pronto.');
    }

    /**
     * O provedor direto é desligado por padrão.
     *
     * A configuração nasce com `stream_direto_habilitado = false` para que a
     * busca comum não pague uma consulta extra sem retorno. O teste lê o padrão
     * direto do arquivo de config, e não do ambiente: quem liga a chave no `.env`
     * para testar o fallback não pode ver este contrato quebrar.
     */
    public function test_provedor_direto_vem_desligado_por_padrao(): void
    {
        /*
         * A chave é limpa do ambiente antes da leitura, e a limpeza é o ponto
         * do teste. O `docker compose` exporta o `.env` para dentro do
         * container, então quem ligou o fallback para testá-lo tem a chave
         * valendo `true` no processo — e o `env()` leria o `.env` em vez do
         * padrão que o repositório entrega. Sem isolar o ambiente, o contrato
         * que este teste protege desaparecia justamente na configuração mais
         * comum de desenvolvimento.
         */
        putenv('TORRENTS_STREAM_DIRETO_HABILITADO');
        unset($_ENV['TORRENTS_STREAM_DIRETO_HABILITADO'], $_SERVER['TORRENTS_STREAM_DIRETO_HABILITADO']);

        $padrao = require config_path('services.php');

        $this->assertFalse(
            (bool) $padrao['torrents']['stream_direto_habilitado'],
            'O fallback de stream direto deve nascer desligado.'
        );
    }

    /**
     * A cascata é consultada mesmo quando a lista de títulos vem vazia.
     *
     * Antes havia um `return []` preventivo: sem termo, a busca era abortada
     * antes de qualquer provedor ser ouvido, e o usuário recebia "nenhuma fonte
     * encontrada" sem que o Torrentio tivesse sido consultado. Os provedores por
     * identificador respondem pelo `imdb_id`, então uma lista de termos vazia não
     * é motivo para não perguntar. Este teste trava a chamada à cascata.
     */
    public function test_cascata_e_consultada_mesmo_com_titulo_vazio(): void
    {
        $catalogo = Mockery::mock(CatalogoProvedores::class);
        $catalogo->shouldReceive('buscar')->once()->andReturn([]);
        $catalogo->shouldReceive('buscarFallbackDireto')->andReturn([]);
        $catalogo->shouldReceive('reconciliarCenso')->andReturnNull();
        $catalogo->shouldReceive('fecharOrcamento')->andReturnNull();

        $servico = new TorrentService($catalogo, new OrcamentoBusca());

        $fontes = $servico->fontes('');

        $this->assertSame([], $fontes);
    }

    /**
     * O fallback recebe o título original junto do traduzido.
     *
     * A cascata só consulta o original quando `valeSegundaTentativa()` manda, mas
     * o scraper web não tem esse custo: ele pergunta pelo nome mais provável, e o
     * título original é o que funciona para o conteúdo raro ("Desperate
     * Housewives" acha o que "Donas de Casa Desesperadas" não acha). Sem isto, um
     * título cuja tradução não rendeu termo nenhum chegaria ao fallback sem pista.
     */
    public function test_fallback_recebe_titulo_traduzido_e_original(): void
    {
        $recebidos = null;

        $catalogo = Mockery::mock(CatalogoProvedores::class);
        $catalogo->shouldReceive('buscar')->andReturn([]);
        // O stream direto roda primeiro, antes da segunda fase da cascata, então a
        // lista de termos dele nasce do traduzido mais o original.
        $catalogo->shouldReceive('ptBrSuficiente')->andReturnFalse();
        $catalogo->shouldReceive('buscarFallbackDireto')
            ->once()
            ->andReturnUsing(function (array $titulos) use (&$recebidos): array {
                $recebidos = $titulos;

                return [];
            });
        $catalogo->shouldReceive('reconciliarCenso')->andReturnNull();
        $catalogo->shouldReceive('fecharOrcamento')->andReturnNull();

        $servico = new TorrentService($catalogo, new OrcamentoBusca());

        $servico->fontes('Donas de Casa Desesperadas', null, null, 'Desperate Housewives');

        $this->assertContains('Donas de Casa Desesperadas', $recebidos, 'O título traduzido precisa chegar ao fallback.');
        $this->assertContains('Desperate Housewives', $recebidos, 'O título original precisa chegar ao fallback.');
    }

    /**
     * O stream direto não repete o título quando ele já está na lista.
     *
     * O mesmo título pode chegar por dois caminhos (traduzido e original, ou o
     * `$titulos` já preenchido); acrescentá-lo de novo faria o scraper repetir a
     * consulta. A deduplicação protege o orçamento do stream direto.
     */
    public function test_stream_direto_nao_duplica_titulo(): void
    {
        $recebidos = null;

        $catalogo = Mockery::mock(CatalogoProvedores::class);
        $catalogo->shouldReceive('buscar')->andReturn([]);
        $catalogo->shouldReceive('buscarFallbackDireto')
            ->once()
            ->andReturnUsing(function (array $titulos) use (&$recebidos): array {
                $recebidos = $titulos;

                return [];
            });
        $catalogo->shouldReceive('reconciliarCenso')->andReturnNull();
        $catalogo->shouldReceive('fecharOrcamento')->andReturnNull();

        $servico = new TorrentService($catalogo, new OrcamentoBusca());

        // Sem título original distinto, a lista do stream direto deve conter só o
        // título traduzido, uma única vez.
        $servico->fontes('Filme Raro');

        $this->assertSame(['Filme Raro'], $recebidos, 'Sem original distinto, o fallback recebe só o traduzido.');
    }
}
