<?php

namespace Tests\Unit;

use App\Services\Torrents\LeitorVideobox;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O leitor do videobox é o degrau que faltava no acervo do Pobreflix.
 *
 * O `pobreflixtv.democrat` não entrega arquivo nem iframe: a página do título
 * chega com o player vazio e o JavaScript é quem pergunta ao site quais
 * servidores atendem aquele id. Cada teste aqui trava um trecho dessa travessia
 * — a leitura do id, o endpoint dos servidores, a expressão do `robotlink` no
 * StreamTape e a confirmação de que a origem devolve vídeo — sem tocar na rede.
 *
 * O que a bateria protege, antes de tudo, é o **silêncio**: página sem id,
 * player que não responde, servidor sem áudio dublado e sonda que devolve HTML
 * continuam virando `null`, e a busca segue como se o site não tivesse o título.
 */
class LeitorVideoboxTest extends TestCase
{
    private const PAGINA = 'https://www.pobreflixtv.democrat/filmes/online/homem-aranha-um-novo-dia-dublado-74844/';

    private const PLAYER_DATA = 'https://www.pobreflixtv.democrat/index.php?app=videobox&module=video&controller=view&do=playerData&id=74844';

    /** O token que o JavaScript realmente usa, montado por substring. */
    private const TOKEN_BOM = 'jHCummFk8DfS';

    /** O token isca, publicado no `div` para quem não abre o script. */
    private const TOKEN_FALSO = 'jHCummFk8cde';

    private function leitor(): LeitorVideobox
    {
        return app(LeitorVideobox::class);
    }

    /**
     * A página do título, como o site a entrega sem navegador: player vazio e o
     * id do vídeo no atributo.
     */
    private function pagina(): string
    {
        return '<html><head><base href="https://www.pobreflixtv.democrat/"></head><body>'
            .'<div id="VideoContent"><div id="view" data-video-id="74844" data-tmdb-id="969681" '
            .'data-temp="" data-ep=""></div></div>'
            .'<script src="https://www.pobreflixtv.democrat/uploads/javascript_videobox/front.js"></script>'
            .'</body></html>';
    }

    /**
     * O JSON do endpoint do player, com os quatro servidores e os dois áudios —
     * inclusive o `&amp;` que o site publica no meio da lista.
     */
    private function dadosDoPlayer(bool $comDublado = true): string
    {
        return (string) json_encode([
            'players' => [
                ['label' => 'Byse', 'url' => 'https://bysebuho.com/e/', 'downloadUrl' => 'https://bysebuho.com/d/'],
                ['label' => 'DoodStream', 'url' => 'https://playmogo.com/e/', 'downloadUrl' => 'https://playmogo.com/d/'],
                ['label' => 'MixDrop', 'url' => 'https://mixdrop.top/e/', 'downloadUrl' => 'https://mixdrop.top/f/'],
                ['label' => 'StreamTape', 'url' => 'https://streamtape.com/e/', 'downloadUrl' => 'https://streamtape.com/v/'],
            ],
            'servers_dub' => $comDublado
                ? 'mixdrop=l77e7vmefg4nox&amp;streamtape=2aZO3qdDbliZMZk&byse=04fn1bx7ics4&doodstream=omwmsx07eh2o'
                : '',
            'servers_leg' => 'mixdrop=dkkjnenrs09dxl&streamtape=3948z4aYAjtdVDg&byse=8i3yxupshtrl&doodstream=9986ygocy4cf',
            'is_episode' => '',
            'navigation' => ['prev' => '', 'next' => '', 'series' => ''],
            'current_audio' => 'Dublado',
        ]);
    }

    /**
     * O embed do StreamTape, com a expressão que monta o `get_video`.
     *
     * O `div` publica um token e o script monta outro: é a isca que separa quem
     * lê o HTML de quem segue o player de verdade.
     */
    private function embedStreamtape(): string
    {
        return '<html><body>'
            .'<div id="robotlink" style="display:none;">/streamtape.com/get_video?id=2aZO3qdDbliZMZk'
            .'&expires=1792975775&ip=F0uVKRWSDS9XKxR&token='.self::TOKEN_FALSO.'</div>'
            .'<script>document.getElementById(\'robotlink\').innerHTML = \'//streamtape.com/get_vid\'+ '
            .'(\'xcdeo?id=2aZO3qdDbliZMZk&expires=1792975775&ip=F0uVKRWSDS9XKxR&token='.self::TOKEN_BOM.'\')'
            .'.substring(2).substring(1);</script>'
            .'</body></html>';
    }

    /**
     * Fixa as três respostas da cadeia de sucesso.
     */
    private function cadeiaCompleta(string $prova = 'video'): void
    {
        Http::fake([
            'https://streamtape.com/e/*' => Http::response($this->embedStreamtape()),
            'https://streamtape.com/get_video*' => match ($prova) {
                'html' => Http::response('{"status":500,"msg":"erro"}', 200, ['Content-Type' => 'text/html']),
                default => Http::response('....ftypisom', 206, ['Content-Type' => 'video/mp4']),
            },
            self::PLAYER_DATA => Http::response($this->dadosDoPlayer(), 200, ['Content-Type' => 'application/json']),
            '*' => Http::response('resposta não esperada'),
        ]);
    }

    public function test_resolve_o_dublado_ate_o_arquivo_do_streamtape(): void
    {
        $this->cadeiaCompleta();

        $resolvido = $this->leitor()->resolver($this->pagina(), self::PAGINA, 10);

        $this->assertNotNull($resolvido);
        $this->assertSame('dublado', $resolvido['idioma']);

        // A URL sai da concatenação do script, com o token bom — e sem `stream=1`,
        // que não faz parte da expressão do player.
        $this->assertSame(
            'https://streamtape.com/get_video?id=2aZO3qdDbliZMZk&expires=1792975775'
            .'&ip=F0uVKRWSDS9XKxR&token='.self::TOKEN_BOM,
            $resolvido['url']
        );

        $this->assertStringNotContainsString(self::TOKEN_FALSO, $resolvido['url']);
    }

    /**
     * O endpoint dos servidores é pedido ao host do `<base>`, com o cabeçalho
     * que o site exige para responder JSON em vez da casca da página.
     */
    public function test_o_player_e_consultado_no_host_do_base_com_xhr(): void
    {
        $this->cadeiaCompleta();

        $this->leitor()->resolver($this->pagina(), self::PAGINA, 10);

        Http::assertSent(function ($requisicao) {
            return $requisicao->url() === self::PLAYER_DATA
                && $requisicao->hasHeader('X-Requested-With', 'XMLHttpRequest')
                && $requisicao->hasHeader('Referer', self::PAGINA);
        });
    }

    public function test_pagina_sem_video_id_devolve_null(): void
    {
        Http::fake(['*' => Http::response('nada deveria ser pedido')]);

        $semId = '<html><body><div id="view"></div><script src="/videobox.js"></script></body></html>';

        $this->assertNull($this->leitor()->resolver($semId, self::PAGINA, 10));
        Http::assertNothingSent();
    }

    /**
     * O `data-video-id` sozinho não prova player: sem a marca do videobox, a
     * página é de outra coisa e o endpoint não existe.
     */
    public function test_data_video_id_sem_videobox_nao_basta(): void
    {
        Http::fake(['*' => Http::response('nada deveria ser pedido')]);

        $outroSite = '<html><body><div data-video-id="74844"></div></body></html>';

        $this->assertNull($this->leitor()->resolver($outroSite, self::PAGINA, 10));
        Http::assertNothingSent();
    }

    public function test_video_sem_servidor_dublado_devolve_null(): void
    {
        Http::fake([
            self::PLAYER_DATA => Http::response($this->dadosDoPlayer(false), 200, ['Content-Type' => 'application/json']),
            '*' => Http::response('não deveria abrir o embed sem dublado'),
        ]);

        $this->assertNull($this->leitor()->resolver($this->pagina(), self::PAGINA, 10));
    }

    /**
     * Token que não vale devolve `200` com JSON de erro. O status não denuncia,
     * e é a assinatura do corpo que separa o MP4 da página de erro.
     */
    public function test_arquivo_que_nao_e_video_e_recusado(): void
    {
        $this->cadeiaCompleta('html');

        $this->assertNull($this->leitor()->resolver($this->pagina(), self::PAGINA, 10));
    }

    public function test_player_que_nao_responde_devolve_null(): void
    {
        Http::fake([
            self::PLAYER_DATA => Http::response('', 500),
            '*' => Http::response('não deveria abrir o embed'),
        ]);

        $this->assertNull($this->leitor()->resolver($this->pagina(), self::PAGINA, 10));
    }

    /**
     * A montagem da expressão é o que separa o token bom do isca.
     *
     * O método é privado, e o teste o chama direto para travar a matemática do
     * `substring` sem depender de rede: o literal entra inteiro, o corte é
     * aplicado em ordem, e a base e o pedaço se encontram no `get_video`.
     */
    public function test_montagem_da_expressao_aplica_os_cortes_em_ordem(): void
    {
        $leitor = $this->leitor();
        $metodo = new \ReflectionMethod($leitor, 'montarEndereco');
        $metodo->setAccessible(true);

        $expressao = "'//streamtape.com/get_vid'+ ('xcdeo?id=ABC&token=".self::TOKEN_BOM."')"
            .'.substring(2).substring(1);';

        $this->assertSame(
            'https://streamtape.com/get_video?id=ABC&token='.self::TOKEN_BOM,
            $metodo->invoke($leitor, $expressao)
        );
    }

    public function test_montagem_recusa_expressao_sem_token(): void
    {
        $leitor = $this->leitor();
        $metodo = new \ReflectionMethod($leitor, 'montarEndereco');
        $metodo->setAccessible(true);

        $this->assertNull($metodo->invoke($leitor, "'https://streamtape.com/e/abc'"));
    }
}
