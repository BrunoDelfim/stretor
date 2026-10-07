<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Log das execuções da rotina de verificação.
 *
 * Cada rodada deixa uma linha: quando começou, quando terminou, quantas páginas
 * processou e quantos títulos testou/achou com fonte. Serve para auditar o custo
 * real que a verificação tem sobre os trackers — quantidade e ritmo de saída —
 * que é a matéria-prima do ajuste fino de furtividade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verificacoes_execucoes', function (Blueprint $tabela) {
            $tabela->id();
            $tabela->string('origem', 20)->default('comando');
            // em_andamento | ok | falha — a execução interrompida fica presa em
            // em_andamento, e é o status que a próxima rodada espera encontrar.
            $tabela->string('status', 20)->default('em_andamento');
            $tabela->timestamp('iniciada_em')->nullable();
            $tabela->timestamp('finalizada_em')->nullable();
            $tabela->unsignedInteger('paginas_processadas')->default(0);
            $tabela->unsignedInteger('titulos_testados')->default(0);
            $tabela->unsignedInteger('titulos_com_fonte')->default(0);
            $tabela->text('detalhe')->nullable();
            $tabela->timestamps();

            $tabela->index(['iniciada_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verificacoes_execucoes');
    }
};
