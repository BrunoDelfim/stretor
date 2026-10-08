<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico das fontes que já tocaram até o fim neste título.
 *
 * Uma linha aqui é prova de campo: não é a malha que o provedor promete, é o
 * arquivo que o usuário assistiu do começo ao fim. Guardar isso resolve o
 * problema mais caro do fluxo — a cada abertura a busca recomeça do zero e o
 * player paga de novo a tentativa e erro até achar uma fonte que sirva, mesmo
 * quando já havia uma que serviu ontem. Com o histórico, essa fonte volta na
 * frente da fila na próxima vez.
 *
 * `temporada` e `episodio` valem zero para filme, e não nulo: no Postgres dois
 * nulos não colidem no índice único, e a mesma fonte seria gravada duas vezes
 * pelo mesmo motivo.
 *
 * `legendas` guarda a lista com que a fonte tocou. Sem ela, reoferecer uma fonte
 * legendada (idioma original + legenda) tocaria o áudio original sem legenda
 * alguma — pior do que não ter histórico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fontes_reproduzidas', function (Blueprint $tabela) {
            $tabela->id();
            $tabela->string('tipo', 10);                 // filme | serie
            $tabela->unsignedBigInteger('tmdb_id');
            $tabela->unsignedSmallInteger('temporada')->default(0); // 0 = não se aplica
            $tabela->unsignedSmallInteger('episodio')->default(0);
            $tabela->string('fonte_id', 64);             // infohash do magnet ou md5 da URL
            $tabela->string('tipo_fonte', 10);           // torrent | direto
            $tabela->string('titulo')->nullable();
            $tabela->string('qualidade', 30)->nullable();
            $tabela->string('tamanho', 30)->nullable();
            $tabela->string('idioma', 20)->nullable();
            $tabela->string('provedor', 60)->nullable();
            $tabela->string('provedor_rotulo')->nullable();
            $tabela->unsignedInteger('seeds')->default(1);
            $tabela->unsignedInteger('peers')->default(0);
            $tabela->text('magnet')->nullable();
            $tabela->text('stream')->nullable();
            $tabela->json('legendas')->nullable();
            $tabela->unsignedInteger('reproducoes')->default(1);
            $tabela->timestamp('confirmada_em');
            $tabela->timestamps();

            // Nome curto de propósito: o nome gerado pela convenção passa dos 63
            // caracteres que o Postgres aceita para um identificador.
            $tabela->unique(['tipo', 'tmdb_id', 'temporada', 'episodio', 'fonte_id'], 'fontes_reproduzidas_unica');

            // A leitura é sempre "as comprovadas deste título", na ordem de uso.
            $tabela->index(['tipo', 'tmdb_id', 'temporada', 'episodio'], 'fontes_reproduzidas_titulo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fontes_reproduzidas');
    }
};
