<?php

namespace App\Console\Commands;

use App\Enums\StatusVerificacao;
use App\Models\Disponibilidade;
use App\Models\VerificacaoExecucao;
use App\Models\VerificacaoHome;
use Illuminate\Console\Command;

/**
 * Mostra o estado da verificação sem encostar em nenhum tracker.
 *
 * É o companheiro de leitura do `fontes:verificar`: diz quantas páginas já estão
 * prontas, quanto já foi indexado e como terminou a última rodada. Serve para
 * decidir se vale disparar uma nova carga — e para confirmar, sem abrir o banco,
 * que a rotina automática está desligada onde deveria.
 */
class StatusFontes extends Command
{
    protected $signature = 'fontes:status';

    protected $description = 'Mostra o estado da verificação de fontes (não consulta trackers).';

    public function handle(): int
    {
        $paginasAlvo = (int) config('services.verificacao.paginas', 25);

        $porStatus = VerificacaoHome::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $ok = (int) ($porStatus[StatusVerificacao::OK->value] ?? 0);
        $andamento = (int) ($porStatus[StatusVerificacao::EM_ANDAMENTO->value] ?? 0);
        $verificadas = $ok + $andamento;

        $noIndice = Disponibilidade::count();
        $comFonte = Disponibilidade::where('tem_fonte', true)->count();

        $this->info('Status da verificação de fontes');
        $this->table(['Item', 'Valor'], [
            ['Páginas alvo', $paginasAlvo],
            ['Páginas verificadas (ok)', $ok],
            ['Páginas em andamento', $andamento],
            ['Páginas pendentes', max(0, $paginasAlvo - $verificadas)],
            ['Títulos no índice', $noIndice],
            ['Títulos com fonte', $comFonte],
            ['Títulos sem fonte', $noIndice - $comFonte],
        ]);

        $this->resumoUltimaExecucao();

        if (! config('services.verificacao.agendada')) {
            $this->newLine();
            $this->warn('Rotina automática DESLIGADA (VERIFICACAO_AGENDADA=false). Dispare sob comando.');
        }

        return self::SUCCESS;
    }

    private function resumoUltimaExecucao(): void
    {
        $ultima = VerificacaoExecucao::query()->latest('iniciada_em')->first();

        $this->newLine();

        if ($ultima === null) {
            $this->line('Nenhuma execução registrada ainda. Rode: php artisan fontes:verificar');

            return;
        }

        $this->line('Última execução:');
        $this->table(['Campo', 'Valor'], [
            ['Início', $ultima->iniciada_em?->format('d/m/Y H:i') ?? '—'],
            ['Fim', $ultima->finalizada_em?->format('d/m/Y H:i') ?? '—'],
            ['Status', $ultima->status],
            ['Páginas processadas', $ultima->paginas_processadas],
            ['Títulos testados', $ultima->titulos_testados],
            ['Títulos com fonte', $ultima->titulos_com_fonte],
        ]);
    }
}
