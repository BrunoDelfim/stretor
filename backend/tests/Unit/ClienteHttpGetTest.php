<?php

namespace Tests\Unit;

use App\Services\Torrents\ClienteHttp;
use App\Services\Torrents\OrcamentoBusca;
use Illuminate\Http\Client\ConnectionException;
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

    /**
     * A queda de rede na direta é passageira, e a repetição é o que a salva.
     *
     * O `connectTimeout` de 3 s existe para a cascata não pendurar — e é ele que
     * corta o instante ruim. Medido no container, o erro foi `Resolving timed out
     * after 3000 milliseconds` para o agregador que responde em menos de um
     * segundo: a página foi dada como morta, entregue ao FlareSolverr (que
     * devolveu erro ~20 s depois) e o orçamento da busca inteira foi junto.
     */
    public function test_direta_e_repetida_quando_a_rede_falha(): void
    {
        $tentativas = 0;

        Http::fake(function () use (&$tentativas) {
            $tentativas++;

            // A primeira volta morre na resolução do nome; a segunda completa.
            if ($tentativas === 1) {
                throw new ConnectionException('cURL error 28: Resolving timed out after 3000 milliseconds');
            }

            return Http::response('pagina do agregador', 200);
        });

        $resposta = $this->cliente()->get('https://agregador.test/search?q=American+Horror+Story', [], null, 8);

        $this->assertNotNull($resposta, 'A segunda tentativa precisa salvar a requisição.');
        $this->assertSame('pagina do agregador', (string) $resposta->body());
        $this->assertSame(2, $tentativas);
    }

    /**
     * Servidor que **respondeu** bloqueio não é repetido: o 403 não vira permissão
     * na segunda volta, e insistir gastaria orçamento à toa.
     */
    public function test_direta_bloqueada_nao_e_repetida(): void
    {
        config(['services.prowlarr.flaresolverr_url' => '']);

        Http::fake(['*' => Http::response('nada para você', 403)]);

        $resposta = $this->cliente()->get('https://agregador.test/search', [], null, 8);

        $this->assertSame(403, $resposta->status());
        Http::assertSentCount(1);
    }

    /**
     * Sem prazo para a segunda volta, a direta desiste na primeira: repetir aqui
     * atrasaria o socorro ou a página seguinte sem chance de dar certo.
     */
    public function test_direta_nao_e_repetida_sem_orcamento_para_a_segunda_volta(): void
    {
        $orcamento = new OrcamentoBusca();
        $orcamento->abrir(2);

        $tentativas = 0;

        Http::fake(function () use (&$tentativas) {
            $tentativas++;

            throw new ConnectionException('cURL error 28: Resolving timed out after 3000 milliseconds');
        });

        $resposta = (new ClienteHttp($orcamento))->get('https://agregador.test/search', [], null, 8);

        $this->assertNull($resposta);
        $this->assertSame(1, $tentativas, 'Sem prazo para a segunda volta, a direta desiste na primeira.');
    }
}
