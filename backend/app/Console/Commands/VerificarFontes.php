<?php

namespace App\Console\Commands;

use App\Services\Verificacao\VerificadorHome;
use Illuminate\Console\Command;
use Throwable;

/**
 * Dispara a rotina de verificação de fontes na mão.
 *
 * Em desenvolvimento nada roda sozinho: é este comando que alimenta o portão da
 * Home. Ele mostra o que está acontecendo linha a linha — cada título testado e o
 * veredito — e fecha com o resumo da rodada, para não ser preciso abrir o banco
 * para saber o que aconteceu.
 */
class VerificarFontes extends Command
{
    protected $signature = 'fontes:verificar
        {--paginas= : Limita a N páginas (padrão: VERIFICACAO_PAGINAS)}
        {--limite= : Máximo de títulos a testar nesta rodada}
        {--sem-pausa : Desliga as pausas de furtividade (use só em teste)}';

    protected $description = 'Verifica quais títulos das páginas da Home têm fonte.';

    public function handle(VerificadorHome $verificador): int
    {
        $paginas = $this->option('paginas') !== null ? (int) $this->option('paginas') : null;
        $limite = $this->option('limite') !== null ? (int) $this->option('limite') : null;
        $pausas = ! $this->option('sem-pausa');

        $this->info('Verificação de fontes — começando.');

        if ($paginas !== null) {
            $this->line("  Páginas: {$paginas}");
        }

        if ($limite !== null) {
            $this->line("  Teto de títulos: {$limite}");
        }

        if (! $pausas) {
            $this->warn('  Pausas de furtividade DESLIGADAS (modo teste).');
        }

        $inicio = microtime(true);

        try {
            $execucao = $verificador->executar(
                log: fn (string $linha) => $this->line($linha),
                limitePaginas: $paginas,
                limiteTitulos: $limite,
                pausas: $pausas,
            );
        } catch (Throwable $excecao) {
            $this->newLine();
            $this->error('Falha na verificação: '.$excecao->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Concluído.');
        $this->table(['Métrica', 'Valor'], [
            ['Páginas processadas', $execucao->paginas_processadas],
            ['Títulos testados', $execucao->titulos_testados],
            ['Títulos com fonte', $execucao->titulos_com_fonte],
            ['Duração', $this->duracao((int) round(microtime(true) - $inicio))],
        ]);

        return self::SUCCESS;
    }

    /** Formata segundos como "1h 02min 03s", para o resumo ficar legível. */
    private function duracao(int $segundos): string
    {
        if ($segundos < 60) {
            return "{$segundos}s";
        }

        $minutos = intdiv($segundos, 60);
        $resto = $segundos % 60;

        if ($minutos < 60) {
            return sprintf('%dmin %02ds', $minutos, $resto);
        }

        $horas = intdiv($minutos, 60);
        $minutos %= 60;

        return sprintf('%dh %02dmin %02ds', $horas, $minutos, $resto);
    }
}
