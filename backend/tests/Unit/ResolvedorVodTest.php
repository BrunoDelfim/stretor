<?php

namespace Tests\Unit;

use App\Services\Torrents\ResolvedorVod;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O resolvedor por id é o passo zero do stream direto: em vez de procurar a
 * página do título, ele entrega o id do TMDB ao provedor e recebe o arquivo de
 * volta. Cada teste aqui trava um pedaço dessa consulta — o corpo montado para
 * filme e para episódio, a recusa do que não é arquivo nativo, a troca de
 * espelho quando o primeiro não tem o acervo — sem tocar na rede.
 *
 * O que a bateria protege, antes de tudo, é o **silêncio**: um id inválido, um
 * `embed` disfarçado de fonte ou um host sem o título viram `null`, e a busca
 * segue para o motor e os agregadores como se o passo zero não existisse.
 */
class ResolvedorVodTest extends TestCase
{
    private const URL_FILME = 'https://cdn.test/movie/550.mp4';

    private const URL_EPISODIO = 'https://cdn.test/series/693004017.mp4';

    private function resolvedor(): ResolvedorVod
    {
        return app(ResolvedorVod::class);
    }

    /**
     * Fixa os espelhos consultados, para o teste não depender do host padrão.
     *
     * @param  array<int, string>  $hosts
     */
    private function comHosts(array $hosts): void
    {
        config()->set('services.torrents.stream_direto_vod_hosts', $hosts);
    }

    /**
     * Uma resposta nativa da API, como ela chega com o acervo disponível.
     *
     * @return array<string, mixed>
     */
    private function respostaNativa(string $url): array
    {
        return ['success' => true, 'mode' => 'native', 'mime' => 'video/mp4', 'url' => $url];
    }

    public function test_modo_native_devolve_url_e_idioma(): void
    {
        $this->comHosts(['https://vizer.autos']);

        Http::fake([
            'https://vizer.autos/wp-json/api/v1/player' => Http::response($this->respostaNativa(self::URL_EPISODIO)),
        ]);

        $resolvido = $this->resolvedor()->resolver('693', 4, 17, 10);

        $this->assertNotNull($resolvido);
        $this->assertSame(self::URL_EPISODIO, $resolvido['url']);
        $this->assertSame('pt-BR', $resolvido['idioma']);
    }

    /**
     * O idioma sai do que o espelho declarou; sem declaração, vale o acervo.
     *
     * O host é o mesmo, mas agora a declaração dele é ouvida: um espelho que
     * marca a faixa como legendada não pode sair etiquetado de dublado, senão o
     * crivo de idioma da busca o aceitaria como se o áudio fosse PT-BR.
     */
    public function test_idioma_declarado_pelo_espelho_e_respeitado(): void
    {
        $this->comHosts(['https://vizer.autos']);

        Http::fake([
            'https://vizer.autos/wp-json/api/v1/player' => Http::response(
                $this->respostaNativa(self::URL_FILME) + ['audio_language' => 'Legendado']
            ),
        ]);

        $resolvido = $this->resolvedor()->resolver('550', null, null, 10);

        $this->assertNotNull($resolvido);
        $this->assertSame('Legendado', $resolvido['idioma']);
    }

    public function test_filme_manda_type_movie_sem_temporada(): void
    {
        $this->comHosts(['https://vizer.autos']);

        Http::fake([
            'https://vizer.autos/wp-json/api/v1/player' => Http::response($this->respostaNativa(self::URL_FILME)),
        ]);

        $this->assertNotNull($this->resolvedor()->resolver('550', null, null, 10));

        /*
         * A sonda de existência também passa pelo cliente HTTP (um `HEAD` no
         * CDN), mas sem corpo; o filtro pelo endpoint do espelho evita ler um
         * corpo vazio como se fosse o da consulta.
         */
        Http::assertSent(function ($requisicao): bool {
            if (! str_contains($requisicao->url(), '/wp-json/api/v1/player')) {
                return false;
            }

            $corpo = $requisicao->data();

            return $corpo['type'] === 'movie'
                && $corpo['tmdb'] === '550'
                && ! array_key_exists('season', $corpo)
                && ! array_key_exists('episode', $corpo);
        });
    }

    public function test_episodio_manda_type_episode_com_temporada_e_episodio(): void
    {
        $this->comHosts(['https://vizer.autos']);

        Http::fake([
            'https://vizer.autos/wp-json/api/v1/player' => Http::response($this->respostaNativa(self::URL_EPISODIO)),
        ]);

        $this->resolvedor()->resolver('693', 4, 17, 10);

        Http::assertSent(function ($requisicao): bool {
            // Mesmo filtro do teste de filme: a sonda de arquivo não tem corpo.
            if (! str_contains($requisicao->url(), '/wp-json/api/v1/player')) {
                return false;
            }

            $corpo = $requisicao->data();

            return $corpo['type'] === 'episode'
                && $corpo['tmdb'] === '693'
                && (int) $corpo['season'] === 4
                && (int) $corpo['episode'] === 17;
        });
    }

    /**
     * O `embed` é a resposta de uma fonte que não é arquivo: o provedor está
     * apontando para um agregador de terceiro, e esse caminho é do resolvedor de
     * embeds. Devolver a URL crua entregaria ao usuário uma página, não um vídeo.
     */
    public function test_modo_embed_e_recusado(): void
    {
        $this->comHosts(['https://vizer.autos']);

        Http::fake([
            'https://vizer.autos/wp-json/api/v1/player' => Http::response([
                'success' => true,
                'mode' => 'embed',
                'url' => 'https://outroagregador.test/serie/693/4/17',
            ]),
        ]);

        $this->assertNull($this->resolvedor()->resolver('693', 4, 17, 10));
    }

    public function test_titulo_fora_do_acervo_e_recusado(): void
    {
        $this->comHosts(['https://vizer.autos']);

        Http::fake([
            'https://vizer.autos/wp-json/api/v1/player' => Http::response([
                'success' => false,
                'message' => 'Este título ainda não está disponível para reprodução.',
            ]),
        ]);

        $this->assertNull($this->resolvedor()->resolver('9999999', 1, 1, 10));
    }

    /**
     * Formato que o media-service recusaria adiante não entra como fonte.
     */
    public function test_extensao_fora_da_lista_e_recusada(): void
    {
        $this->comHosts(['https://vizer.autos']);

        Http::fake([
            'https://vizer.autos/wp-json/api/v1/player' => Http::response([
                'success' => true,
                'mode' => 'native',
                'url' => 'https://cdn.test/stream.mpd',
            ]),
        ]);

        $this->assertNull($this->resolvedor()->resolver('693', 4, 17, 10));
    }

    /**
     * Id que não é inteiro não gera requisição: a rota só endereça por número.
     */
    public function test_id_nao_numerico_nao_consulta(): void
    {
        $this->comHosts(['https://vizer.autos']);
        Http::fake();

        $this->assertNull($this->resolvedor()->resolver('abc', 1, 1, 10));

        Http::assertNothingSent();
    }

    /**
     * Um espelho que não tem o acervo não encerra a consulta: o próximo assume.
     */
    public function test_espelho_seguinte_assume_quando_o_primeiro_nao_tem(): void
    {
        $this->comHosts(['https://espelho-a.test', 'https://espelho-b.test']);

        Http::fake([
            'https://espelho-a.test/wp-json/api/v1/player' => Http::response([
                'success' => false,
                'message' => 'conteúdo inválido',
            ]),
            'https://espelho-b.test/wp-json/api/v1/player' => Http::response($this->respostaNativa(self::URL_EPISODIO)),
        ]);

        $resolvido = $this->resolvedor()->resolver('693', 1, 1, 10);

        $this->assertNotNull($resolvido);
        $this->assertSame(self::URL_EPISODIO, $resolvido['url']);
    }

    public function test_desligado_nao_consulta(): void
    {
        $this->comHosts(['https://vizer.autos']);
        config()->set('services.torrents.stream_direto_resolver_vod', false);
        Http::fake();

        $this->assertNull($this->resolvedor()->resolver('693', 4, 17, 10));

        Http::assertNothingSent();
    }

    /**
     * Link que o CDN não serve não vira fonte.
     *
     * O espelho afirma `success: true` e `mode: native`, mas o `404` no `HEAD`
     * prova que o arquivo não existe. A sonda pega a mentira aqui, e o `null`
     * faz a busca seguir para o degrau seguinte em vez de entregar ao usuário um
     * link que só o FFmpeg, minutos adiante, denunciaria.
     */
    public function test_arquivo_morto_no_cdn_e_recusado(): void
    {
        $this->comHosts(['https://vizer.autos']);

        Http::fake([
            'https://vizer.autos/wp-json/api/v1/player' => Http::response($this->respostaNativa(self::URL_EPISODIO)),
            'https://cdn.test/*' => Http::response('', 404),
        ]);

        $this->assertNull($this->resolvedor()->resolver('693', 4, 17, 10));
    }

    /**
     * Desligada a sonda, o comportamento volta ao de antes: o espelho manda.
     *
     * A chave existe para poder desligar a conferência sem desligar o passo zero
     * inteiro; aqui isso é provado pelo link morto que ainda assim é aceito.
     */
    public function test_sonda_desligada_aceita_o_link_mesmo_sem_arquivo(): void
    {
        $this->comHosts(['https://vizer.autos']);
        config()->set('services.torrents.stream_direto_sondar_url', false);

        Http::fake([
            'https://vizer.autos/wp-json/api/v1/player' => Http::response($this->respostaNativa(self::URL_EPISODIO)),
            'https://cdn.test/*' => Http::response('', 404),
        ]);

        $resolvido = $this->resolvedor()->resolver('693', 4, 17, 10);

        $this->assertNotNull($resolvido);
        $this->assertSame(self::URL_EPISODIO, $resolvido['url']);
    }

    /**
     * A conta expirada do CDN: o cabeçalho diz `403` e o corpo diz o motivo.
     *
     * É o caso que originou a leitura do corpo. O espelho afirma `native`, o
     * `HEAD` devolve `403` e o `GET` devolve `Conta expirada.` no lugar do MP4.
     * Aceitar esse link fazia a busca dispensar a cascata para **todo** título do
     * acervo, e o player só descobria o problema na conversão.
     */
    public function test_conta_expirada_no_cdn_recusa_o_link(): void
    {
        $this->comHosts(['https://vizer.autos']);

        Http::fake(function (Request $pedido) {
            if (str_contains($pedido->url(), 'wp-json/api/v1/player')) {
                return Http::response($this->respostaNativa(self::URL_FILME));
            }

            if ($pedido->method() === 'HEAD') {
                return Http::response('', 403);
            }

            return Http::response('Conta expirada.', 403, ['Content-Type' => 'text/html; charset=UTF-8']);
        });

        $this->assertNull($this->resolvedor()->resolver('550', null, null, 10));
    }

    /**
     * O `403` do anti-hotlink não condena: o `GET` serve o vídeo.
     *
     * É a razão de a sonda não tratar `403` como morto no cabeçalho. Aqui o
     * `HEAD` é recusado (o CDN não aceita o método), mas o `GET` devolve os
     * primeiros bytes de um MP4 — a fonte está viva e precisa continuar.
     */
    public function test_head_recusado_mas_arquivo_servido_mantem_a_fonte(): void
    {
        $this->comHosts(['https://vizer.autos']);

        Http::fake(function (Request $pedido) {
            if (str_contains($pedido->url(), 'wp-json/api/v1/player')) {
                return Http::response($this->respostaNativa(self::URL_FILME));
            }

            if ($pedido->method() === 'HEAD') {
                return Http::response('', 403);
            }

            return Http::response(
                "\x00\x00\x00\x18ftypmp42",
                206,
                ['Content-Type' => 'video/mp4']
            );
        });

        $resolvido = $this->resolvedor()->resolver('550', null, null, 10);

        $this->assertNotNull($resolvido);
        $this->assertSame(self::URL_FILME, $resolvido['url']);
    }

    /**
     * Recusa ambígua que ainda entrega vídeo continua valendo.
     *
     * O `403` do anti-hotlink (a URL está boa, falta o `Referer`) chega igual no
     * `HEAD`, mas o `GET` com `Range` devolve os bytes do arquivo — e é isso que
     * o player precisa. A sonda de vídeo continua aprovando esse caso.
     */
    public function test_recusa_ambigua_com_video_mantem_a_fonte(): void
    {
        $this->comHosts(['https://vizer.autos']);

        Http::fake(function (Request $pedido) {
            if (str_contains($pedido->url(), 'wp-json/api/v1/player')) {
                return Http::response($this->respostaNativa(self::URL_FILME));
            }

            if ($pedido->method() === 'HEAD') {
                return Http::response('', 403);
            }

            return Http::response("\x00\x00\x00\x18ftypmp42", 206, ['Content-Type' => 'video/mp4']);
        });

        $this->assertNotNull($this->resolvedor()->resolver('550', null, null, 10));
    }

    /**
     * Recusa ambígua que devolve HTML não é fonte.
     *
     * Este era o ponto cego: sem marca de conta no corpo, o `403` com página de
     * WAF/manutenção era aceito "pela timidez". Na prática, a fonte abria o
     * player e não entregava dados — o mesmo desfecho do `401 Credenciais
     * inválidas.` que marcou "Ponto Sem Retorno" como verificado.
     */
    public function test_recusa_ambigua_sem_video_descarta_a_fonte(): void
    {
        $this->comHosts(['https://vizer.autos']);

        Http::fake(function (Request $pedido) {
            if (str_contains($pedido->url(), 'wp-json/api/v1/player')) {
                return Http::response($this->respostaNativa(self::URL_FILME));
            }

            return Http::response('<html><body>Acesso restrito</body></html>', 403, ['Content-Type' => 'text/html']);
        });

        $this->assertNull($this->resolvedor()->resolver('550', null, null, 10));
    }

    /**
     * O cabeçalho saudável não basta: a leitura de bytes confirma a mídia.
     *
     * A sonda parava no `HEAD 200 video/mp4` para economizar uma requisição — e
     * foi essa economia que deixou o `nixplay.lat` passar: o cabeçalho não dizia
     * nada e o corpo entregava credencial recusada. Um `Range` de 1 KB é barato
     * perto de abrir o player sem dados.
     */
    public function test_cabecalho_saudavel_e_confirmado_pela_leitura(): void
    {
        $this->comHosts(['https://vizer.autos']);

        Http::fake(function (Request $pedido) {
            if (str_contains($pedido->url(), 'wp-json/api/v1/player')) {
                return Http::response($this->respostaNativa(self::URL_FILME));
            }

            return Http::response("\x00\x00\x00\x18ftypmp42", 200, ['Content-Type' => 'video/mp4']);
        });

        $this->assertNotNull($this->resolvedor()->resolver('550', null, null, 10));

        Http::assertSent(fn (Request $pedido): bool => $pedido->method() === 'GET'
            && str_contains($pedido->url(), 'cdn.test'));
    }
}
