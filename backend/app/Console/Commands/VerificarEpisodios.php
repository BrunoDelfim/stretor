<?php

namespace App\Console\Commands;

use App\Enums\OrigemVerificacao;
use App\Enums\TipoMidia;
use App\Models\Disponibilidade;
use App\Services\TmdbService;
use App\Services\Verificacao\VerificadorEpisodios;
use App\Services\Verificacao\VerificadorFontes;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Verifica, episódio a episódio, as séries que já têm fonte provada.
 *
 * A rodada da Home responde pela série pelo primeiro episódio — é o que o card
 * mostra. Isso deixa uma promessa em aberto: o E01 estar no ar não garante que o
 * E07 exista, e o modal mostrava o mesmo selo verde para a temporada inteira.
 * Este comando fecha a lacuna aos poucos, no ritmo do dia, gravando o veredito de
 * cada episódio em `disponibilidades_episodios`.
 *
 * O trabalho é incremental de propósito. Uma temporada de vinte episódios custa
 * vinte buscas completas; varrer todas as séries do índice de uma vez levaria
 * horas e disputaria a rede com a rodada principal. O `--limite` corta a
 * execução, e o que ficou de fora volta amanhã — o banco guarda o que já foi
 * provado e a fila continua de onde parou.
 *
 * O modal não depende deste comando: ele pede a verificação dos episódios da
 * temporada aberta por conta própria. Aqui é o backfill do acervo inteiro.
 */
class VerificarEpisodios extends Command
{
    protected $signature = 'fontes:episodios
        {--serie= : Verifica apenas esta série (id do TMDB)}
        {--temporada= : Limita a verificação a uma temporada}
        {--limite=40 : Máximo de episódios testados nesta execução}
        {--sem-pausa : Desliga as pausas entre episódios (use só em teste)}';

    protected $description = 'Verifica cada episódio das séries que já têm fonte provada.';

    public function handle(
        TmdbService $tmdb,
        VerificadorEpisodios $verificador,
        VerificadorFontes $fontes,
    ): int {
        $limite = max(1, (int) $this->option('limite'));
        $apenasSerie = $this->option('serie') !== null ? (int) $this->option('serie') : null;
        $apenasTemporada = $this->option('temporada') !== null ? (int) $this->option('temporada') : null;
        $pausaMs = $this->option('sem-pausa')
            ? 0
            : (int) config('services.verificacao.pausa_itens_ms', 6000);

        /*
         * A fila é a das séries que o card já mostra como verificadas. São essas
         * que prometem algo ao usuário; as que não têm fonte não ganham nada com
         * a varredura episódio a episódio, e a rodada da Home já cuida delas.
         * A ordem é a da prova mais antiga: as que estão há mais tempo sem
         * revisão são as que mais precisam.
         */
        $series = Disponibilidade::query()
            ->where('tipo', TipoMidia::SERIE->value)
            ->where('tem_fonte', true)
            ->when($apenasSerie !== null, fn ($consulta) => $consulta->where('tmdb_id', $apenasSerie))
            ->orderBy('verificado_em')
            ->pluck('tmdb_id');

        if ($series->isEmpty()) {
            $this->info('Nenhuma série com fonte provada para verificar por episódio.');

            return self::SUCCESS;
        }

        $this->info('Verificação por episódio — começando.');
        $this->line('  Limite desta execução: '.$limite.' episódio(s)');

        $testados = 0;
        $comFonte = 0;

        foreach ($series as $id) {
            if ($testados >= $limite) {
                break;
            }

            $testados += $this->verificarSerie(
                (int) $id,
                $tmdb,
                $verificador,
                $fontes,
                $limite - $testados,
                $apenasTemporada,
                $pausaMs,
                $comFonte,
            );
        }

        $this->newLine();
        $this->info("Fim: {$testados} episódio(s) testado(s), {$comFonte} com fonte.");

        return self::SUCCESS;
    }

    /**
     * Passa pelas temporadas de uma série e testa os episódios que faltam.
     *
     * @param  int  $restante  Quantos episódios ainda cabem nesta execução.
     * @param  int  $comFonte  Acumula os episódios que saíram com fonte.
     * @return int Quantos episódios foram testados.
     */
    private function verificarSerie(
        int $id,
        TmdbService $tmdb,
        VerificadorEpisodios $verificador,
        VerificadorFontes $fontes,
        int $restante,
        ?int $apenasTemporada,
        int $pausaMs,
        int &$comFonte,
    ): int {
        try {
            $ficha = $tmdb->detalhesSerie($id);
        } catch (RuntimeException $excecao) {
            $this->warn("  Série {$id}: ficha indisponível ({$excecao->getMessage()}).");

            return 0;
        }

        $this->line("  Série {$ficha['titulo']} ({$id})");

        $temporadas = $apenasTemporada !== null
            ? [['numero' => $apenasTemporada]]
            : ($ficha['temporadas'] ?? []);

        $testados = 0;

        foreach ($temporadas as $temporada) {
            if ($testados >= $restante) {
                break;
            }

            $numero = (int) ($temporada['numero'] ?? 0);

            if ($numero < 1) {
                continue;
            }

            try {
                $episodios = $tmdb->temporada($id, $numero)['episodios'] ?? [];
            } catch (RuntimeException $excecao) {
                $this->warn("    T{$numero}: episódios indisponíveis ({$excecao->getMessage()}).");

                continue;
            }

            $pendentes = $verificador->pendentes($id, $numero, $episodios);

            if ($pendentes === []) {
                $this->line("    T{$numero}: tudo provado.");

                continue;
            }

            $this->line('    T'.$numero.': '.count($pendentes).' episódio(s) sem prova.');

            foreach ($pendentes as $episodio) {
                if ($testados >= $restante) {
                    break 2;
                }

                $veredito = $fontes->verificarEpisodio($ficha, $numero, $episodio, OrigemVerificacao::COMANDO);

                $testados++;

                if ($veredito->tem_fonte) {
                    $comFonte++;
                }

                $this->line(sprintf(
                    '      T%dE%02d %s',
                    $numero,
                    $episodio,
                    $veredito->tem_fonte ? 'fonte: '.($veredito->fonte_idioma ?? '—') : 'sem fonte',
                ));

                if ($pausaMs > 0) {
                    usleep($pausaMs * 1000);
                }
            }
        }

        return $testados;
    }
}
