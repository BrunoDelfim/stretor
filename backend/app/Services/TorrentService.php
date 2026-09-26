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

        return $titulos;
    }

    /**
     * Filtra e ordena as fontes.
     *
     * O filtro de seeds é a primeira barreira contra fontes mortas, e a ordenação
     * é o que faz o dublado aparecer antes do legendado na interface.
     *
     * O corte no limite não é cego: as fontes dubladas e em dual áudio são o que
     * o usuário brasileiro procura, e costumam ter pouquíssimos seeds (um release
     * nacional raramente compete com um WEB-DL gringo de 70 seeds). Cortar a lista
     * ordenada em 20 descartaria justamente essas fontes quando o provedor devolve
     * muitas opções em inglês — foi o que aconteceu com "Grey's Anatomy", em que a
     * única dublada ficou de fora enquanto 19 originais entraram. Por isso o corte
     * reserva espaço para todas as dubladas/dual e só então completa o restante
     * com as demais, respeitando o limite total.
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

        usort($fontes, function (array $a, array $b) {
            $prioridadeA = IdiomaFonte::tryFrom($a['idioma'] ?? '')?->prioridade() ?? 99;
            $prioridadeB = IdiomaFonte::tryFrom($b['idioma'] ?? '')?->prioridade() ?? 99;

            return $prioridadeA <=> $prioridadeB ?: $b['seeds'] <=> $a['seeds'];
        });

        $limite = MensagensTorrent::LIMITE_FONTES;

        if (count($fontes) <= $limite) {
            return $fontes;
        }

        $nacionais = array_filter(
            $fontes,
            fn (array $fonte) => in_array(
                $fonte['idioma'] ?? '',
                [IdiomaFonte::DUBLADO->value, IdiomaFonte::DUAL_AUDIO->value],
                true
            )
        );

        // Se as dubladas já preenchem o limite, elas são a resposta: devolvemos
        // só elas, sem gastar espaço com originais.
        if (count($nacionais) >= $limite) {
            return array_slice(array_values($nacionais), 0, $limite);
        }

        $restantes = array_filter(
            $fontes,
            fn (array $fonte) => ! in_array(
                $fonte['idioma'] ?? '',
                [IdiomaFonte::DUBLADO->value, IdiomaFonte::DUAL_AUDIO->value],
                true
            )
        );

        return array_merge(
            array_values($nacionais),
            array_slice(array_values($restantes), 0, $limite - count($nacionais))
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
        $contexto = [
            'titulos' => $titulos,
            'ano' => $ano,
            'imdb_id' => $imdbId,
            'fontes' => count($fontes),
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
