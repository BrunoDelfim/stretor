<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índice de disponibilidade por título.
 *
 * Uma linha por (tipo, tmdb_id) responde à pergunta central do sistema: este
 * título tem fonte? O `tem_fonte` é o veredito; o resto guarda o rastro da prova
 * (de onde veio, qual a melhor fonte, quando foi verificado) para a rotina saber
 * o que já resolveu e o que ainda precisa tentar de novo depois de vencido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disponibilidades', function (Blueprint $tabela) {
            $tabela->id();
            $tabela->string('tipo', 10);            // filme | serie
            $tabela->unsignedBigInteger('tmdb_id');
            $tabela->boolean('tem_fonte')->default(false);
            $tabela->string('fonte_idioma')->nullable();
            $tabela->string('fonte_tipo')->nullable(); // torrent | direto
            $tabela->string('origem', 20);
            $tabela->timestamp('verificado_em')->nullable();
            $tabela->timestamps();

            // A rotina consulta o par (tipo, tmdb_id) o tempo todo, e um título
            // só pode ter um veredito — o par é a chave natural.
            $tabela->unique(['tipo', 'tmdb_id']);

            // A varredura filtra por presença de fonte e por validade da prova.
            $tabela->index(['tem_fonte', 'verificado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disponibilidades');
    }
};
