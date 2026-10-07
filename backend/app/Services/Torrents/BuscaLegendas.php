<?php

namespace App\Services\Torrents;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Busca de legendas para o fallback de idioma original.
 *
 * Quando nenhum provedor entrega áudio em PT-BR, o [`TorrentService`] serve a
 * fonte de idioma original — desde que com legenda. É este serviço que a
 * encontra. A fonte é o acervo do VidSrc, endereçável por identificador: o
 * mesmo `imdb_id` já usado pela busca (e a numeração `SxxExx`, no caso de série)
 * responde com a lista de faixas de legenda disponíveis para aquele título.
 *
 * A regra de idioma é a do usuário: **PT-BR e inglês quando existirem, só inglês
 * quando não houver PT-BR**. A ordem da lista devolvida também é essa — o PT-BR
 * primeiro, para o frontend já abrir com a legenda certa marcada.
 *
 * A resposta crua traz várias variantes do mesmo idioma (PT-BR brasileiro,
 * inglês "diálogo", inglês só de placas…). Escolhemos uma de cada: a primeira
 * PT-BR e, para o inglês, uma versão completa — as variantes de placa/cifra
 * ("Forced", "Signs", "Songs") ficam de fora enquanto houver alternativa.
 *
 * O resultado é cacheado: a lista de legendas de um título quase não muda, e o
 * endpoint é de terceiros — não faz sentido perguntar a cada reprodução.
 */
class BuscaLegendas
{
    /** Rota do acervo que devolve os dados e a lista de faixas. */
    private const CAMINHO = '/api.php';

    /** Espera entre tentativas, em microssegundos (250 ms). */
    private const PAUSA_ENTRE_TENTATIVAS = 250_000;

    /**
     * Legendas de um título, já selecionadas e normalizadas.
     *
     * Devolve uma lista no contrato do frontend: `srclang` (código para o
     * `<track>`), `label` (rótulo exibido), `url` (arquivo de legenda de origem)
     * e `origem` (de quem veio). Lista vazia significa "sem legenda utilizável" —
     * e, no fluxo do fallback, isso aborta a oferta do áudio original.
     *
     * @return array<int, array{srclang: string, label: string, url: string, origem: string}>
     */
    public function buscar(?string $imdbId, string $tipo = 'movie', ?int $temporada = null, ?int $episodio = null): array
    {
        $imdbId = trim((string) $imdbId);

        // Sem identificador não há como endereçar o acervo: o nome do título
        // seria adivinhação, e legenda errada é pior que legenda nenhuma.
        if ($imdbId === '') {
            return [];
        }

        $chave = sprintf(
            'legendas:vidsrc:%s:%s:%s:%s',
            $tipo === 'tv' ? 'tv' : 'movie',
            $imdbId,
            $temporada ?? '-',
            $episodio ?? '-'
        );

        $ttl = (int) config('services.torrents.legendas_cache_ttl', 21600);

        $cacheado = Cache::get($chave);

        if (is_array($cacheado)) {
            return $cacheado;
        }

        $resultado = $this->consultar($imdbId, $tipo, $temporada, $episodio);

        /*
         * Só o resultado positivo vai para o cache. Guardar o vazio gravaria por
         * horas uma falha passageira do acervo (um `504` do CDN, um blip de
         * rede) e o título ficaria "sem legenda" até o cache expirar. Como o
         * fallback só roda quando não há áudio PT-BR, repetir a consulta no caso
         * negativo não pesa no caminho comum.
         */
        if ($resultado !== []) {
            Cache::put($chave, $resultado, $ttl);
        }

        return $resultado;
    }

    /**
     * Pergunta ao acervo e devolve a lista já selecionada.
     *
     * @return array<int, array{srclang: string, label: string, url: string, origem: string}>
     */
    private function consultar(string $imdbId, string $tipo, ?int $temporada, ?int $episodio): array
    {
        $host = rtrim((string) config('services.torrents.legendas_host', 'https://data.vidsrc.sh'), '/');

        $consulta = [
            'type' => $tipo === 'tv' ? 'tv' : 'movie',
            'imdb' => $imdbId,
        ];

        // A numeração só existe no fluxo de série; no filme fica de fora.
        if ($tipo === 'tv' && $temporada !== null && $episodio !== null) {
            $consulta['season'] = $temporada;
            $consulta['episode'] = $episodio;
        }

        $resposta = $this->requisitar($host.self::CAMINHO, $consulta, $imdbId);

        if ($resposta === null) {
            return [];
        }

        $brutas = $resposta->json('default_subs', []);

        if (! is_array($brutas)) {
            return [];
        }

        return $this->selecionar($brutas);
    }

    /**
     * Pede a lista ao acervo, com algumas tentativas.
     *
     * O endpoint oscila: em rajadas o CDN à frente do acervo devolve `504` e a
     * mesma consulta responde `200` logo em seguida. Uma falha isolada não pode
     * custar a legenda do título, então insistimos algumas vezes antes de
     * desistir. Devolve `null` quando nenhuma tentativa conseguiu um `200`.
     *
     * @param  array<string, mixed>  $consulta
     */
    private function requisitar(string $caminho, array $consulta, string $imdbId): ?Response
    {
        $tentativas = max(1, (int) config('services.torrents.legendas_tentativas', 3));
        $timeout = (int) config('services.torrents.legendas_tempo_limite', 5);

        for ($tentativa = 1; $tentativa <= $tentativas; $tentativa++) {
            try {
                $resposta = Http::withHeaders(['User-Agent' => $this->agente()])
                    ->timeout($timeout)
                    ->get($caminho, $consulta);

                if ($resposta->status() === 200) {
                    return $resposta;
                }

                Log::debug('Busca de legendas: tentativa sem sucesso.', [
                    'imdb_id' => $imdbId,
                    'tentativa' => $tentativa,
                    'status' => $resposta->status(),
                ]);
            } catch (Throwable $excecao) {
                Log::debug('Busca de legendas: falha de rede na tentativa.', [
                    'imdb_id' => $imdbId,
                    'tentativa' => $tentativa,
                    'erro' => $excecao->getMessage(),
                ]);
            }

            if ($tentativa < $tentativas) {
                usleep(self::PAUSA_ENTRE_TENTATIVAS);
            }
        }

        return null;
    }

    /**
     * Escolhe uma legenda PT-BR e uma inglesa da lista crua do acervo.
     *
     * @param  array<int, mixed>  $brutas
     * @return array<int, array{srclang: string, label: string, url: string, origem: string}>
     */
    private function selecionar(array $brutas): array
    {
        $ptBr = null;
        $inglesCompleto = null;
        $inglesQualquer = null;

        foreach ($brutas as $bruta) {
            if (! is_array($bruta)) {
                continue;
            }

            $url = trim((string) ($bruta['url'] ?? ''));

            if ($url === '') {
                continue;
            }

            $codigo = strtolower(trim((string) ($bruta['code'] ?? '')));
            $rotulo = (string) ($bruta['lang'] ?? '');

            if ($codigo === 'pt' && $ptBr === null) {
                $ptBr = [
                    'srclang' => 'pt-BR',
                    'label' => 'Português (Brasil)',
                    'url' => $url,
                    'origem' => 'vidsrc',
                ];
            }

            if ($codigo === 'en') {
                $candidata = [
                    'srclang' => 'en',
                    'label' => 'English',
                    'url' => $url,
                    'origem' => 'vidsrc',
                ];

                $inglesQualquer ??= $candidata;

                // Placas e cifras descrevem só efeitos/músicas, não o diálogo:
                // guardamos como reserva, mas preferimos uma faixa completa.
                $soEfeitos = preg_match('/forced|signs|songs/i', $rotulo) === 1;

                if (! $soEfeitos) {
                    $inglesCompleto ??= $candidata;
                }
            }
        }

        $ingles = $inglesCompleto ?? $inglesQualquer;
        $legendas = [];

        if ($ptBr !== null) {
            $legendas[] = $ptBr;
        }

        if ($ingles !== null) {
            $legendas[] = $ingles;
        }

        return $legendas;
    }

    /** Agente de navegador usado nas consultas ao acervo. */
    private function agente(): string
    {
        return (string) (config('services.torrents.user_agent') ?: 'Mozilla/5.0');
    }
}
