<?php

namespace App\Providers;

use App\Services\Torrents\OrcamentoBusca;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * O orçamento da busca é um singleton de propósito: o catálogo de
         * provedores e o cliente HTTP precisam enxergar o **mesmo** relógio. Se
         * cada um tivesse o seu, o catálogo saberia que o prazo acabou enquanto o
         * FlareSolverr ainda começaria uma espera de dezenas de segundos — que é
         * justamente o que estoura o tempo do frontend.
         */
        $this->app->singleton(OrcamentoBusca::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
