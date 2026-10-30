<?php

namespace App\Jobs;

use App\Enums\OrigemVerificacao;
use App\Services\TmdbService;
use App\Services\Verificacao\VerificadorEpisodios;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Verifica a temporada inteira em segundo plano.
 *
 * O modal não pode ser quem paga a conta: uma temporada de doze episódios são
 * minutos de busca, e o navegador desiste da requisição em 60 s. O `POST` do
 * modal só enfileira este trabalho e volta na hora; o selo aparece conforme o
 * worker grava cada linha, e o frontend acompanha pelo `GET` da temporada.
 *
 * O turno aqui é maior que o da requisição (não há ninguém esperando a
 * resposta) e não tem o teto de 40 s, mas continua sendo fatiado: cada episódio
 * grava a própria prova assim que fica pronto, então uma queda no meio do
 * caminho não perde o que já foi medido.
 */
class VerificarTemporada implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Teto de vida do job. Uma temporada longa (vinte e poucos episódios) com a
     * prova de bytes ligada passa de vinte minutos; o teto existe para o worker
     * não ficar preso num job morto.
     */
    public int $timeout = 3600;

    /**
     * Sem retentativa: o job é idempotente, mas repetir de imediato uma rodada
     * que falhou (provedores fora do ar) só gasta o ritmo. O que faltou volta na
     * próxima abertura da temporada ou na varredura da madrugada.
     */
    public int $tries = 1;

    public function __construct(
        public readonly int $serieId,
        public readonly int $temporada,
        public readonly OrigemVerificacao $origem = OrigemVerificacao::PLAY,
    ) {}

    /**
     * Impede que dois jobs da mesma temporada se sobreponham.
     *
     * Abrir o modal duas vezes — ou dois usuários abrindo a mesma série — não
     * pode duplicar a busca: o segundo pedido é descartado enquanto o primeiro
     * ainda está na fila ou rodando.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("temporada:{$this->serieId}:{$this->temporada}"))
                ->expireAfter(3600)
                ->dontRelease(),
        ];
    }

    public function handle(TmdbService $tmdb, VerificadorEpisodios $episodios): void
    {
        $dados = $tmdb->temporada($this->serieId, $this->temporada);
        $lista = $dados['episodios'] ?? [];

        if ($lista === []) {
            return;
        }

        $total = count($lista);
        $testados = 0;
        $rodadas = 0;

        /*
         * O laço tem teto próprio, além do teto de tempo: se `verificar()`
         * devolvesse sempre os mesmos episódios (um provedor que responde
         * "testado" sem gravar a prova), o `restantes` nunca chegaria a zero e o
         * worker ficaria preso até o `$timeout`. Uma rodada a mais que o número de
         * episódios já cobre a temporada inteira com folga.
         */
        while ($rodadas <= $total) {
            $rodadas += 1;

            $resultado = $episodios->verificar(
                serieId: $this->serieId,
                temporada: $this->temporada,
                episodios: $lista,
                limite: $total,
                origem: $this->origem,
                tetoSegundos: $this->timeout,
            );

            if ($resultado['testados'] === []) {
                break;
            }

            $testados += count($resultado['testados']);

            if ($resultado['restantes'] <= 0) {
                break;
            }
        }

        Log::info('Verificação de temporada concluída em segundo plano.', [
            'serie' => $this->serieId,
            'temporada' => $this->temporada,
            'episodios' => $testados,
        ]);
    }
}
