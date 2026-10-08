<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diz quem merece nova tentativa depois de um veredito negativo.
 *
 * Nem todo "sem fonte" tem o mesmo peso. Quando a busca não teve como perguntar
 * a todos os provedores, o negativo fala da busca, não do acervo — e esse título
 * merece outra chance logo, na virada do dia. Quando a busca perguntou a todos e
 * não havia nada, o acervo é que decidiu, e ele muda devagar: insistir todo dia
 * só gasta requisição nos trackers, então esse veredito espera a janela longa.
 *
 * Nasce `false` para as linhas existentes: todo veredito antigo foi gravado como
 * definitivo, que é justamente o lado paciente da regra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disponibilidades', function (Blueprint $tabela) {
            $tabela->boolean('deve_retentar')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('disponibilidades', function (Blueprint $tabela) {
            $tabela->dropColumn('deve_retentar');
        });
    }
};
