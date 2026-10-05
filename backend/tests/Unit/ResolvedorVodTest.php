<?php

namespace Tests\Unit;

use App\Services\Torrents\ResolvedorVod;
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

    public function test_filme_manda_type_movie_sem_temporada(): void
    {
        $this->comHosts(['https://vizer.autos']);

        Http::fake([
            'https://vizer.autos/wp-json/api/v1/player' => Http::response($this->respostaNativa(self::URL_FILME)),
        ]);

        $this->assertNotNull($this->resolvedor()->resolver('550', null, null, 10));

        Http::assertSent(function ($requisicao): bool {
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

        Http::assertSent(fn ($requisicao): bool => ($corpo = $requisicao->data())['type'] === 'episode'
            && $corpo['tmdb'] === '693'
            && (int) $corpo['season'] === 4
            && (int) $corpo['episode'] === 17);
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
}
