<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Torrents\ConversorLegenda;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Conversor de legenda para o formato que o Plyr aceita.
 *
 * O provedor entrega a legenda como `.srt`, e o navegador só lê WebVTT no
 * `<track>`. Em vez de o frontend baixar o SRT de um host de terceiros (e
 * depender do CORS dele) e convertê-lo, o `<track>` aponta para cá: buscamos o
 * arquivo, convertemos com o [`ConversorLegenda`] e devolvemos `text/vtt`.
 *
 * A lista de hosts permitidos existe por segurança: sem ela, um `url` arbitrário
 * transformaria este endpoint num proxy aberto (SSRF), capaz de alcançar a rede
 * interna a partir do servidor. Só hosts de legenda conhecidos passam.
 *
 * A conversão é cacheada — a legenda de um título não muda —, e o `Cache-Control`
 * deixa o próprio navegador reaproveitá-la durante a reprodução.
 */
class LegendaController extends Controller
{
    /**
     * Busca o SRT de origem, converte para WebVTT e devolve ao player.
     *
     * O `url` é a legenda crua anexada à fonte pelo [`BuscaLegendas`]. Quando o
     * host não consta na lista permitida, o arquivo não responde ou a conversão
     * sairia vazia, respondemos 404 — o `<track>` falha em silêncio e a
     * reprodução segue sem legenda.
     */
    public function converter(Request $requisicao): Response
    {
        $url = trim((string) $requisicao->query('url'));

        if (! $this->urlPermitida($url)) {
            abort(404, 'Legenda não encontrada.');
        }

        $ttl = (int) config('services.torrents.legendas_cache_ttl', 21600);
        $vtt = Cache::remember('legenda:vtt:'.md5($url), $ttl, fn (): ?string => $this->baixarEConverter($url));

        if ($vtt === null || trim($vtt) === '') {
            abort(404, 'Legenda indisponível.');
        }

        return response($vtt, 200, [
            'Content-Type' => 'text/vtt; charset=utf-8',
            'Cache-Control' => 'public, max-age='.$ttl,
            // O `<track>` é servido ao player por este mesmo domínio, mas a
            // liberação de CORS não custa nada caso o frontend rode isolado.
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /** Baixa o arquivo de legenda e o converte para WebVTT. */
    private function baixarEConverter(string $url): ?string
    {
        try {
            $resposta = Http::withHeaders(['User-Agent' => $this->agente()])
                ->timeout((int) config('services.torrents.legendas_tempo_limite', 8))
                ->get($url);
        } catch (Throwable $excecao) {
            Log::warning('Conversão de legenda: falha ao baixar o arquivo.', [
                'url' => $url,
                'erro' => $excecao->getMessage(),
            ]);

            return null;
        }

        if (! $resposta->successful()) {
            return null;
        }

        // O conversor reconhece e preserva um conteúdo que já seja WebVTT.
        return ConversorLegenda::srtParaVtt((string) $resposta->body());
    }

    /**
     * Diz se a URL aponta para um dos hosts de legenda autorizados.
     *
     * A comparação é sobre o **host** (nunca o texto da URL inteira) e aceita
     * subdomínios do domínio permitido, mas não um domínio que apenas termine
     * com o mesmo sufixo (`vidapi.cloud.evil.com` não passa).
     */
    private function urlPermitida(string $url): bool
    {
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return false;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        foreach ((array) config('services.torrents.legendas_hosts_permitidos', []) as $permitido) {
            $permitido = strtolower(trim((string) $permitido));

            if ($permitido === '') {
                continue;
            }

            if ($host === $permitido || str_ends_with($host, '.'.$permitido)) {
                return true;
            }
        }

        return false;
    }

    /** Agente de navegador usado no download da legenda. */
    private function agente(): string
    {
        return (string) (config('services.torrents.user_agent') ?: 'Mozilla/5.0');
    }
}
