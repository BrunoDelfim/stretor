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
 * cascata de torrents e entra na frente dela na lista final, porque não depende
 * de malha. O risco desta expansão é silencioso — a fonte direta não tem `seeds`
 * nem `magnet`, então os filtros que sempre valeram para torrent a descartariam
 * sem que ninguém percebesse. Estes testes fixam os pontos que a salvam: o ramo
 * `tipo === 'direto'` em `ordenar()` (para as diretas que chegam pela cascata) e
 * a montagem que preenche `stream` com `magnet` vazio.
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
     * Os dois métodos são dublados à parte porque os dois rodam e compõem a
     * lista: `buscar()` devolve a cascata de torrents e `buscarFallbackDireto()`
     * devolve o stream direto. Assim o teste controla cada método sem tocar o
     * catálogo real nem em rede.
     *
     * @param  array<int, array<string, mixed>>  $fontes
     * @param  array<int, array<string, mixed>>  $diretas
     */
    private function servicoComFontes(array $fontes, array $diretas = []): TorrentService
    {
        $catalogo = Mockery::mock(CatalogoProvedores::class);
        $catalogo->shouldReceive('buscar')->andReturn($fontes);
        $catalogo->shouldReceive('buscarFallbackDireto')->andReturn($diretas);
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
     * A fonte direta cobre o caso em que os torrents são todos descartados.
     *
     * O Torrentio devolveu uma fonte, mas ela é lixo: sem seeds, o filtro de
     * `ordenar()` a descarta. Quando isso acontece, a lista final fica só com o
     * que o stream direto trouxe — que é exatamente o papel dele: cobrir o
     * conteúdo que os trackers não entregam vivo.
     */
    public function test_stream_direto_cobre_quando_os_torrents_sao_descartados(): void
    {
        $lixo = $this->fonteTorrent('lixo');
        $lixo['seeds'] = 0;

        $servico = $this->servicoComFontes([$lixo], [$this->fonteDireta('socorro')]);

        $fontes = $servico->fontes('Filme Raro');

        $this->assertCount(1, $fontes, 'O fallback precisa entrar quando o filtro esvazia a lista.');
        $this->assertSame('direto', $fontes[0]['tipo']);
        $this->assertSame('https://exemplo.test/socorro.mp4', $fontes[0]['stream']);
    }

    /**
     * Com torrent vivo, o stream direto entra mesmo assim — e na frente.
     *
     * Este caso substitui o controle antigo ("com torrent vivo, o fallback não
     * dispara"). Aquilo valia quando o stream direto era socorro, acionado só com
     * a lista final vazia. Agora os dois métodos sempre rodam e a lista final é a
     * soma, com o direto na frente: quando o acervo web tem o título, ele toca sem
     * esperar o tracker. O torrent continua na lista, como alternativa.
     */
    public function test_stream_direto_convive_com_torrent_vivo_e_vem_primeiro(): void
    {
        $servico = $this->servicoComFontes([$this->fonteTorrent('viva')], [$this->fonteDireta('socorro')]);

        $fontes = $servico->fontes('Filme Raro');

        $this->assertCount(2, $fontes, 'Os dois métodos compõem a lista.');
        $this->assertSame('direto', $fontes[0]['tipo'], 'O stream direto vem primeiro.');
        $this->assertSame('torrent', $fontes[1]['tipo'], 'O torrent continua como alternativa.');
    }

    /**
     * O caso do conteúdo PT-BR raro: só veio original.
     *
     * Quando o Torrentio devolve apenas releases em inglês, o corte duro de
     * idioma esvazia a lista de torrents — o original não é resposta para quem
     * pediu português. A fonte direta, que já rodou, é a única da lista final.
     */
    public function test_stream_direto_e_a_unica_fonte_quando_so_veio_original(): void
    {
        $original = $this->fonteTorrent('original', 'en');
        $original['idioma'] = 'original';
        $original['pt_br'] = false;

        $servico = $this->servicoComFontes([$original], [$this->fonteDireta('socorro')]);

        $fontes = $servico->fontes('Filme Raro');

        $this->assertCount(1, $fontes, 'Sem PT-BR, o fallback precisa substituir a reserva em inglês.');
        $this->assertSame('direto', $fontes[0]['tipo']);
        $this->assertSame('https://exemplo.test/socorro.mp4', $fontes[0]['stream']);
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
