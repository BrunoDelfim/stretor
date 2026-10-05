<?php

namespace Tests\Feature;

use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * A garantia que o [`TestCase`] dá e de que a suíte inteira depende.
 *
 * O container exporta `CACHE_STORE=redis` em `$_SERVER`, e o `<env>` do
 * `phpunit.xml` não vence essa leitura — o isolamento existe só por causa do
 * `config()` no `TestCase`. Sem este teste, apagar aquela linha não quebraria
 * nada: o sintoma apareceria **fora** da suíte, como um cache de aplicação
 * esvaziado e um passe do Cloudflare colado que some no meio de uma busca, sem
 * log e sem erro.
 */
class IsolamentoDoCacheTest extends TestCase
{
    public function test_a_suite_nao_toca_no_cache_do_ambiente(): void
    {
        $this->assertSame('array', config('cache.default'));
        $this->assertNotInstanceOf(RedisStore::class, Cache::store()->getStore());
    }

    public function test_a_suite_nao_enfileira_nem_grava_sessao_no_redis(): void
    {
        $this->assertSame('sync', config('queue.default'));
        $this->assertSame('array', config('session.driver'));
    }
}
