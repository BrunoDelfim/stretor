<?php

use App\Http\Controllers\Api\MovieController;
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
        Route::get('/search', [MovieController::class, 'buscar']);
        Route::get('/{id}', [MovieController::class, 'detalhes'])->whereNumber('id');
        // Fontes de torrent para o fluxo de reprodução. Fica depois da rota de
        // detalhes porque o segmento extra ("/fontes") não colide com o id.
        Route::get('/{id}/fontes', [TorrentController::class, 'fontes'])->whereNumber('id');
    });
});
