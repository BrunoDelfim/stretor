<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Rotina diária da verificação de fontes.
 *
 * Só é agendada quando VERIFICACAO_AGENDADA está ligada — o que, de propósito,
 * não acontece em desenvolvimento: aqui a carga é sempre disparada na mão com
 * `php artisan fontes:verificar`. No VPS, ligue o flag para o agendador (cron com
 * `php artisan schedule:run`, ou um serviço `schedule:work`) assumir a rotina.
 * Enquanto o flag estiver desligado, nada é registrado e o comando segue sendo o
 * único caminho de execução.
 */
if (config('services.verificacao.habilitada') && config('services.verificacao.agendada')) {
    Schedule::command('fontes:verificar')
        ->dailyAt(config('services.verificacao.horario', '04:00'))
        ->withoutOverlapping(240);

    /*
     * Resgate horário: a tarefa diária não se repete quando o horário passa em
     * branco.
     *
     * O `schedule:work` decide cada tarefa por `isDue()`, que olha o minuto
     * corrente da expressão cron — não "desde quando não roda". Se o container do
     * agendador for recriado depois das 04:00 (o `make aplicar` faz isso), a
     * rodada do dia simplesmente não acontece. A prova das páginas vence em
     * VERIFICACAO_VALIDADE_HORAS e a Home passa a servir o TMDB cru até a
     * madrugada seguinte: foi o que aconteceu depois de um deploy às 10h.
     *
     * O `--se-vencido` só varre quando há página pronta com prova vencida, e o
     * próprio comando recusa iniciar se houver execução em curso. No dia normal,
     * depois da rodada das 04:00, ele sai em silêncio de hora em hora.
     */
    Schedule::command('fontes:verificar --se-vencido')
        ->hourlyAt(20)
        ->withoutOverlapping(240);

    /*
     * Varredura dos episódios das séries que já têm fonte provada.
     *
     * Roda antes da rodada principal e em fatias: o `--limite` corta a execução
     * em 40 episódios, e o que sobra volta no dia seguinte. É o backfill que faz
     * o selo do modal dizer a verdade linha a linha — o card responde só pelo
     * primeiro episódio, e uma temporada inteira não cabe numa requisição nem
     * numa noite do notebook.
     */
    Schedule::command('fontes:episodios --limite='.(int) config('services.verificacao.episodios_limite_diario', 0))
        ->dailyAt(config('services.verificacao.horario_episodios', '02:30'))
        ->withoutOverlapping(600);
}
