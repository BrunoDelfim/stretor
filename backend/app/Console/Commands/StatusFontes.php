<?php

namespace App\Console\Commands;

use App\Enums\StatusVerificacao;
use App\Models\Disponibilidade;
use App\Models\VerificacaoExecucao;
use App\Models\VerificacaoHome;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Mostra o estado da verificação sem encostar em nenhum tracker.
 *
 * É o companheiro de leitura do `fontes:verificar`: diz quantas páginas estão
 * prontas, quanto já foi indexado, se a prova ainda está no prazo e como terminou
 * a última rodada. Serve para decidir se vale disparar uma nova carga — e para
 * perceber, sem abrir o banco, quando a Home está servindo uma lista vencida ou
 * uma página ficou travada no meio do caminho.
 */
class StatusFontes extends Command
{
    protected $signature = 'fontes:status';

    protected $description = 'Mostra o estado da verificação de fontes (não consulta trackers).';

    public function handle(): int
    {
        $paginasAlvo = (int) config('services.verificacao.paginas', 25);
        $validade = (int) config('services.verificacao.validade_horas', 24);
        $limite = now()->subHours($validade);

        $porStatus = VerificacaoHome::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $ok = (int) ($porStatus[StatusVerificacao::OK->value] ?? 0);
        $andamento = (int) ($porStatus[StatusVerificacao::EM_ANDAMENTO->value] ?? 0);

        // Página em andamento há mais que a validade é resíduo de uma rodada que
        // morreu no meio: ninguém está processando-a. O `fontes:verificar` a
        // devolve a pendente no começo da próxima execução.
        $travadas = VerificacaoHome::query()
            ->where('status', StatusVerificacao::EM_ANDAMENTO->value)
            ->where('updated_at', '<', $limite)
            ->count();

        // Frescor da Home: quantas páginas prontas já passaram do prazo (nessas, a
        // Home está servindo o TMDB do dia) e quando foi a última verificação.
        $vencidas = VerificacaoHome::query()->comProvaVencida($validade)->count();

        $ultimaPagina = VerificacaoHome::query()
            ->where('status', StatusVerificacao::OK->value)
            ->max('verificada_em');

        $noIndice = Disponibilidade::count();
        $comFonte = Disponibilidade::where('tem_fonte', true)->count();
        // O "sem fonte" se divide pela marca de retentativa: quanto volta amanhã
        // (a busca não concluiu) e quanto espera a janela longa (veredito).
        $retentaAmanha = Disponibilidade::where('tem_fonte', false)->where('deve_retentar', true)->count();
        $janelaLonga = Disponibilidade::where('tem_fonte', false)->where('deve_retentar', false)->count();
        $diasJanelaLonga = (int) config('services.verificacao.validade_dias_definitivo', 7);

        $this->info('Status da verificação de fontes');
        $this->table(['Item', 'Valor'], [
            ['Páginas alvo', $paginasAlvo],
            ['Páginas prontas (ok)', $ok],
            ["  das quais com prova vencida (> {$validade}h)", $vencidas],
            ['Páginas em andamento', $andamento],
            ['  das quais travadas (execução interrompida)', $travadas],
            ['Páginas pendentes', max(0, $paginasAlvo - $ok - $andamento)],
            ['Última página verificada', $ultimaPagina !== null
                ? Carbon::parse($ultimaPagina)->format('d/m/Y H:i')
                : '—'],
            ['Títulos no índice', $noIndice],
            ['Títulos com fonte', $comFonte],
            ['Títulos sem fonte', $noIndice - $comFonte],
            ['Sem fonte — volta amanhã', $retentaAmanha],
            ["Sem fonte — reexame em {$diasJanelaLonga} dias", $janelaLonga],
        ]);

        $this->resumoUltimaExecucao();

        $this->newLine();

        if (config('services.verificacao.agendada')) {
            $horario = config('services.verificacao.horario', '04:00');
            $this->line("Rotina automática LIGADA — diária às {$horario} pelo container `scheduler`.");
        } else {
            $this->warn('Rotina automática DESLIGADA (VERIFICACAO_AGENDADA=false). Dispare sob comando.');
        }

        if ($travadas > 0) {
            $this->warn('Há páginas travadas em "em andamento": o próximo `fontes:verificar` as libera.');
        }

        if ($vencidas > 0) {
            $this->warn('A Home está servindo o TMDB do dia nas páginas com prova vencida até a próxima rodada.');
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
