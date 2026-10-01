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
 *    online" — é o termo que casa com a página certa na maioria dos casos.
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
 *
 * ## Por que não há mais `site:` na query
 *
 * A versão anterior ancorava cada termo num domínio fixo (`site:tokyvideo.com`)
 * para forçar o motor a devolver páginas de player. O ganho de precisão era real,
 * mas o preço era alto: a busca inteira ficava refém de um punhado de domínios.
 * Bastava o Tokyvideo sair do ar — ou bloquear o IP do container — para o fallback
 * inteiro parar de achar qualquer coisa, mesmo com o Dailymotion e o Archive.org
 * vivos e cheios do mesmo título.
 *
 * A query agora é **natural**: o título, a numeração e a intenção de streaming,
 * sem operador de domínio. Quem decide se o resultado serve não é mais uma lista
 * fixa de domínios aceitos, e sim a **prova de mídia**: o [`MotorBuscaWeb`] aplica
 * uma lista negra ampla (fóruns, Q&A, suporte, redes sociais, PDFs, adultos) para
 * descartar o lixo grosso, e o [`ProvedorStreamDireto`] só aceita a página se a
 * extração encontrar um player ou um link direto de mídia. A separação é
 * deliberada — a query pergunta "onde está este vídeo?", e a extração responde
 * "aqui há vídeo de verdade". Trocar de provedor deixa de exigir mexer na query.
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
     * Devolve o título sem a numeração do episódio, quando ela já está nele.
     *
     * O `TorrentService` entrega o primeiro título já numerado ("Donas de Casa
     * Desesperadas S01E01"), porque a mesma lista alimenta os provedores de
     * torrent, que precisam da numeração. O scraper web, porém, monta a própria
     * numeração — e anexá-la a um título que já a traz gerava termos com duas
     * grafias ("... S01E01 1x01 ..."), que nenhum motor casa.
     *
     * A remoção só acontece quando a numeração declarada é **a mesma** pedida:
     * um título que declare outro episódio é preservado como veio, porque aí a
     * numeração pedida é informação nova. Sem numeração reconhecível, o título
     * volta intacto.
     */
    private function tituloSemNumeracao(string $titulo, ?int $temporada, ?int $episodio): string
    {
        if ($temporada === null || $episodio === null) {
            return $titulo;
        }

        $declarada = TermosBusca::numeracaoDoTitulo($titulo);

        if ($declarada === null
            || $declarada['temporada'] !== $temporada
            || $declarada['episodio'] !== $episodio) {
            return $titulo;
        }

        /*
         * A remoção cobre as mesmas grafias que `numeracoesDoEpisodio()` gera,
         * mais as variantes que os títulos de release usam ("S01.E01", "s1e1").
         * O espaço extra que sobra é colapsado para o termo não sair com buraco.
         */
        $padroes = [
            '/\b\d{1,2}x\d{1,3}\b/iu',
            '/\bs\d{1,2}[\s._-]*e\d{1,3}\b/iu',
            '/\btemporada\s*\d{1,2}\s*epis[oó]dio\s*\d{1,3}\b/iu',
            '/\b\d{1,2}ª\s*temporada\s*epis[oó]dio\s*\d{1,3}\b/iu',
        ];

        $limpo = preg_replace($padroes, ' ', $titulo) ?? $titulo;

        return trim((string) preg_replace('/\s+/u', ' ', $limpo));
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
     * A lista abre com o termo **mais específico possível**: título, numeração e
     * intenção de streaming juntos. É o que tem a maior chance de devolver a
     * página do episódio certo já na primeira consulta. Só depois vêm as
     * variações — sem intenção, sem numeração, com o título original —, que
     * dependem mais do ranqueamento do motor e por isso rendem menos.
     *
     * Nenhum termo carrega operador `site:`. A restrição de domínio saiu da query:
     * a busca pergunta pela web inteira, a lista negra do [`MotorBuscaWeb`] corta o
     * lixo grosso e a prova de mídia na extração decide o que serve. Assim,
     * derrubar um provedor não derruba a busca.
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

        /*
         * O título pode chegar já numerado. O `TorrentService` monta os termos de
         * episódio com `TermosBusca::episodio()`, então o primeiro título da lista
         * que chega ao fallback é "Donas de Casa Desesperadas S01E01" — a
         * numeração já está lá. Anexá-la de novo produzia "Donas de Casa
         * Desesperadas S01E01 1x01 assistir online dublado": um termo com duas
         * numerações que nenhum motor casa, e que ainda gastava orçamento antes de
         * os termos úteis serem tentados.
         *
         * Quando o título já declara a numeração pedida, ela sai do termo e a
         * numeração não é reanexada — o título vira a base limpa ("Donas de Casa
         * Desesperadas") e as variações de intenção continuam valendo. Se a
         * numeração declarada for de outro episódio, o título é usado como veio:
         * aí a numeração pedida é informação nova, não repetição.
         */
        $tituloBase = $this->tituloSemNumeracao($titulo, $temporada, $episodio);
        $jaNumerado = $tituloBase !== $titulo;

        if ($temporada !== null && $episodio !== null && ! $jaNumerado) {
            // O termo mais preciso primeiro: título + numeração + intenção. É o
            // que casa com a página do episódio sem depender do ranqueamento.
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

        /*
         * Quando o título já vinha numerado, a base limpa assume o lugar dele: os
         * termos de intenção passam a ser "Donas de Casa Desesperadas assistir
         * online dublado", sem a numeração repetida. O título original numerado
         * continua entrando como rede de segurança mais abaixo.
         */
        $titulo = $jaNumerado ? $tituloBase : $titulo;

        // O título solto com intenção fecha a lista: é o termo que acha a página
        // da série/filme inteira quando a numeração não aparece no título.
        foreach ($intencoes as $intencao) {
            $termos[] = "{$titulo} {$intencao}";
        }

        /*
         * Por último, as grafias alternativas (título original), sem intenção de
         * idioma: é a rede de segurança para quando o acervo PT-BR não tem página.
         *
         * O alternativo também pode chegar numerado — o `TorrentService` monta a
         * lista inteira com `TermosBusca::episodio()`, então "Desperate Housewives
         * S01E01" é tão comum quanto o título principal. Sem a mesma limpeza
         * aplicada acima, a numeração era anexada de novo e o termo saía com duas
         * grafias ("... S01E01 dublado 1x01"), que nenhum motor casa.
         */
        foreach ($this->titulosLimpos($titulosAlternativos) as $alternativo) {
            $alternativoBase = $this->tituloSemNumeracao($alternativo, $temporada, $episodio);
            $alternativoJaNumerado = $alternativoBase !== $alternativo;

            if ($temporada !== null && $episodio !== null && ! $alternativoJaNumerado) {
                foreach ($this->numeracoesDoEpisodio($temporada, $episodio) as $numeracao) {
                    $termos[] = "{$alternativo} {$numeracao}";
                }
            } else {
                $termos[] = $alternativoJaNumerado ? $alternativoBase : $alternativo;
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
