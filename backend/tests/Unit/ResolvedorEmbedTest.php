<?php

namespace Tests\Unit;

use App\Services\Torrents\ResolvedorEmbed;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O resolvedor de embeds é o degrau que faltava: o `verpobreflix.net` não
 * hospeda vídeo, só embute o player do `plenoflu.com`, e a cadeia até o arquivo
 * passa por quatro serviços. Cada teste aqui trava um trecho dessa travessia —
 * a leitura do id do episódio, o desempacotamento do script do player, a
 * decifragem do formato do CryptoJS — sem tocar na rede.
 *
 * O que a bateria protege, antes de tudo, é o **silêncio**: nenhuma etapa pode
 * transformar dado ausente ou resposta torta em fonte. Quando qualquer elo falha,
 * o resultado é `null`, e a página continua sendo tratada como "sem arquivo"
 * exatamente como era antes de o resolvedor existir.
 */
class ResolvedorEmbedTest extends TestCase
{
    /** Hash do player usado nos fixtures; o formato é o do FirePlayer (hexa largo). */
    private const HASH = 'd41d8cd98f00b204e9800998ecf8427e';

    private const PLAYLIST = "https://vaiquecol.com/cdn/hls/831b/master.m3u8?md5=abc&expires=1";

    /**
     * Invoca um método privado do resolvedor sem abrir conexão nenhuma.
     */
    private function invocar(string $metodo, mixed ...$argumentos): mixed
    {
        $servico = $this->resolvedor();
        $reflexao = new \ReflectionMethod($servico, $metodo);
        $reflexao->setAccessible(true);

        return $reflexao->invoke($servico, ...$argumentos);
    }

    private function resolvedor(): ResolvedorEmbed
    {
        return app(ResolvedorEmbed::class);
    }

    /**
     * Respostas da cadeia com o player **protegido** (o caso do `superflixapi`,
     * atrás do Cloudflare Turnstile).
     *
     * @return array<string, mixed>
     */
    private function cadeiaComPlayerProtegido(): array
    {
        return [
            'https://plenoflu.com/tvshow/*' => Http::response('DIRECT_EPISODE_ID = 77601'),
            'https://plenoflu.com/api?action=getOptions*' => Http::response(json_encode([
                'data' => ['options' => [['ID' => 1, 'type' => '1']]],
            ])),
            'https://plenoflu.com/api?action=getPlayer*' => Http::response(json_encode([
                'data' => ['video_url' => base64_encode('https://superflixapi.quest/embed/1413')],
            ])),
        ];
    }

    /**
     * O script do player como ele chega na página: empacotado pelo packer
     * clássico. O corpo é mínimo de propósito — o desempacotador não executa
     * nada, só troca os índices do dicionário de volta pelas palavras.
     */
    private function scriptEmpacotado(): string
    {
        return "eval(function(p,a,c,k,e,d){return p}('0 1(2){3(\"4\",5)}',62,6,"
            ."'function|fireload|source|FirePlayer|".self::HASH."|true'.split('|'),0,{}))";
    }

    /**
     * A página do agregador, com o iframe que denuncia o player de terceiro.
     */
    private function paginaComEmbed(): string
    {
        return '<html><body><iframe src="https://plenoflu.com/tvshow/1413/1/1" '
            .'allowfullscreen></iframe></body></html>';
    }

    /**
     * A cadeia inteira, com todas as respostas no lugar: o resolver atravessa o
     * iframe e devolve o master.m3u8 assinado.
     */
    public function test_cadeia_completa_devolve_a_playlist(): void
    {
        Http::fake([
            'https://plenoflu.com/tvshow/*' => Http::response('<script>var DIRECT_EPISODE_ID = 77601;</script>'),
            'https://plenoflu.com/api?action=getOptions*' => Http::response(json_encode([
                'data' => ['options' => [['ID' => 374125, 'type' => '1']]],
            ])),
            'https://plenoflu.com/api?action=getPlayer*' => Http::response(json_encode([
                'data' => ['video_url' => base64_encode('https://vaiquecol.com/embed2/1413-1-1')],
            ])),
            'https://vaiquecol.com/embed2/*' => Http::response('<script>'.$this->scriptEmpacotado().'</script>'),
            'https://vaiquecol.com/player/index.php*' => Http::response(json_encode([
                'hls' => true,
                'securedLink' => self::PLAYLIST,
                'videoSources' => [],
                'ck' => '\\x59\\x54',
            ])),
            'https://vaiquecol.com/cdn/hls/*' => Http::response(
                "#EXTM3U\n#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"audio\",LANGUAGE=\"por\",NAME=\"Portuguese\"\n",
                200,
                ['Content-Type' => 'application/x-mpegURL']
            ),
        ]);

        $resolvido = $this->resolvedor()->resolver(
            $this->paginaComEmbed(),
            'https://www.verpobreflix.net/series/american-horror-story',
            10
        );

        $this->assertNotNull($resolvido);
        $this->assertSame(self::PLAYLIST, $resolvido['url']);
        $this->assertSame('dublado', $resolvido['idioma'], 'A faixa de áudio em português faz a fonte dublada.');
    }

    /**
     * A consulta ao player precisa se apresentar como AJAX: sem o cabeçalho, o
     * endpoint devolve a **página** do player em vez do JSON, e a cadeia morre
     * sem entender por quê.
     */
    public function test_a_consulta_ao_player_vai_com_cabecalho_de_ajax(): void
    {
        Http::fake([
            'https://plenoflu.com/tvshow/*' => Http::response('DIRECT_EPISODE_ID = 77601'),
            'https://plenoflu.com/api?action=getOptions*' => Http::response(json_encode([
                'data' => ['options' => [['ID' => 1, 'type' => '1']]],
            ])),
            'https://plenoflu.com/api?action=getPlayer*' => Http::response(json_encode([
                'data' => ['video_url' => base64_encode('https://vaiquecol.com/embed2/1413-1-1')],
            ])),
            'https://vaiquecol.com/embed2/*' => Http::response('<script>'.$this->scriptEmpacotado().'</script>'),
            'https://vaiquecol.com/player/index.php*' => Http::response('{}'),
        ]);

        $this->resolvedor()->resolver($this->paginaComEmbed(), 'https://agregador.test/serie', 10);

        Http::assertSent(function ($requisicao) {
            return str_contains($requisicao->url(), 'do=getVideo')
                && $requisicao->hasHeader('X-Requested-With', 'XMLHttpRequest');
        });
    }

    /**
     * Página sem embed conhecido não gera requisição nenhuma. A checagem é o que
     * impede o resolvedor de virar uma despesa de rede em toda página raspada.
     */
    public function test_pagina_sem_embed_conhecido_nao_abre_conexao(): void
    {
        Http::fake();

        $resolvido = $this->resolvedor()->resolver(
            '<html><body><video src="https://cdn.test/filme.mp4"></video></body></html>',
            'https://agregador.test/serie',
            10
        );

        $this->assertNull($resolvido);
        Http::assertNothingSent();
    }

    /**
     * Embed de host desconhecido é ignorado: só cadeias com receita conhecida
     * são seguidas, e seguir qualquer iframe seria adivinhação.
     */
    public function test_embed_de_host_desconhecido_e_ignorado(): void
    {
        Http::fake();

        $html = '<iframe src="https://player.desconhecido.test/embed/1413"></iframe>';

        $this->assertNull($this->resolvedor()->resolver($html, 'https://agregador.test/serie', 10));
        Http::assertNothingSent();
    }

    /**
     * Desligado, o resolvedor não percorre cadeia alguma — é o que devolve o
     * fallback ao comportamento anterior quando um agregador começa a responder
     * coisa errada.
     */
    public function test_resolvedor_desligado_nao_toca_a_cadeia(): void
    {
        config(['services.torrents.stream_direto_resolver_embeds' => false]);
        Http::fake();

        $this->assertNull(
            $this->resolvedor()->resolver($this->paginaComEmbed(), 'https://agregador.test/serie', 10)
        );
        Http::assertNothingSent();
    }

    /**
     * O player protegido é recusado sem sequer ser aberto: o `superflixapi` está
     * atrás do Turnstile, e a página que voltaria de lá é a de verificação.
     */
    public function test_player_sem_caminho_aberto_e_descartado(): void
    {
        Http::fake($this->cadeiaComPlayerProtegido());

        $this->assertNull(
            $this->resolvedor()->resolver($this->paginaComEmbed(), 'https://agregador.test/serie', 10)
        );

        Http::assertNotSent(fn ($requisicao) => str_contains($requisicao->url(), 'superflixapi.quest'));
    }

    /**
     * A URL só é oferecida depois de abrir. Uma fonte assinada que já não
     * responde viraria erro na tela do usuário, depois de todo o custo da busca.
     */
    public function test_fonte_que_nao_abre_como_playlist_e_recusada(): void
    {
        Http::fake([
            'https://plenoflu.com/tvshow/*' => Http::response('DIRECT_EPISODE_ID = 77601'),
            'https://plenoflu.com/api?action=getOptions*' => Http::response(json_encode([
                'data' => ['options' => [['ID' => 1, 'type' => '1']]],
            ])),
            'https://plenoflu.com/api?action=getPlayer*' => Http::response(json_encode([
                'data' => ['video_url' => base64_encode('https://vaiquecol.com/embed2/1413-1-1')],
            ])),
            'https://vaiquecol.com/embed2/*' => Http::response('<script>'.$this->scriptEmpacotado().'</script>'),
            'https://vaiquecol.com/player/index.php*' => Http::response(json_encode([
                'hls' => true,
                'securedLink' => self::PLAYLIST,
            ])),
            'https://vaiquecol.com/cdn/hls/*' => Http::response('expirou', 404),
        ]);

        $this->assertNull(
            $this->resolvedor()->resolver($this->paginaComEmbed(), 'https://agregador.test/serie', 10)
        );
    }

    public function test_id_do_episodio_sai_da_pagina_do_embed(): void
    {
        $this->assertSame('77601', $this->invocar('episodioDoPlenoflu', '<script>DIRECT_EPISODE_ID = 77601;</script>'));
        $this->assertSame('77601', $this->invocar('episodioDoPlenoflu', 'window.DIRECT_EPISODE_ID="77601";'));
        $this->assertNull($this->invocar('episodioDoPlenoflu', '<html>página sem id nenhum</html>'));
    }

    public function test_script_empacotado_e_desempacotado(): void
    {
        $texto = $this->invocar('desempacotar', '<script>'.$this->scriptEmpacotado().'</script>');

        $this->assertIsString($texto);
        $this->assertStringContainsString('FirePlayer("'.self::HASH.'",true)', $texto);
    }

    public function test_hash_do_player_e_lido_do_script_empacotado(): void
    {
        $html = '<script>'.$this->scriptEmpacotado().'</script>';

        $this->assertSame(self::HASH, $this->invocar('hashDoFirePlayer', $html));
    }

    /**
     * Sem empacotamento o hash ainda é lido: há instalações do player que montam
     * o script em texto claro.
     */
    public function test_hash_em_script_sem_packer_tambem_e_lido(): void
    {
        $html = '<script>fireload();function fireload(){FirePlayer("'.self::HASH.'",{ck:"x"})}</script>';

        $this->assertSame(self::HASH, $this->invocar('hashDoFirePlayer', $html));
    }

    public function test_pagina_sem_packer_devolve_nulo(): void
    {
        $this->assertNull($this->invocar('desempacotar', '<html><script>var x = 1;</script></html>'));
        $this->assertNull($this->invocar('hashDoFirePlayer', '<html><script>var x = 1;</script></html>'));
    }

    public function test_escapes_hexadecimais_da_chave_sao_traduzidos(): void
    {
        $this->assertSame('YTd', $this->invocar('desofuscar', '\\x59\\x54\\x64'));
    }

    /**
     * As duas leituras da chave são tentadas: a literal (a que o player entrega
     * ao CryptoJS) e a interpretada.
     */
    public function test_chaves_possiveis_tentam_a_literal_e_a_interpretada(): void
    {
        $chaves = $this->invocar('chavesPossiveis', '\\x59\\x54');

        $this->assertSame(['\\x59\\x54', 'YT'], $chaves);
    }

    public function test_playlist_sem_faixa_portuguesa_nao_declara_idioma(): void
    {
        $this->assertNull($this->invocar(
            'idiomaDaPlaylist',
            "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1000000\nhttps://cdn.test/v.m3u8"
        ));
    }

    public function test_faixa_em_ingles_nao_vira_dublado(): void
    {
        $playlist = "#EXTM3U\n#EXT-X-MEDIA:TYPE=AUDIO,LANGUAGE=\"eng\",NAME=\"English\"\n";

        $this->assertNull($this->invocar('idiomaDaPlaylist', $playlist));
    }

    /**
     * O player progressivo cifra cada fonte com o formato do CryptoJS, e a
     * decifragem precisa devolver a URL — não bytes quaisquer.
     */
    public function test_cifra_do_cryptojs_e_decifrada(): void
    {
        $chave = 'chave-de-teste';
        $cifra = $this->cifraCryptoJs('https://cdn.test/video.mp4', $chave);

        $this->assertSame('https://cdn.test/video.mp4', $this->invocar('decifrar', $cifra, $chave));
    }

    /**
     * A cifra do CryptoJS não tem assinatura: com a chave errada, o que sai são
     * bytes aleatórios. A prova de que a decifragem deu certo é o texto claro ser
     * uma URL — e é ela que impede lixo de virar fonte.
     */
    public function test_chave_errada_nao_vira_fonte(): void
    {
        $cifra = $this->cifraCryptoJs('https://cdn.test/video.mp4', 'chave-de-teste');

        $this->assertNull($this->invocar('decifrar', $cifra, 'outra-chave'));
    }

    /**
     * A cadeia completa com o player progressivo: o `securedLink` não vem, e a
     * fonte cifrada é aberta com a chave que o próprio player publica — em
     * escapes hexadecimais, como no site.
     */
    public function test_player_progressivo_cifrado_e_resolvido(): void
    {
        $chave = 'chave-de-teste';
        $url = 'https://cdn.test/video.mp4';

        Http::fake([
            'https://plenoflu.com/tvshow/*' => Http::response('DIRECT_EPISODE_ID = 77601'),
            'https://plenoflu.com/api?action=getOptions*' => Http::response(json_encode([
                'data' => ['options' => [['ID' => 1, 'type' => '1']]],
            ])),
            'https://plenoflu.com/api?action=getPlayer*' => Http::response(json_encode([
                'data' => ['video_url' => base64_encode('https://vaiquecol.com/embed2/1413-1-1')],
            ])),
            'https://vaiquecol.com/embed2/*' => Http::response('<script>'.$this->scriptEmpacotado().'</script>'),
            'https://vaiquecol.com/player/index.php*' => Http::response(json_encode([
                'hls' => false,
                'ck' => $this->escapeHexadecimal($chave),
                'videoSources' => [['file' => $this->cifraCryptoJs($url, $chave), 'label' => 'HD']],
            ])),
            '*' => Http::response('nada deveria ser baixado'),
        ]);

        $resolvido = $this->resolvedor()->resolver($this->paginaComEmbed(), 'https://agregador.test/serie', 10);

        $this->assertNotNull($resolvido);
        $this->assertSame($url, $resolvido['url']);

        // O arquivo progressivo não é aberto para conferência: baixá-lo traria o
        // filme inteiro só para provar que existe.
        Http::assertNotSent(fn ($requisicao) => str_contains($requisicao->url(), 'cdn.test'));
    }

    /**
     * Formato que o media-service recusaria adiante não entra como fonte — a
     * recusa aqui evita oferecer ao usuário um player que morre ao tocar.
     */
    public function test_formato_fora_da_lista_e_recusado(): void
    {
        $chave = 'chave-de-teste';

        Http::fake([
            'https://plenoflu.com/tvshow/*' => Http::response('DIRECT_EPISODE_ID = 77601'),
            'https://plenoflu.com/api?action=getOptions*' => Http::response(json_encode([
                'data' => ['options' => [['ID' => 1, 'type' => '1']]],
            ])),
            'https://plenoflu.com/api?action=getPlayer*' => Http::response(json_encode([
                'data' => ['video_url' => base64_encode('https://vaiquecol.com/embed2/1413-1-1')],
            ])),
            'https://vaiquecol.com/embed2/*' => Http::response('<script>'.$this->scriptEmpacotado().'</script>'),
            'https://vaiquecol.com/player/index.php*' => Http::response(json_encode([
                'hls' => false,
                'ck' => $this->escapeHexadecimal($chave),
                'videoSources' => [['file' => $this->cifraCryptoJs('https://cdn.test/video.mkv', $chave)]],
            ])),
            '*' => Http::response('resposta que não é playlist'),
        ]);

        $this->assertNull(
            $this->resolvedor()->resolver($this->paginaComEmbed(), 'https://agregador.test/serie', 10)
        );
    }

    /**
     * Monta uma cifra no formato do CryptoJS — `{"ct","iv","s"}`, do jeito que a
     * biblioteca `cryptojs-aes-format` do player produz.
     *
     * A derivação da chave é a do `EVP_BytesToKey` em MD5, que é o esquema
     * publicado da biblioteca, não uma invenção daqui: é o mesmo caminho que o
     * navegador percorre ao chamar `CryptoJSAesJson.decrypt()`.
     */
    private function cifraCryptoJs(
        string $claro,
        string $chave,
        string $sal = "\x00\x11\x22\x33\x44\x55\x66\x77",
        string $vetor = "\x00\x11\x22\x33\x44\x55\x66\x77\x88\x99\xaa\xbb\xcc\xdd\xee\xff"
    ): string {
        $comSal = $chave.$sal;
        $primeiro = md5($comSal, true);
        $binaria = substr($primeiro.md5($primeiro.$comSal, true), 0, 32);

        $cifrado = (string) openssl_encrypt(json_encode($claro), 'aes-256-cbc', $binaria, OPENSSL_RAW_DATA, $vetor);

        return (string) json_encode([
            'ct' => base64_encode($cifrado),
            'iv' => bin2hex($vetor),
            's' => bin2hex($sal),
        ]);
    }

    /**
     * Escreve uma senha em escapes hexadecimais, como o player a publica no
     * campo `ck`.
     */
    private function escapeHexadecimal(string $valor): string
    {
        $escapes = array_map(
            fn (string $caractere): string => '\\x'.str_pad(dechex(ord($caractere)), 2, '0', STR_PAD_LEFT),
            str_split($valor)
        );

        return implode('', $escapes);
    }
}
