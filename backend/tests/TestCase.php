<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * A base de todos os testes — e o único lugar onde o isolamento do ambiente é
 * garantido.
 *
 * O bloco `<env>` do `phpunit.xml` **não vale** neste stack, e é bom que o motivo
 * esteja escrito: o PHPUnit aplica cada chave em `$_ENV` e no `getenv()`, mas o
 * Docker deixa o mesmo nome em `$_SERVER` (`CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`,
 * `SESSION_DRIVER=redis`), e o repositório de `Env` do Laravel lê `$_SERVER`
 * **primeiro**. Medido dentro do processo de teste, a chave ficava contraditória:
 * `env="array"`, `server="redis"` — e o que valia era o `redis`.
 *
 * O custo disso não é teórico: os testes rodavam contra o **cache de verdade**, e
 * os `Cache::flush()` de `CacheBypassTest`, `CacheVazioTest` e dos testes do passe
 * esvaziavam o Redis da aplicação a cada execução. Foi assim que um passe do
 * Cloudflare colado na bancada desapareceu no meio de uma investigação — sem log,
 * sem erro, como se nunca tivesse existido. Aqui a troca é feita por `config()`,
 * que não depende de quem lê o ambiente, e vale para a suíte inteira.
 */
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'session.driver' => 'array',
            'queue.default' => 'sync',
        ]);
    }
}
