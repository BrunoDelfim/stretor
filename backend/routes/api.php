<?php

use App\Http\Controllers\Api\MovieController;
use App\Http\Controllers\Api\PasseCloudflareController;
use App\Http\Controllers\Api\TorrentController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'stretor-api',
        'timestamp' => now()->toIso8601String(),
    ]);
});

Route::prefix('v1')->group(function () {
    Route::prefix('movies')->group(function () {
        Route::get('/popular', [MovieController::class, 'populares']);
        // Home unificada: filmes, animação e séries no mesmo fluxo.
        Route::get('/trending', [MovieController::class, 'tendencias']);
        Route::get('/search', [MovieController::class, 'buscar']);
        Route::get('/{id}', [MovieController::class, 'detalhes'])->whereNumber('id');
        // Detalhes de série e episódios de uma temporada, usados pelo modal de
        // série. Ficam antes de "/{id}" para que o segmento extra não seja
        // confundido com um id.
        Route::get('/{id}/serie', [MovieController::class, 'detalhesSerie'])->whereNumber('id');
        Route::get('/{id}/temporada/{numero}', [MovieController::class, 'temporada'])
            ->whereNumber('id')
            ->whereNumber('numero');
        // Fontes de torrent para o fluxo de reprodução. Fica depois da rota de
        // detalhes porque o segmento extra ("/fontes") não colide com o id.
        Route::get('/{id}/fontes', [TorrentController::class, 'fontes'])->whereNumber('id');
    });

    /*
     * Passe do Cloudflare dos hosts com desafio embutido (o superflix). É por
     * aqui que o navegador do usuário entrega o que ganhou ao vencer o widget
     * Turnstile — porque nenhum servidor o vence, nem o FlareSolverr. Vale para
     * este backend porque navegador e backend saem pelo mesmo IP, e expira com o
     * prazo que o host anuncia (45 minutos). Ver o [`PasseCloudflareController`].
     */
    Route::prefix('passe-cloudflare')->group(function () {
        Route::get('/', [PasseCloudflareController::class, 'estado']);
        Route::post('/', [PasseCloudflareController::class, 'registrar']);
        // A conferência fecha o ciclo: colar o passe não prova nada sozinho, e o
        // host recusa o par trocado devolvendo a mesma tela de verificação de
        // quem não apresentou nada. Aqui o backend pede a página de verdade e
        // relata o que recebeu, com o IP por onde ele sai.
        Route::post('/conferir', [PasseCloudflareController::class, 'conferir']);
        Route::delete('/', [PasseCloudflareController::class, 'esquecer']);
    });
});
