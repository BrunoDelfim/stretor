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
        ->withoutOverlapping();
}
