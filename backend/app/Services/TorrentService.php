<?php

namespace App\Services;

use App\Services\Torrents\CatalogoProvedores;
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
    ): array {
        $titulos = $this->titulosDeBusca($titulo, $tituloOriginal);

        if (empty($titulos)) {
            return [];
        }

        $fontes = $this->ordenar(
            $this->catalogo->buscar($titulos, $ano, $imdbId)
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
     * Filtra e ordena as fontes.
     *
     * O filtro de seeds é a primeira barreira contra fontes mortas, e a ordenação
     * é o que faz o dublado aparecer antes do legendado na interface.
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

        return array_slice($fontes, 0, MensagensTorrent::LIMITE_FONTES);
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
