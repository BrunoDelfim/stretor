<?php

namespace App\Providers;

use App\Services\Torrents\ClienteHttp;
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

        /*
         * O cliente HTTP é um singleton pelo mesmo motivo, e por um motivo próprio:
         * os serviços de uma mesma busca — o provedor direto, a busca nos
         * agregadores, o motor web e o resolvedor de embeds — precisam compartilhar
         * o que aprenderam sobre o caminho até os sites. É aqui que fica, por
         * exemplo, a desistência do FlareSolverr nesta rodada: uma resposta `500`
         * dele custa cerca de 20 s, e sem esta marca cada serviço pagaria o mesmo
         * preço para receber o mesmo erro, comendo o orçamento da busca inteira.
         *
         * Como o contêiner é remontado a cada requisição, o singleton tem o escopo
         * de uma busca: a próxima começa com o socorro disponível de novo.
         */
        $this->app->singleton(ClienteHttp::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
