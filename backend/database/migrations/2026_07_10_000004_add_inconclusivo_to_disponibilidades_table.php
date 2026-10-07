<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Separa "não tem fonte" de "não deu para concluir".
 *
 * Até aqui, toda lista vazia virava `tem_fonte = false` e travava o título por
 * 24 h. Mas uma busca só pode dizer "não tem fonte" quando **teve como perguntar
 * a todos**: se nenhum provedor estava utilizável, se o orçamento cortou a
 * cascata no meio, ou se um provedor quebrou, o vazio fala da busca — não do
 * acervo. A coluna marca exatamente esse caso: o título continua candidato e
 * volta à fila já na próxima rodada, sem esperar a validade da prova.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('disponibilidades', function (Blueprint $tabela) {
            // Nasce `false` para as linhas existentes: todo veredito antigo foi
            // gravado como definitivo antes de o guard existir.
            $tabela->boolean('inconclusivo')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('disponibilidades', function (Blueprint $tabela) {
            $tabela->dropColumn('inconclusivo');
        });
    }
};
