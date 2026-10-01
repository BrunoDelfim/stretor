<?php

namespace App\Services\Torrents;

/**
 * Monta os termos de busca do scraper de stream direto.
 *
 * O provedor de torrents pergunta pelo nome do release ("Titulo S01E01 1080p").
 * O scraper web pergunta outra coisa: ele procura uma **página de streaming**, e
 * quem publica essas páginas escreve o título do jeito que o usuário digita no
 * Google — sem tag de qualidade, sem codec, sem grupo. Por isso os termos daqui
 * são outros, e é esta trait que os separa da lógica de torrent.
 *
 * A construção é em camadas, da mais específica para a mais ampla:
 *
 * 1. **Título + numeração + intenção.** "Donas de Casa Desesperadas 1x01 assistir
 *    online dublado" — é o termo que casa com a página certa na maioria dos casos.
 * 2. **Título + numeração, sem intenção.** Alguns indexadores de streaming não
 *    repetem "assistir online" no título da página; o termo enxuto os alcança.
 * 3. **Título solto + intenção.** Último recurso para quando a numeração atrapalha
 *    (a página cobre a série inteira e não declara o episódio no título).
 * 4. **Título original + numeração, sem intenção.** Quando o acervo PT-BR não tem
 *    página nenhuma, o título original ("Desperate Housewives S01E01") alcança os
 *    sites que publicam a série pelo nome de origem. A intenção de idioma sai de
 *    cena de propósito: a ideia é achar **qualquer** página de vídeo e deixar a
 *    normalização posterior decidir o que serve.
 *
 * A ordem importa: o provedor consome os termos de cima para baixo e para assim
 * que junta páginas suficientes, então o termo mais preciso é sempre o primeiro.
 */
trait TermosStreamDireto
{
    /**
     * Grafias de numeração aceitas pelos sites de streaming.
     *
     * O mesmo episódio aparece como "1x01", "S01E01", "Temporada 1 Episódio 1" e
     * "1ª Temporada Episódio 1" conforme o site. Mandar só uma grafia deixaria
     * páginas boas de fora, então o provedor pergunta por todas — cada uma é uma
     * consulta, e o orçamento curto do fallback corta o excesso.
     *
     * @return array<int, string>
     */
    protected function numeracoesDoEpisodio(int $temporada, int $episodio): array
    {
        $t = str_pad((string) $temporada, 2, '0', STR_PAD_LEFT);
        $e = str_pad((string) $episodio, 2, '0', STR_PAD_LEFT);

        return array_values(array_unique([
            "{$temporada}x{$e}",
            "S{$t}E{$e}",
            "Temporada {$temporada} Episódio {$episodio}",
            "{$temporada}ª Temporada Episódio {$episodio}",
        ]));
    }

    /**
     * Plataformas de vídeo conhecidas, alvo explícito da busca.
     *
     * O SearXNG agrega a web inteira e, para um título conhecido, as primeiras
     * posições são de catálogo, fórum e enciclopédia — nada disso hospeda o
     * arquivo. Ancorar a consulta numa plataforma que **de fato** publica vídeo
     * (`site:tokyvideo.com`) faz o motor devolver páginas de player em vez de
     * páginas *sobre* o título, e o orçamento curto do fallback rende mais.
     *
     * A ordem é de propósito: primeiro as plataformas com acervo PT-BR forte
     * (Tokyvideo, Dailymotion, OK.ru), depois as que hospedam vídeo mas em
     * qualquer idioma. A normalização posterior ainda filtra o idioma — aqui o
     * objetivo é só trazer candidatos que o extrator consiga abrir.
     *
     * @return array<int, string>
     */
    protected function plataformasDeVideo(): array
    {
        $configuradas = config('services.torrents.stream_direto_plataformas', []);

        if (is_array($configuradas) && $configuradas !== []) {
            return array_values(array_filter(
                array_map(static fn ($termo): string => trim((string) $termo), $configuradas),
                static fn (string $termo): bool => $termo !== ''
            ));
        }

        return [
            'site:tokyvideo.com',
            'site:dailymotion.com',
            'site:ok.ru',
            'site:vimeo.com',
        ];
    }

    /**
     * Termos de intenção de streaming, na ordem em que valem a consulta.
     *
     * "assistir online" é o que os sites brasileiros usam no `<title>`; "dublado"
     * filtra a versão com áudio PT-BR, que é o alvo do fallback. "legendado" entra
     * como reserva porque uma página legendada ainda é melhor que nada quando o
     * dublado não existe.
     *
     * A ordem começa pelos termos que puxam **agregadores de vídeo** ("assistir
     * online", "dublado") e só depois abre para os genéricos. O motivo é o
     * ranqueamento do motor de busca: para qualquer título conhecido, as primeiras
     * posições são ocupadas por páginas de catálogo e metadados (JustWatch, IMDb,
     * Plex, YouTube oficial) — que a lista negra do [`MotorBuscaWeb`] descarta. Um
     * termo que já nasce apontado para streaming ("assistir online dublado") faz o
     * motor trazer os sites que de fato hospedam o vídeo para as primeiras
     * posições, reduzindo o desperdício de orçamento com páginas inúteis.
     *
     * @return array<int, string>
     */
    protected function intencoesDeStreaming(): array
    {
        $configuradas = config('services.torrents.stream_direto_termos', []);

        if (is_array($configuradas) && $configuradas !== []) {
            return array_values(array_filter(
                array_map(static fn ($termo): string => trim((string) $termo), $configuradas),
                static fn (string $termo): bool => $termo !== ''
            ));
        }

        return [
            'assistir online dublado',
            'assistir online legendado',
            'assistir online',
            'filme completo dublado',
            'serie completa dublada',
        ];
    }

    /**
     * Monta a lista de termos de busca, do mais preciso ao mais amplo.
     *
     * A lista abre com os termos **ancorados em plataformas de vídeo**
     * (`site:tokyvideo.com`): são os que têm a maior chance de devolver uma
     * página de player já na primeira consulta, porque restringem o motor a
     * domínios que de fato hospedam o arquivo. Só depois vêm as intenções
     * genéricas, que dependem do ranqueamento do motor e por isso rendem menos.
     *
     * `$titulosAlternativos` são as outras grafias do nome (tipicamente o título
     * original). Elas entram **por último**, e só na forma genérica — sem a
     * intenção de idioma —, porque a função delas é justamente cobrir o caso em
     * que o acervo PT-BR não tem página: aí vale achar qualquer página de vídeo e
     * deixar a normalização filtrar.
     *
     * @param  array<int, string>  $titulosAlternativos
     * @return array<int, string>
     */
    protected function termosDeStreaming(
        string $titulo,
        ?int $temporada = null,
        ?int $episodio = null,
        array $titulosAlternativos = [],
    ): array {
        $titulo = trim($titulo);

        if ($titulo === '') {
            return [];
        }

        $termos = [];
        $intencoes = $this->intencoesDeStreaming();
        $plataformas = $this->plataformasDeVideo();

        // A âncora de plataforma vem primeiro: é o termo que casa com a página
        // certa sem depender do ranqueamento do motor. A numeração entra quando
        // existe, para o episódio não virar a série inteira.
        foreach ($plataformas as $plataforma) {
            if ($temporada !== null && $episodio !== null) {
                foreach ($this->numeracoesDoEpisodio($temporada, $episodio) as $numeracao) {
                    $termos[] = "{$titulo} {$numeracao} {$plataforma}";
                }
            } else {
                $termos[] = "{$titulo} {$plataforma}";
            }
        }

        if ($temporada !== null && $episodio !== null) {
            foreach ($this->numeracoesDoEpisodio($temporada, $episodio) as $numeracao) {
                foreach ($intencoes as $intencao) {
                    $termos[] = "{$titulo} {$numeracao} {$intencao}";
                }
            }

            // A numeração sozinha, sem a intenção: alcança os sites que não
            // repetem "assistir online" no título da página.
            foreach ($this->numeracoesDoEpisodio($temporada, $episodio) as $numeracao) {
                $termos[] = "{$titulo} {$numeracao}";
            }
        }

        // O título solto com intenção fecha a lista: é o termo que acha a página
        // da série/filme inteira quando a numeração não aparece no título.
        foreach ($intencoes as $intencao) {
            $termos[] = "{$titulo} {$intencao}";
        }

        // Por último, as grafias alternativas (título original), sem intenção de
        // idioma: é a rede de segurança para quando o acervo PT-BR não tem página.
        foreach ($this->titulosLimpos($titulosAlternativos) as $alternativo) {
            if ($temporada !== null && $episodio !== null) {
                foreach ($this->numeracoesDoEpisodio($temporada, $episodio) as $numeracao) {
                    $termos[] = "{$alternativo} {$numeracao}";
                }
            } else {
                $termos[] = $alternativo;
            }
        }

        return $this->limitarTermos(array_values(array_unique($termos)));
    }

    /**
     * Corta a cauda da lista de termos, preservando os mais precisos.
     *
     * Um episódio gera dezenas de termos: 4 grafias de numeração × 5 intenções,
     * mais as variações sem intenção e o título original. Cada termo é uma
     * requisição ao motor de busca, e buscadores bloqueiam quem dispara em
     * rajada — consultar todos de uma vez é o caminho mais curto para o rate
     * limit.
     *
     * A lista já vem ordenada do mais preciso ao mais amplo, então cortar a cauda
     * descarta justamente os termos genéricos, que são os que menos rendem. O
     * teto é configurável (`stream_direto_max_termos`); zero ou negativo desliga
     * o corte.
     *
     * @param  array<int, string>  $termos
     * @return array<int, string>
     */
    private function limitarTermos(array $termos): array
    {
        $teto = (int) config('services.torrents.stream_direto_max_termos', 8);

        if ($teto <= 0 || count($termos) <= $teto) {
            return $termos;
        }

        return array_slice($termos, 0, $teto);
    }

    /**
     * Normaliza a lista de títulos alternativos, descartando vazios e repetidos.
     *
     * @param  array<int, string>  $titulos
     * @return array<int, string>
     */
    private function titulosLimpos(array $titulos): array
    {
        $limpos = [];

        foreach ($titulos as $titulo) {
            $titulo = trim((string) $titulo);

            if ($titulo !== '') {
                $limpos[] = $titulo;
            }
        }

        return array_values(array_unique($limpos));
    }
}
