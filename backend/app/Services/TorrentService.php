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
     * montagem é por mérito de áudio, em duas chaves: **o idioma manda** e, dentro
     * do mesmo idioma, **o episódio vem antes do pack**. Assim o dublado de
     * episódio é o primeiro tentado, o pack dublado vem logo atrás, e o pack nunca
     * passa à frente de um episódio de áudio igual ou melhor — era o que fazia o
     * pack do Torrentio encobrir os episódios do Knaben, do TPB+ e do APIBay. O
     * pack segue sendo o último recurso: fica atrás do dublado, do dual e de todo
     * episódio do mesmo idioma.
     *
     * A pilha boa é só o áudio PT-BR provado — dublado e dual. Se ela alcança o
     * piso de `minimo_fontes`, a lista é só ela; senão, a reserva (legendado,
     * original e os packs de idioma não provado) completa até o teto de fontes.
     * Como a pilha boa só carrega as prioridades 0 e 1, a concatenação já sai em
     * ordem de idioma: nenhum original antes de um dublado ou dual.
     *
     * @param  array<int, array<string, mixed>>  $fontes
     * @return array<int, array<string, mixed>>
     */
    private function ordenar(array $fontes): array
    {
        $ordemProvedores = $this->ordemDeProvedores();

        $fontes = array_values(array_filter($fontes, function (array $fonte): bool {
            if (($fonte['seeds'] ?? 0) <= 0 || ($fonte['magnet'] ?? '') === '') {
                return false;
            }

            /*
             * O pack de idioma não provado é o socorro da série antiga e quase
             * nunca vem marcado como dublado. Ele só entra na lista pela exceção de
             * `packs_qualquer_idioma`; desligada, vale o corte de idioma normal e
             * ele é descartado. O pack com PT-BR provado não depende da exceção.
             */
            if (! empty($fonte['pack']) && ! $this->ePtBr($fonte)) {
                return (bool) config('services.torrents.packs_qualquer_idioma', true);
            }

            return true;
        }));

        $porMerito = fn (array $a, array $b): int
            => $this->chaveDeOrdem($a, $ordemProvedores) <=> $this->chaveDeOrdem($b, $ordemProvedores);

        $ptBr = [];
        $reserva = [];

        foreach ($fontes as $fonte) {
            if ($this->ePtBr($fonte)) {
                $ptBr[] = $fonte;
            } else {
                $reserva[] = $fonte;
            }
        }

        usort($ptBr, $porMerito);
        usort($reserva, $porMerito);

        $limite = MensagensTorrent::LIMITE_FONTES;
        $minimo = (int) config('services.torrents.minimo_fontes', 15);
        $apenasPtBr = (bool) config('services.torrents.apenas_pt_br', true);

        /*
         * Com base PT-BR suficiente, a lista é só o que serve direto. O mínimo é
         * um piso, não um teto: alcançado ele, a lista sobe até o teto de fontes
         * com o que há de bom. Sem base suficiente, a reserva completa até o teto,
         * para o player ter alternativa quando a fonte boa não responder.
         */
        if ($apenasPtBr && count($ptBr) >= $minimo) {
            return array_slice($ptBr, 0, $limite);
        }

        return array_slice(array_merge($ptBr, $reserva), 0, $limite);
    }

    /**
     * Ordem canônica dos provedores, do mais forte para o mais fraco.
     *
     * É a mesma ordem em que a cascata os consulta — os por identificador primeiro
     * (Torrentio e o addon Stremio), depois os nativos, o indexador e o YTS. Como o
     * idioma já decide a faixa principal, esta chave só desempata dentro da mesma
     * faixa, mantendo cada provedor em bloco em vez de intercalá-los por seeds.
     *
     * @return array<string, int>
     */
    private function ordemDeProvedores(): array
    {
        return [
            'torrentio' => 0,
            'addon_stremio' => 1,
            'trackers_br' => 2,
            'apibay' => 3,
            'knaben' => 4,
            'bt4g' => 5,
            'torznab' => 6,
            'yts' => 7,
        ];
    }

    /**
     * Chave de ordenação de uma fonte: idioma, episódio antes de pack, provedor e
     * seeds. Comparada em bloco, ela entrega a ordem final sem nenhuma regra
     * escondida dentro do comparador.
     *
     * @param  array<string, mixed>  $fonte
     * @param  array<string, int>  $ordemProvedores
     * @return array<int, int>
     */
    private function chaveDeOrdem(array $fonte, array $ordemProvedores): array
    {
        $idioma = IdiomaFonte::tryFrom((string) ($fonte['idioma'] ?? ''))?->prioridade() ?? 99;
        $pack = ! empty($fonte['pack']) ? 1 : 0;
        $provedor = $ordemProvedores[$fonte['provedor'] ?? ''] ?? 99;

        return [$idioma, $pack, $provedor, -(int) ($fonte['seeds'] ?? 0)];
    }

    /**
     * Diz se a fonte serve para a pilha boa da montagem.
     *
     * A etiqueta `pt_br` da cascata vem primeiro — ela já embute a promoção dos
     * packs cujo conteúdo provou o dublado. O idioma cru cobre a chamada fora da
     * cascata. Ficam de fora os packs de idioma não provado: eles não são áudio
     * PT-BR, são o socorro da série antiga. Vão para a reserva e, por serem pack,
     * só aparecem depois dos episódios de qualquer idioma — é o que mantém o pack
     * do Torrentio atrás dos episódios do Knaben, do TPB+ e do APIBay.
     *
     * @param  array<string, mixed>  $fonte
     */
    private function ePtBr(array $fonte): bool
    {
        if (($fonte['pt_br'] ?? null) === true) {
            return true;
        }

        return in_array(
            $fonte['idioma'] ?? '',
            [IdiomaFonte::DUBLADO->value, IdiomaFonte::DUAL_AUDIO->value],
            true
        );
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
