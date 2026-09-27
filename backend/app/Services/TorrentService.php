<?php

namespace App\Services;

use App\Services\Torrents\CatalogoProvedores;
use App\Services\Torrents\TermosBusca;
use App\Support\MensagensTorrent;
use App\Enums\IdiomaFonte;
use Illuminate\Support\Facades\Log;

/**
 * Busca de fontes de torrent para um filme.
 *
 * Este serviço é a fachada do subsistema de torrents: o controller conhece
 * apenas `fontes()` e o contrato normalizado que ele devolve (título, qualidade,
 * idioma, tamanho, seeds e magnet). Quem consulta o quê, em que ordem e com qual
 * cache é responsabilidade do [`CatalogoProvedores`].
 *
 * A divisão é intencional:
 *
 * - **CatalogoProvedores** cuida da infraestrutura — o registro dos provedores, a
 *   cascata de fallback, o cache por provedor e a tolerância a falha.
 * - **TorrentService** cuida da regra de negócio — quais títulos buscar, como
 *   ordenar, o que descartar e o que registrar no log.
 *
 * Assim, acrescentar um provedor novo não encosta na regra de ordenação, e mudar
 * a política de idioma não encosta em HTTP.
 */
class TorrentService
{
    public function __construct(
        private readonly CatalogoProvedores $catalogo,
    ) {
    }

    /**
     * Fontes disponíveis para um filme, já ordenadas por prioridade.
     *
     * A ordenação coloca o dublado em PT-BR primeiro e, dentro do mesmo idioma,
     * as fontes com mais seeds. Fontes sem seeds são descartadas: não têm como
     * servir dados e só fariam o frontend perder tempo tentando.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fontes(
        string $titulo,
        ?int $ano = null,
        ?string $imdbId = null,
        ?string $tituloOriginal = null,
        ?int $temporada = null,
        ?int $episodio = null,
    ): array {
        // Quando temporada e episódio vêm preenchidos, a busca é de um episódio
        // de série: o termo passa a ser "Titulo S01E02" em vez do título solto.
        // Sem eles, o fluxo de filme segue exatamente como antes.
        $titulos = ($temporada !== null && $episodio !== null)
            ? $this->titulosDeEpisodio($titulo, $tituloOriginal, $temporada, $episodio)
            : $this->titulosDeBusca($titulo, $tituloOriginal);

        if (empty($titulos)) {
            return [];
        }

        $fontes = $this->ordenar(
            $this->catalogo->buscar($titulos, $ano, $imdbId, $temporada, $episodio)
        );

        $this->registrar($fontes, $titulos, $ano, $imdbId);

        return $fontes;
    }

    /**
     * Confirma se existe algum provedor utilizável na configuração atual.
     *
     * O controller usa isto para escolher a mensagem do aviso: "nenhuma fonte
     * encontrada" (o sistema procurou e não achou) é diferente de "nenhum
     * provedor pôde ser consultado" (falta configuração).
     */
    public function temProvedorDisponivel(): bool
    {
        return $this->catalogo->algumDisponivel();
    }

    /**
     * Monta a lista de títulos a tentar, sem repetir.
     *
     * O TMDB é consultado em PT-BR, então o título traduzido é o que os trackers
     * brasileiros publicam. O título original entra como segunda tentativa porque
     * algumas traduções ficam curtas demais para o buscador do site ("Homem-
     * Aranha" devolve o desenho, "Spider-Man" devolve o filme).
     *
     * @return array<int, string>
     */
    private function titulosDeBusca(string $titulo, ?string $tituloOriginal): array
    {
        $titulos = [];

        foreach ([$titulo, $tituloOriginal] as $candidato) {
            $candidato = trim((string) $candidato);

            if ($candidato !== '' && ! in_array($candidato, $titulos, true)) {
                $titulos[] = $candidato;
            }
        }

        return $titulos;
    }

    /**
     * Monta os termos de busca de um episódio, sem repetir.
     *
     * Cada episódio é um release próprio, então o termo precisa da numeração
     * "SxxExx". Tentamos o título traduzido e o original, como na busca de filme,
     * porque o tracker nacional publica pelo nome em PT-BR e os indexadores
     * internacionais pelo original.
     *
     * Além do termo puro, cada título rende as variações dubladas
     * ("... S01E01 dublado", "... S01E01 dual áudio"). Sem elas a cascata nunca
     * pergunta pelo release nacional: o termo puro devolve dezenas de lançamentos
     * em inglês e o dublado fica fora da primeira página dos provedores por nome.
     * Era por isso que uma série só trazia fontes "Idioma original" mesmo com o
     * indexador PT-BR configurado.
     *
     * A ordem importa: o termo puro vem primeiro porque é o que o Torrentio (busca
     * por identificador) ignora e os provedores por nome usam como base; as
     * variações dubladas entram logo depois para puxar o release nacional.
     *
     * @return array<int, string>
     */
    private function titulosDeEpisodio(
        string $titulo,
        ?string $tituloOriginal,
        int $temporada,
        int $episodio,
    ): array {
        $titulos = [];

        foreach ([$titulo, $tituloOriginal] as $candidato) {
            $candidato = trim((string) $candidato);

            if ($candidato === '') {
                continue;
            }

            $termo = TermosBusca::episodio($candidato, $temporada, $episodio);

            if (! in_array($termo, $titulos, true)) {
                $titulos[] = $termo;
            }

            foreach (TermosBusca::episodioDublado($candidato, $temporada, $episodio) as $dublado) {
                if (! in_array($dublado, $titulos, true)) {
                    $titulos[] = $dublado;
                }
            }
        }

        /*
         * Os packs entram por último, e a posição é a decisão que protege os
         * episódios que hoje funcionam: a cascata para no primeiro termo que
         * devolve dublado, então uma série recente continua sendo resolvida só
         * com os termos de episódio. O pack só é consultado quando nenhum deles
         * achou nada — que é exatamente o caso das séries antigas, cujos
         * episódios isolados já não têm seeds.
         */
        if (config('services.torrents.packs_habilitados', true)) {
            foreach ([$titulo, $tituloOriginal] as $candidato) {
                $candidato = trim((string) $candidato);

                if ($candidato === '') {
                    continue;
                }

                $packs = array_merge(
                    TermosBusca::packTemporada($candidato, $temporada),
                    TermosBusca::packTemporadaDublado($candidato, $temporada),
                );

                foreach ($packs as $pack) {
                    if (! in_array($pack, $titulos, true)) {
                        $titulos[] = $pack;
                    }
                }
            }
        }

        /*
         * Os termos de série entram por último — depois até dos packs —, e a
         * posição é o que protege o que hoje funciona: a cascata para no primeiro
         * termo que devolve dublado, então uma série recente é resolvida muito
         * antes de chegar aqui. Esta frente existe para o caso extremo, a série
         * antiga em que nem o episódio nem o "S01 completa" acham nada: os
         * buscadores por nome casam todas as palavras do termo, e "S01E01" e
         * "completa" são ruído suficiente para zerar o recall justamente onde os
         * packs nacionais vivem. Sem numeração e sem "completa", o termo pergunta
         * pela série pelo nome, que é como os packs multi-temporada aparecem
         * ("1ª 2ª 3ª Temporadas Dublado e Legendado"). Quem descarta o que não
         * cobrir a temporada pedida é o gate do [`CatalogoProvedores`].
         */
        if (config('services.torrents.termos_serie_habilitados', true)) {
            foreach ([$titulo, $tituloOriginal] as $candidato) {
                $candidato = trim((string) $candidato);

                if ($candidato === '') {
                    continue;
                }

                foreach (TermosBusca::serieDublado($candidato, $temporada) as $serie) {
                    if (! in_array($serie, $titulos, true)) {
                        $titulos[] = $serie;
                    }
                }
            }
        }

        return $titulos;
    }

    /**
     * Filtra e monta a lista final.
     *
     * O filtro de seeds é a primeira barreira contra fontes mortas. Depois, a
     * montagem é por mérito de idioma: as fontes PT-BR (dublado e dual áudio,
     * mais os packs que a inspeção provou dublados) vêm primeiro, na frente de
     * qualquer reserva.
     *
     * A regra do mínimo é o que resolve o problema das séries antigas. Quando há
     * poucas fontes PT-BR, a lista não fica curta nem é preenchida de originais
     * por acaso: ela completa **até o mínimo** com o que houver de reserva, e o
     * PT-BR continua no topo. Um filme com 7 dubladas e 40 originais devolve as 7
     * dubladas seguidas de 8 originais — nunca 20 originais com o dublado
     * escondido no meio.
     *
     * Com `apenas_pt_br` ligado, a reserva entra só até o mínimo. Desligado, ela
     * completa até o teto — o comportamento para o dia em que legendado e
     * original forem reproduzíveis.
     *
     * @param  array<int, array<string, mixed>>  $fontes
     * @return array<int, array<string, mixed>>
     */
    private function ordenar(array $fontes): array
    {
        $fontes = array_values(array_filter(
            $fontes,
            fn (array $fonte) => ($fonte['seeds'] ?? 0) > 0 && ($fonte['magnet'] ?? '') !== ''
        ));

        $porPrioridade = function (array $a, array $b): int {
            $prioridadeA = IdiomaFonte::tryFrom($a['idioma'] ?? '')?->prioridade() ?? 99;
            $prioridadeB = IdiomaFonte::tryFrom($b['idioma'] ?? '')?->prioridade() ?? 99;

            return $prioridadeA <=> $prioridadeB ?: $b['seeds'] <=> $a['seeds'];
        };

        $ptBr = [];
        $reserva = [];

        foreach ($fontes as $fonte) {
            if ($this->ePtBr($fonte)) {
                $ptBr[] = $fonte;
            } else {
                $reserva[] = $fonte;
            }
        }

        usort($ptBr, $porPrioridade);
        usort($reserva, $porPrioridade);

        $limite = MensagensTorrent::LIMITE_FONTES;
        $minimo = (int) config('services.torrents.minimo_fontes', 15);
        $apenasPtBr = (bool) config('services.torrents.apenas_pt_br', true);

        /*
         * Com base PT-BR suficiente, a lista é só o que serve direto — dublado,
         * dual e os packs que atravessam o corte de idioma. O mínimo é um piso,
         * não um teto: alcançado ele, nada mais é limitado e a lista sobe até o
         * teto de fontes com o que há de bom. Tratá-lo como teto foi o que
         * encolheu a lista de "American Horror Story" de 17 para 15 e ainda a
         * encheu de episódios em inglês.
         */
        if ($apenasPtBr && count($ptBr) >= $minimo) {
            return array_slice($ptBr, 0, $limite);
        }

        /*
         * Sem base PT-BR suficiente, a reserva completa a lista até o teto — não
         * até o mínimo —, para o player ter alternativas quando a fonte boa não
         * responder. É o caso do filme que só tem release em inglês.
         */
        return array_slice(array_merge($ptBr, $reserva), 0, $limite);
    }

    /**
     * Diz se a fonte serve para a faixa PT-BR da montagem.
     *
     * A etiqueta `pt_br` da cascata vem primeiro — ela já embute a promoção dos
     * packs cujo conteúdo provou o dublado. O idioma cru cobre a chamada fora da
     * cascata. Por último, o pack de temporada entra como fonte boa mesmo sem o
     * nome provar PT-BR: ele é o último recurso de uma série antiga, quase nunca
     * vem marcado como dublado e, sem esta exceção, caía na reserva e perdia
     * para os episódios em inglês na hora de completar a lista.
     *
     * @param  array<string, mixed>  $fonte
     */
    private function ePtBr(array $fonte): bool
    {
        if (($fonte['pt_br'] ?? null) === true) {
            return true;
        }

        if (in_array(
            $fonte['idioma'] ?? '',
            [IdiomaFonte::DUBLADO->value, IdiomaFonte::DUAL_AUDIO->value],
            true
        )) {
            return true;
        }

        return (bool) config('services.torrents.packs_qualquer_idioma', true)
            && ! empty($fonte['pack']);
    }

    /**
     * Registra no log por que a lista veio como veio.
     *
     * O log é a única forma de distinguir "não existe release dublado" de "a
     * classificação de idioma falhou": se há fontes e nenhuma dublada, o índice
     * funciona e a escassez é real; se não há fontes nenhuma, o problema é de
     * rede, de provedor fora do ar ou de configuração.
     *
     * @param  array<int, array<string, mixed>>  $fontes
     * @param  array<int, string>  $titulos
     */
    private function registrar(array $fontes, array $titulos, ?int $ano, ?string $imdbId): void
    {
        $ptBr = count(array_filter($fontes, fn (array $fonte) => $this->ePtBr($fonte)));

        $contexto = [
            'titulos' => $titulos,
            'ano' => $ano,
            'imdb_id' => $imdbId,
            'fontes' => count($fontes),
            // O par que conta a montagem: quantas PT-BR vieram no topo e quanta
            // reserva foi necessária para chegar ao mínimo. Sem ele, "a lista veio
            // cheia de original" fica indistinguível de "não havia dublado".
            'pt_br' => $ptBr,
            'reserva' => count($fontes) - $ptBr,
        ];

        if (empty($fontes)) {
            Log::warning('Nenhuma fonte de torrent encontrada para o título.', $contexto);

            return;
        }

        if (! $this->catalogo->temDublado($fontes)) {
            Log::info('Há fontes, mas nenhuma dublada em PT-BR para o título.', $contexto);
        }
    }
}
