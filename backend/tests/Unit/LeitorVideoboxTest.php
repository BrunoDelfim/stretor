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

    /*
     * ------------------------------------------------------------------
     * Série: a ficha não serve vídeo, o episódio serve.
     * ------------------------------------------------------------------
     *
     * O `playerData` da ficha (`/series/online/...`) vem com `servers_dub` vazio,
     * e era por isso que série caía na cascata de torrents mesmo com o acervo no
     * site: a página existia, provava ser o título, e não entregava arquivo. O
     * caminho até o episódio é o `episodesList&season=`, que devolve a URL de cada
     * episódio da temporada.
     */

    private const SERIE = 'https://www.pobreflixtv.democrat/series/online/round-6-dublado-1102/';

    private const EPISODIO = 'https://www.pobreflixtv.democrat/episodios/online/round-6-2x2-dublado-1104/';

    private const PLAYER_DATA_SERIE = 'https://www.pobreflixtv.democrat/index.php?app=videobox&module=video&controller=view&do=playerData&id=1102';

    private const PLAYER_DATA_EPISODIO = 'https://www.pobreflixtv.democrat/index.php?app=videobox&module=video&controller=view&do=playerData&id=1104';

    private const EPISODES_LIST = 'https://www.pobreflixtv.democrat/index.php?app=videobox&module=video&controller=view&do=episodesList&id=1102&season=2';

    /** A ficha da série: player vazio, sem numeração de episódio. */
    private function paginaDaSerie(): string
    {
        return '<html><head><base href="https://www.pobreflixtv.democrat/"></head><body>'
            .'<div id="view" data-video-id="1102" data-tmdb-id="93405" data-temp="" data-ep=""></div>'
            .'<button data-season="2">Temporada 2</button>'
            .'<script src="/uploads/javascript_videobox/front.js"></script>'
            .'</body></html>';
    }

    /** A página do episódio, com a numeração preenchida. */
    private function paginaDoEpisodio(): string
    {
        return '<html><head><base href="https://www.pobreflixtv.democrat/"></head><body>'
            .'<div id="view" data-video-id="1104" data-tmdb-id="93405" data-temp="2" data-ep="2"></div>'
            .'<script src="/uploads/javascript_videobox/front.js"></script>'
            .'</body></html>';
    }

    /**
     * O JSON do `episodesList` da temporada 2, como o site o devolve.
     *
     * O primeiro episódio tem URL diferente do segundo de propósito: o teste
     * prova que o leitor escolhe pelo número, e não pela posição na lista.
     */
    private function listaDaTemporada(): string
    {
        return (string) json_encode([
            'canEdit' => '',
            'episodes' => [
                ['number' => '1', 'title' => 'Pão e loteria', 'url' => 'https://www.pobreflixtv.democrat/episodios/online/round-6-2x1-dublado-45685/'],
                ['number' => '2', 'title' => 'Adeus, verão', 'url' => self::EPISODIO],
                ['number' => '3', 'title' => 'Um passo à frente', 'url' => 'https://www.pobreflixtv.democrat/episodios/online/round-6-2x3-dublado-45686/'],
            ],
        ]);
    }

    /** O player da página do episódio declara o StreamTape dublado. */
    private function dadosDoEpisodio(): string
    {
        return (string) json_encode([
            'players' => [
                ['label' => 'StreamTape', 'url' => 'https://streamtape.com/e/'],
            ],
            'servers_dub' => 'mixdrop=pj96zr73i80odw&amp;streamtape=g2YjDDG4vAfxLe',
            'servers_leg' => '',
            'is_episode' => '1',
            'current_audio' => 'Dublado',
        ]);
    }

    /**
     * A ficha não tem servidor, o episódio tem: o leitor desce e devolve o vídeo.
     */
    public function test_serie_desce_para_a_pagina_do_episodio(): void
    {
        Http::fake([
            self::EPISODES_LIST => Http::response($this->listaDaTemporada(), 200, ['Content-Type' => 'application/json']),
            self::PLAYER_DATA_SERIE => Http::response($this->dadosDoPlayer(false), 200, ['Content-Type' => 'application/json']),
            self::PLAYER_DATA_EPISODIO => Http::response($this->dadosDoEpisodio(), 200, ['Content-Type' => 'application/json']),
            'https://streamtape.com/e/*' => Http::response($this->embedStreamtape()),
            'https://streamtape.com/get_video*' => Http::response('....ftypisom', 206, ['Content-Type' => 'video/mp4']),
            '*' => Http::response($this->paginaDoEpisodio()),
        ]);

        $resolvido = $this->leitor()->resolver($this->paginaDaSerie(), self::SERIE, 10, 2, 2);

        $this->assertNotNull($resolvido);
        $this->assertSame('dublado', $resolvido['idioma']);
        $this->assertStringContainsString('token='.self::TOKEN_BOM, $resolvido['url']);

        // A descida aconteceu: a lista da temporada foi pedida e a página do
        // episódio foi aberta — nessa ordem.
        Http::assertSent(fn ($requisicao) => str_contains($requisicao->url(), 'do=episodesList'));
        Http::assertSent(fn ($requisicao) => $requisicao->url() === self::EPISODIO);
    }

    /**
     * Episódio que não existe na temporada não vira fonte — e não abre página
     * nenhuma: a lista é a única fonte de verdade sobre o que o acervo tem.
     */
    public function test_serie_sem_o_episodio_pedido_devolve_null(): void
    {
        Http::fake([
            self::EPISODES_LIST => Http::response($this->listaDaTemporada(), 200, ['Content-Type' => 'application/json']),
            self::PLAYER_DATA_SERIE => Http::response($this->dadosDoPlayer(false), 200, ['Content-Type' => 'application/json']),
            '*' => Http::response('não deveria abrir episódio nenhum'),
        ]);

        $this->assertNull($this->leitor()->resolver($this->paginaDaSerie(), self::SERIE, 10, 2, 7));

        Http::assertNotSent(fn ($requisicao) => str_contains($requisicao->url(), 'episodios/online'));
    }

    /**
     * A página do episódio **não** desce de novo.
     *
     * Nela os `data-temp`/`data-ep` vêm preenchidos; servidor vazio ali significa
     * acervo sem vídeo. Sem essa guarda, o leitor pediria a lista da temporada a
     * partir do id do episódio, receberia uma lista vazia e gastaria duas
     * requisições para chegar ao mesmo `null`.
     */
    public function test_pagina_de_episodio_sem_servidor_nao_desce_de_novo(): void
    {
        Http::fake([
            self::PLAYER_DATA_EPISODIO => Http::response($this->dadosDoPlayer(false), 200, ['Content-Type' => 'application/json']),
            '*' => Http::response('nada mais deveria ser pedido'),
        ]);

        $this->assertNull($this->leitor()->resolver($this->paginaDoEpisodio(), self::EPISODIO, 10, 2, 2));

        Http::assertNotSent(fn ($requisicao) => str_contains($requisicao->url(), 'do=episodesList'));
    }

    /**
     * Sem temporada e episódio no pedido, a ficha é tratada como qualquer página
     * sem servidor: `null`, sem consulta extra. É o caso do fluxo de filme
     * aberto numa página de série por engano da busca.
     */
    public function test_serie_sem_numeracao_no_pedido_nao_desce(): void
    {
        Http::fake([
            self::PLAYER_DATA_SERIE => Http::response($this->dadosDoPlayer(false), 200, ['Content-Type' => 'application/json']),
            '*' => Http::response('nada mais deveria ser pedido'),
        ]);

        $this->assertNull($this->leitor()->resolver($this->paginaDaSerie(), self::SERIE, 10));

        Http::assertNotSent(fn ($requisicao) => str_contains($requisicao->url(), 'do=episodesList'));
    }

    /*
     * ------------------------------------------------------------------
     * MixDrop: o segundo servidor, e o que salva o episódio quando o
     * espelho do StreamTape já foi apagado ("Video not found!").
     * ------------------------------------------------------------------
     */

    /** O MP4 que o MixDrop publica, como ele aparece depois de desempacotado. */
    private const MP4_MIXDROP = 'https://a-delivery44.mxcontent.net/v2/abc.mp4?s=token&e=exp';

    /**
     * Um script empacotado no formato do packer clássico.
     *
     * O dicionário é montado à mão e o corpo só usa índices: é exatamente o que
     * o `p.a.c.k.e.r` produz. Depois de desempacotado, vira
     * `MDCore.wurl="//a-delivery44.mxcontent.net/v2/abc.mp4?s=token&e=exp";`.
     *
     * A URL do fixture não tem dígito solto de propósito: o packer troca
     * **todo** token pelo número correspondente, e um `1` no meio da query
     * seria confundido com o índice 1 (é o mesmo cuidado que os scripts reais
     * tomam, escondendo a query atrás de letras).
     */
    private function scriptDoMixdrop(): string
    {
        return '<html><body><script>eval(function(p,a,c,k,e,d){e=function(c){return c};'
            ."if(!''.replace(/^/,String)){while(c--){d[c]=k[c]||c}k=[function(e){return d[e]}];"
            ."e=function(){return'\\\\w+'};c=1};while(c--){if(k[c]){p=p.replace(new RegExp('\\\\b'+e(c)+'\\\\b','g'),k[c])}}"
            ."return p}('0.1=\"2\";',62,3,'MDCore|wurl|//a-delivery44.mxcontent.net/v2/abc.mp4?s=token&e=exp'.split('|'),0,{}))"
            .'</script></body></html>';
    }

    /**
     * Sem StreamTape no player, o MixDrop responde pelo episódio.
     *
     * É o caso real do acervo: o site declara os quatro servidores, mas o espelho
     * do StreamTape daquele episódio já foi apagado. Antes o leitor devolvia
     * `null` e a série caía na cascata de torrents; agora ele tenta o próximo
     * servidor com receita e entrega o MP4 do CDN.
     */
    public function test_mixdrop_entrega_o_mp4_quando_o_streamtape_nao_tem(): void
    {
        $dados = (string) json_encode([
            'players' => [
                ['label' => 'MixDrop', 'url' => 'https://mixdrop.top/e/'],
            ],
            'servers_dub' => 'mixdrop=abc123',
            'servers_leg' => '',
            'current_audio' => 'Dublado',
        ]);

        Http::fake([
            self::PLAYER_DATA => Http::response($dados, 200, ['Content-Type' => 'application/json']),
            'https://mixdrop.top/e/*' => Http::response($this->scriptDoMixdrop()),
            'https://a-delivery44.mxcontent.net/*' => Http::response('....ftypisom', 206, ['Content-Type' => 'video/mp4']),
            '*' => Http::response('resposta não esperada'),
        ]);

        $resolvido = $this->leitor()->resolver($this->pagina(), self::PAGINA, 10);

        $this->assertNotNull($resolvido);
        $this->assertSame(self::MP4_MIXDROP, $resolvido['url']);
        $this->assertSame('dublado', $resolvido['idioma']);
    }

    /**
     * O desempacotador só age quando reconhece o packer.
     *
     * Uma página que apenas mencione `eval(function(` faria o leitor procurar um
     * `.mp4` dentro de lixo — e o `null` é o que mantém o erro no log em vez de
     * virar fonte quebrada.
     */
    public function test_desempacotador_ignora_pagina_sem_packer(): void
    {
        $metodo = new \ReflectionMethod($this->leitor(), 'desempacotar');
        $metodo->setAccessible(true);

        $this->assertNull($metodo->invoke($this->leitor(), '<html>eval(function( nada aqui</html>'));
        $this->assertNull($metodo->invoke($this->leitor(), '<html>página limpa</html>'));
    }

    /** O endereço relativo (`//cdn`) do MixDrop sai como `https://`. */
    public function test_endereco_do_mixdrop_normaliza_o_esquema(): void
    {
        $metodo = new \ReflectionMethod($this->leitor(), 'enderecoDoMixdrop');
        $metodo->setAccessible(true);

        $this->assertSame(
            self::MP4_MIXDROP,
            $metodo->invoke($this->leitor(), $this->scriptDoMixdrop())
        );
    }
}
