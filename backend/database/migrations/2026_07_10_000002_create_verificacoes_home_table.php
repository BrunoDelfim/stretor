<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado da verificação de cada página da Home.
 *
 * A `pagina` é a PK porque há um mapa 1:1 entre a página pedida pelo frontend e
 * esta linha: enquanto o `status` não é `ok`, a Home serve o TMDB cru; quando é,
 * ela serve o `itens` (jsonb) — já filtrado para o que tem fonte, na ordem de
 * tendência do TMDB. Guardamos também `total_paginas` como o TMDB informou na
 * hora, para a rolagem infinita saber onde termina sem depender de nova consulta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verificacoes_home', function (Blueprint $tabela) {
            $tabela->unsignedInteger('pagina')->primary();
            $tabela->string('status', 20)->default('pendente');
            $tabela->jsonb('itens')->nullable();
            $tabela->unsignedInteger('total_paginas')->nullable();
            $tabela->unsignedInteger('itens_tmdb')->default(0);
            $tabela->timestamp('verificada_em')->nullable();
            $tabela->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verificacoes_home');
    }
};
