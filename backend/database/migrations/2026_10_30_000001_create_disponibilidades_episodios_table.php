<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prova de fonte por episódio.
 *
 * A linha de `disponibilidades` responde pela série inteira, e a resposta dela é
 * a do primeiro episódio — a amostra mais barata de "esta série tem fonte". Isso
 * serve ao selo do card, mas não ao modal: uma série pode ter o E01 no ar e o
 * resto morto, e o usuário só descobria clicando em cada linha.
 *
 * Aqui cada episódio testado ganha o próprio veredito, com o mesmo rastro da
 * linha da série (de onde veio, qual a melhor fonte, quando foi verificado) para
 * a rotina saber o que já resolveu e o que venceu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disponibilidades_episodios', function (Blueprint $tabela) {
            $tabela->id();
            $tabela->unsignedBigInteger('tmdb_id');       // série no TMDB
            $tabela->unsignedSmallInteger('temporada');
            $tabela->unsignedSmallInteger('episodio');
            $tabela->boolean('tem_fonte')->default(false);
            $tabela->boolean('inconclusivo')->default(false);
            $tabela->boolean('deve_retentar')->default(false);
            $tabela->string('fonte_idioma')->nullable();
            $tabela->string('fonte_tipo')->nullable();    // torrent | direto
            $tabela->string('origem', 20);
            $tabela->timestamp('verificado_em')->nullable();
            $tabela->timestamps();

            // O episódio é identificado pela série mais a numeração; o nome do
            // índice é curto porque o gerado pela convenção passa dos 63
            // caracteres que o Postgres aceita.
            $tabela->unique(['tmdb_id', 'temporada', 'episodio'], 'disponibilidades_episodios_unica');

            // A leitura do modal é "os episódios desta temporada"; a da rotina é
            // "o que já tem prova e o que venceu".
            $tabela->index(['tmdb_id', 'temporada'], 'disponibilidades_episodios_temporada');
            $tabela->index(['tem_fonte', 'verificado_em'], 'disponibilidades_episodios_validade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disponibilidades_episodios');
    }
};
