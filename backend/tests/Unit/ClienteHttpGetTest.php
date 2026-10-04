<?php

namespace Tests\Unit;

use App\Services\Torrents\ClienteHttp;
use App\Services\Torrents\OrcamentoBusca;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A query string do endereço era descartada no caminho direto do [`ClienteHttp`].
 *
 * O `get()` do Laravel recebe os parâmetros num segundo argumento, e o Guzzle os
 * usa para **substituir** a query embutida na URL. O cliente passava um array
 * vazio por padrão, então todo endereço com busca já embutida chegava ao servidor
 * sem ela: `verpobreflix.net/search?q=...` virava `/search` e a resposta era a
 * própria home. Na prática, os trackers PT-BR e a busca direta dos agregadores
 * voltavam vazios — sem erro nenhum, só sem resultado.
 */
class ClienteHttpGetTest extends TestCase
{
    private function cliente(): ClienteHttp
    {
        return new ClienteHttp(new OrcamentoBusca());
    }

    public function test_query_embutida_na_url_e_preservada(): void
    {
        Http::fake(['*' => Http::response('ok')]);

        $this->cliente()->get('https://agregador.test/search?q=American+Horror+Story', [], null, 8);

        Http::assertSent(fn ($requisicao) => str_contains($requisicao->url(), '/search?q=American+Horror+Story'));
    }

    public function test_parametros_passados_explicitamente_sao_aplicados(): void
    {
        Http::fake(['*' => Http::response('ok')]);

        $this->cliente()->get('https://motor.test/search', ['q' => 'termo', 'format' => 'json'], null, 8);

        Http::assertSent(function ($requisicao) {
            return str_contains($requisicao->url(), 'q=termo')
                && str_contains($requisicao->url(), 'format=json');
        });
    }
}
