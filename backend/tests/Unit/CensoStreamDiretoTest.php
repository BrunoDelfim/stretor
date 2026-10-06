<?php

namespace Tests\Unit;

use App\Enums\IdiomaFonte;
use App\Services\Torrents\CatalogoProvedores;
use App\Services\Torrents\InspecaoPack;
use App\Services\Torrents\OrcamentoBusca;
use App\Services\Torrents\ProvedorAddonStremio;
use App\Services\Torrents\ProvedorApibay;
use App\Services\Torrents\ProvedorBt4g;
use App\Services\Torrents\ProvedorKnaben;
use App\Services\Torrents\ProvedorStreamDireto;
use App\Services\Torrents\ProvedorTorrentio;
use App\Services\Torrents\ProvedorTorznab;
use App\Services\Torrents\ProvedorTrackersBr;
use App\Services\Torrents\ProvedorYts;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Trava o censo do stream direto dentro da busca que roda os dois métodos.
 *
 * O stream direto não passa pela cascata: é acionado à parte, antes dela, e o
 * seu censo é preenchido à mão em `buscarFallbackDireto()`. Como a cascata roda
 * em seguida, na mesma busca, e o `buscar()` dela zera o censo, o registro do
 * stream direto tem de sobreviver — sem isso, o relatório diria `nao_consultado`
 * de um provedor que rodou, que é justamente a mentira que o censo existe para
 * evitar.
 *
 * O outro lado da mesma moeda: o censo que sobrevive **não** pode virar veredito
 * para a cascata. Um acerto do stream direto não dispensa os termos de socorro
 * (pack e série) dela — é o que `jaTemEpisodioAproveitado()` garante ao ignorar
 * o provedor direto na conta.
 *
 * O catálogo é montado com dublês e nada aqui toca em rede ou banco.
 */
class CensoStreamDiretoTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * Dublê de um provedor do registro, só com o que o censo lê.
     */
    private function duble(string $classe, string $id): mixed
    {
        $duble = Mockery::mock($classe);
        $duble->shouldReceive('identificador')->andReturn($id);
        $duble->shouldReceive('rotulo')->andReturn($id);
        $duble->shouldReceive('disponivel')->andReturn(true);

        return $duble;
    }

    /**
     * Monta o catálogo com todos os provedores substituídos por dublês.
     *
     * O stream direto é um dublê que responde o que o teste pedir em
     * `$fontesDiretas` — vazio no caso comum, porque o que interessa ali é o
     * registro da consulta no censo, não as fontes que ele devolveria. O censo dos
     * agregadores vem preenchido à mão — é o que ele devolveria de verdade quando o
     * primeiro agregador da ordem acha a página —, para o teste provar que ele
     * chega à cobertura.
     *
     * @param  array<int, array<string, mixed>>  $fontesDiretas
     */
    private function catalogo(array $fontesDiretas = []): CatalogoProvedores
    {
        $streamDireto = Mockery::mock(ProvedorStreamDireto::class);
        $streamDireto->shouldReceive('identificador')->andReturn('stream_direto');
        $streamDireto->shouldReceive('rotulo')->andReturn('Stream direto');
        $streamDireto->shouldReceive('disponivel')->andReturn(true);
        $streamDireto->shouldReceive('buscarComTitulos')->andReturn($fontesDiretas);
        $streamDireto->shouldReceive('censoDosAgregadores')->andReturn([
            [
                'agregador' => 'verpobreflix.net',
                'situacao' => 'com_pagina',
                'consultas' => 1,
                'paginas' => 1,
                'ms' => 320,
                'titulo' => 'Donas de Casa Desesperadas',
                'encontradas' => ['https://verpobreflix.net/serie/693/1/1'],
            ],
            [
                'agregador' => 'superflixapi.quest',
                'situacao' => 'nao_consultado',
                'consultas' => 0,
                'paginas' => 0,
                'ms' => 0,
                'titulo' => '',
                'encontradas' => [],
            ],
        ]);

        return new CatalogoProvedores(
            $this->duble(ProvedorTrackersBr::class, 'trackers-br'),
            $this->duble(ProvedorApibay::class, 'apibay'),
            $this->duble(ProvedorKnaben::class, 'knaben'),
            $this->duble(ProvedorTorrentio::class, 'torrentio'),
            $this->duble(ProvedorAddonStremio::class, 'addon-stremio'),
            $this->duble(ProvedorBt4g::class, 'bt4g'),
            $this->duble(ProvedorTorznab::class, 'torznab'),
            $this->duble(ProvedorYts::class, 'yts'),
            Mockery::mock(InspecaoPack::class),
            new OrcamentoBusca(),
            $streamDireto,
        );
    }

    /**
     * Extrai a situação de um provedor do relatório de cobertura.
     */
    private function situacao(CatalogoProvedores $catalogo, string $id): ?string
    {
        foreach ($catalogo->cobertura() as $linha) {
            if ($linha['provedor'] === $id) {
                return $linha['situacao'];
            }
        }

        return null;
    }

    /**
     * Uma fonte direta marcada com o **site de origem** continua contando na
     * linha `stream_direto` do censo.
     *
     * A marcação da origem mudou o `provedor` da fonte direta: ele passou a
     * carregar o agregador (o que o usuário reconhece na lista), não o nome do
     * método. Quem casa a fonte com a linha do censo é `linhaDoCenso()`, pelo
     * `tipo`. Sem esse desvio, as duas contas que dependem do casamento — as
     * `aproveitadas` do gate e o `na_lista` da montagem — ficariam zeradas para o
     * stream direto, e a cobertura diria `barrado_no_filtro` de uma busca que
     * achou o episódio.
     */
    public function test_fonte_direta_marcada_com_a_origem_conta_na_linha_do_stream_direto(): void
    {
        $catalogo = $this->catalogo($this->fontesDiretas('verpobreflix.net'));

        $catalogo->buscarFallbackDireto(['Donas de Casa Desesperadas'], 2004, null, 1, 1);
        $catalogo->reconciliarCenso($this->fontesDiretas('verpobreflix.net'));

        $linha = collect($catalogo->cobertura())->firstWhere('provedor', 'stream_direto');

        $this->assertSame(1, $linha['na_lista'], 'A fonte direta na lista pertence à linha do stream direto.');
        $this->assertSame(
            'com_fonte',
            $this->situacao($catalogo, 'stream_direto'),
            'A linha do stream direto precisa dizer com_fonte quando a fonte está na lista.'
        );
    }

    /**
     * O caso central: o stream direto roda primeiro, volta vazio, e a cascata de
     * torrents assume. O censo do stream direto precisa continuar registrando a
     * consulta — `sem_resultado`, e não `nao_consultado`.
     */
    public function test_censo_do_stream_direto_sobrevive_a_cascata_de_torrents(): void
    {
        $catalogo = $this->catalogo();

        // O primeiro método da busca é o stream direto.
        $catalogo->buscarFallbackDireto(['Donas de Casa Desesperadas'], 2004, null, 1, 1);

        $this->assertSame(
            'sem_resultado',
            $this->situacao($catalogo, 'stream_direto'),
            'O stream direto rodou e voltou vazio: o censo deve dizer sem_resultado.'
        );

        // A cascata de torrents roda depois, na mesma busca, e zera o censo no início.
        $catalogo->buscar(['Donas de Casa Desesperadas S01E01'], 2004, null, 1, 1);

        $this->assertSame(
            'sem_resultado',
            $this->situacao($catalogo, 'stream_direto'),
            'A cascata de torrents não pode apagar o registro do stream direto já consultado.'
        );
    }

    /**
     * O controle: sem o stream direto ter rodado, a cascata o deixa em
     * `nao_consultado`. A preservação não pode inventar uma consulta que não houve.
     */
    public function test_stream_direto_nao_consultado_continua_nao_consultado(): void
    {
        $catalogo = $this->catalogo();

        $catalogo->buscar(['Donas de Casa Desesperadas S01E01'], 2004, null, 1, 1);

        $this->assertSame(
            'nao_consultado',
            $this->situacao($catalogo, 'stream_direto'),
            'Sem consulta ao stream direto, o censo deve continuar dizendo nao_consultado.'
        );
    }

    /**
     * A linha do stream direto carrega o censo dos agregadores dentro dela.
     *
     * É o que responde "0 fontes com 1 consulta" sem deixar dúvida: a consulta ao
     * provedor aconteceu e ele de fato perguntou ao verpobreflix — que devolveu a
     * página do episódio —, e o superflix, que vem depois na ordem, nem foi
     * acionado. Sem esta chave, o relatório diria o mesmo de uma varredura que nem
     * chegou a tocar no agregador.
     */
    public function test_cobertura_do_stream_direto_traz_os_agregadores(): void
    {
        $catalogo = $this->catalogo();

        $catalogo->buscarFallbackDireto(['Donas de Casa Desesperadas'], 2004, null, 1, 1);

        $linha = collect($catalogo->cobertura())->firstWhere('provedor', 'stream_direto');

        $this->assertSame('verpobreflix.net', $linha['agregadores'][0]['agregador']);
        $this->assertSame('com_pagina', $linha['agregadores'][0]['situacao']);
        $this->assertSame(
            ['https://verpobreflix.net/serie/693/1/1'],
            $linha['agregadores'][0]['encontradas'],
            'Os endereços entregues pelo agregador são o que prova onde a busca parou.'
        );
        $this->assertSame(
            'nao_consultado',
            $linha['agregadores'][1]['situacao'],
            'Um agregador que a cascata não chegou a consultar precisa aparecer como tal.'
        );
    }

    /**
     * Os demais provedores não têm um nível abaixo: a chave existe, mas vazia.
     *
     * A ausência da chave obrigaria o consumidor a um `??` linha a linha, e a
     * diferença entre "não tem agregadores" e "não foi consultado" voltaria a se
     * esconder — que é justamente o problema que o censo resolve.
     */
    public function test_provedor_sem_agregadores_devolve_a_chave_vazia(): void
    {
        $catalogo = $this->catalogo();

        $catalogo->buscar(['Donas de Casa Desesperadas S01E01'], 2004, null, 1, 1);

        $linha = collect($catalogo->cobertura())->firstWhere('provedor', 'torrentio');

        $this->assertSame([], $linha['agregadores']);
    }

    /**
     * Dublê de um provedor primário que responde o episódio pedido.
     *
     * O contador de chamadas é o que prova se o termo de socorro foi dispensado
     * antes de o provedor ser perguntado: quando o corte atua, `buscar()` não é
     * chamado nenhuma vez.
     *
     * @param  int  $chamadas  Contador das consultas, por referência
     */
    private function provedorComEpisodio(int &$chamadas): mixed
    {
        $provedor = $this->duble(ProvedorTorrentio::class, 'torrentio');

        $provedor->shouldReceive('buscar')->andReturnUsing(function () use (&$chamadas): array {
            $chamadas++;

            return [[
                'id' => 'episodio-dublado-'.$chamadas,
                'titulo' => 'Donas de Casa Desesperadas S01E01 720p Dublado',
                'release' => 'Donas de Casa Desesperadas S01E01 720p Dublado',
                'idioma' => IdiomaFonte::DUBLADO->value,
                'idioma_rotulo' => IdiomaFonte::DUBLADO->rotulo(),
                'provedor' => 'torrentio',
                'provedor_rotulo' => 'Torrentio',
            ]];
        });

        return $provedor;
    }

    /**
     * Invoca um grupo da cascata como o `buscar()` faz, com um só provedor.
     *
     * `buscarGrupo()` é privado, então o teste o chama por reflexão — o mesmo
     * caminho já usado nos testes de gate e de cache. `$dispensarSocorro` replica
     * a rodada de abertura, em que os provedores por identificador e os primários
     * correm juntos e o corte do socorro não vale.
     */
    private function buscarGrupo(
        CatalogoProvedores $catalogo,
        mixed $provedor,
        string $titulo,
        bool $termoDePack,
        bool $dispensarSocorro = false,
    ): array {
        $metodo = new ReflectionMethod(CatalogoProvedores::class, 'buscarGrupo');
        $metodo->setAccessible(true);

        return $metodo->invoke(
            $catalogo,
            [$provedor],
            $titulo,
            2004,
            null,
            1,
            1,
            $termoDePack,
            false,
            [],
            $dispensarSocorro,
        );
    }

    /**
     * Uma fonte direta no contrato do frontend, como o scraper a entrega.
     *
     * O `provedor` de uma fonte direta é o **site de origem** (o agregador onde o
     * vídeo foi achado) — é assim que o provedor a monta hoje. O parâmetro existe
     * para o teste escolher a origem; sem ele, a fonte sai rotulada pelo nome do
     * método, como era antes da marcação.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fontesDiretas(?string $origem = null): array
    {
        return [[
            'id' => md5('https://superflixapi.quest/serie/693/1/1'),
            'tipo' => 'direto',
            'titulo' => 'Donas de Casa Desesperadas S01E01 Dublado',
            'qualidade' => '720P',
            'idioma' => IdiomaFonte::DUBLADO->value,
            'idioma_rotulo' => IdiomaFonte::DUBLADO->rotulo(),
            'seeds' => 1,
            'peers' => 0,
            'magnet' => '',
            'stream' => 'https://superflixapi.quest/serie/693/1/1',
            'provedor' => $origem ?? 'stream_direto',
            'provedor_rotulo' => $origem !== null ? ucfirst($origem) : 'Stream direto',
        ]];
    }

    /**
     * Um acerto do stream direto **não** pode calar o socorro da cascata.
     *
     * O censo do stream direto sobrevive ao `buscar()` da cascata, então o
     * `aproveitadas` dele chegava à conta de `jaTemEpisodioAproveitado()` como o
     * de qualquer outro provedor. O efeito era o sintoma que o censo existe para
     * evitar: uma única URL achada no acervo web dispensava os termos de pack e de
     * série, e os packs nacionais — que só os trackers enxergam — deixavam de ser
     * perguntados. "Só veio do superflix."
     *
     * O teste prova as duas metades: o censo do direto ficou com `aproveitadas`
     * acima de zero (senão não haveria poluição a temer) e o provedor da cascata
     * foi consultado mesmo assim.
     */
    public function test_stream_direto_nao_dispensa_o_socorro_da_cascata(): void
    {
        $catalogo = $this->catalogo($this->fontesDiretas());
        $chamadas = 0;
        $provedor = $this->provedorComEpisodio($chamadas);

        $catalogo->buscarFallbackDireto(['Donas de Casa Desesperadas'], 2004, null, 1, 1);

        $this->assertSame(
            'descartado_na_montagem',
            $this->situacao($catalogo, 'stream_direto'),
            'O acerto do stream direto precisa entrar no censo como fonte aproveitada.'
        );

        $fontes = $this->buscarGrupo(
            $catalogo,
            $provedor,
            'Donas de Casa Desesperadas S01 completa',
            true,
        );

        $this->assertSame(1, $chamadas, 'O termo de socorro da cascata precisa ser perguntado.');
        $this->assertNotEmpty($fontes, 'O que a cascata achar no socorro precisa voltar.');
    }

    /**
     * O controle: um acerto da **cascata** dispensa o socorro — é o corte que
     * evita multiplicar requisições quando o episódio já tem resposta.
     *
     * Sem esta metade, uma exclusão larga demais em `jaTemEpisodioAproveitado()`
     * teria furado o corte por completo, e o teste anterior passaria do mesmo
     * jeito.
     */
    public function test_acerto_da_cascata_dispensa_o_socorro(): void
    {
        $catalogo = $this->catalogo();
        $chamadas = 0;
        $provedor = $this->provedorComEpisodio($chamadas);

        // Rodada de abertura: o episódio é achado pelo termo de episódio.
        $this->buscarGrupo(
            $catalogo,
            $provedor,
            'Donas de Casa Desesperadas S01E01',
            false,
            true,
        );

        $this->assertSame(1, $chamadas, 'A rodada de abertura precisa perguntar ao provedor.');

        // O termo de socorro seguinte já encontra o episódio resolvido no censo.
        $fontes = $this->buscarGrupo(
            $catalogo,
            $provedor,
            'Donas de Casa Desesperadas S01 completa',
            true,
        );

        $this->assertSame([], $fontes, 'Com o episódio já aproveitado, o socorro devolve vazio.');
        $this->assertSame(1, $chamadas, 'O provedor não pode ser perguntado de novo no socorro.');
    }
}
