<?php

namespace App\Services\Verificacao;

use App\Enums\OrigemVerificacao;
use App\Models\DisponibilidadeEpisodio;
use App\Services\TmdbService;

/**
 * Verifica os episódios de uma temporada, um a um.
 *
 * O selo do card responde pela série pelo primeiro episódio — a amostra mais
 * barata. Isso não basta ao modal: a temporada tem dez, vinte linhas, e cada uma
 * promete uma fonte diferente. Aqui a pergunta é feita por episódio, com o mesmo
 * [`VerificadorFontes`] que o card usa, para que as duas respostas não divirjam.
 *
 * O trabalho é fatiado de propósito. Uma temporada inteira de uma vez seriam
 * minutos de busca dentro de uma requisição; o modal pede um punhado por vez,
 * mostra os selos que já existem e volta para os que faltam — sem prender a
 * abertura da tela. Quem abre o modal amanhã encontra a fila continuando de onde
 * parou, porque o que já foi provado fica no banco.
 */
class VerificadorEpisodios
{
    public function __construct(
        private readonly TmdbService $tmdb,
        private readonly VerificadorFontes $verificador,
    ) {}

    /**
     * Quantos episódios cada requisição do modal verifica.
     *
     * Três é o que cabe no tempo de uma resposta sem segurar o usuário: cada
     * episódio custa uma busca inteira, e a fila anda conforme ele olha a
     * temporada. O valor é configurável para o dia em que o hardware permitir
     * mais.
     */
    public function limitePorRequisicao(): int
    {
        return max(1, (int) config('services.verificacao.episodios_por_requisicao', 3));
    }

    /**
     * Os números dos episódios que ainda não têm prova dentro do prazo.
     *
     * "Sem prova" inclui o veredito "sem fonte" vencido: ele volta à fila como
     * qualquer outro. Um episódio com veredito definitivo recente — com ou sem
     * fonte — não é re-testado, porque a resposta ainda vale.
     *
     * @param  array<int, array<string, mixed>>  $episodios
     * @return array<int, int>
     */
    public function pendentes(int $serieId, int $temporada, array $episodios): array
    {
        $numeros = array_values(array_filter(array_map(
            fn (array $episodio): int => (int) ($episodio['numero'] ?? 0),
            $episodios
        )));

        if ($numeros === []) {
            return [];
        }

        $comProva = DisponibilidadeEpisodio::query()
            ->where('tmdb_id', $serieId)
            ->where('temporada', $temporada)
            ->whereIn('episodio', $numeros)
            ->get(['episodio', 'tem_fonte', 'deve_retentar', 'verificado_em'])
            ->filter(fn (DisponibilidadeEpisodio $linha): bool => ! $linha->expirado())
            ->pluck('episodio')
            ->map(fn ($numero): int => (int) $numero)
            ->all();

        return array_values(array_diff($numeros, $comProva));
    }

    /**
     * Os episódios que **nunca** passaram por verificação.
     *
     * É a fila da abertura do modal. Prova vencida não volta por aqui: ela é
     * assunto da varredura diária, que revisa o acervo sem prender a tela do
     * usuário. Assim a abertura só gasta trabalho quando há algo novo para
     * descobrir.
     *
     * @param  array<int, array<string, mixed>>  $episodios
     * @return array<int, int>
     */
    public function nuncaTestados(int $serieId, int $temporada, array $episodios): array
    {
        $numeros = array_values(array_filter(array_map(
            fn (array $episodio): int => (int) ($episodio['numero'] ?? 0),
            $episodios
        )));

        if ($numeros === []) {
            return [];
        }

        $conhecidos = DisponibilidadeEpisodio::query()
            ->where('tmdb_id', $serieId)
            ->where('temporada', $temporada)
            ->whereIn('episodio', $numeros)
            ->pluck('episodio')
            ->map(fn ($numero): int => (int) $numero)
            ->all();

        return array_values(array_diff($numeros, $conhecidos));
    }

    /**
     * Verifica até `$limite` episódios pendentes e conta o que sobrou.
     *
     * A ficha da série é buscada no TMDB porque a busca de fontes precisa de
     * título, ano e `imdb_id` — o mesmo trio que o player usa. Ela vem do cache
     * do serviço depois da primeira vez, então o custo real é a busca de fontes.
     *
     * O laço para por dois motivos: o número de episódios do turno e o relógio da
     * requisição. O segundo não é enfeite — o navegador desiste em 60 s, e uma
     * temporada de estreia pode fazer cada busca gastar o teto inteiro. Melhor
     * devolver três selos e `pendentes > 0` do que estourar o tempo e não devolver
     * nenhum.
     *
     * @param  array<int, array<string, mixed>>  $episodios
     * @return array{testados: array<int, int>, restantes: int}
     */
    public function verificar(
        int $serieId,
        int $temporada,
        array $episodios,
        ?int $limite = null,
        OrigemVerificacao $origem = OrigemVerificacao::PLAY,
        ?int $tetoSegundos = null,
    ): array {
        $pendentes = $this->pendentes($serieId, $temporada, $episodios);

        if ($pendentes === []) {
            return ['testados' => [], 'restantes' => 0];
        }

        $alvos = array_slice($pendentes, 0, $limite ?? $this->limitePorRequisicao());

        if ($alvos === []) {
            return ['testados' => [], 'restantes' => count($pendentes)];
        }

        $serie = $this->tmdb->detalhesSerie($serieId);
        // O teto da requisição existe porque o navegador desiste em 60 s. No job
        // em segundo plano quem chama passa um teto maior (ou o do próprio job),
        // e o trabalho pode ir até o fim da temporada.
        $tetoDaRequisicao = $tetoSegundos ?? (int) config('services.verificacao.orcamento_requisicao', 50);
        $inicio = microtime(true);

        $testados = [];

        foreach ($alvos as $numero) {
            $this->verificador->verificarEpisodio($serie, $temporada, $numero, $origem);
            $testados[] = $numero;

            if (microtime(true) - $inicio >= $tetoDaRequisicao) {
                break;
            }
        }

        return [
            'testados' => $testados,
            'restantes' => count($pendentes) - count($testados),
        ];
    }
}
