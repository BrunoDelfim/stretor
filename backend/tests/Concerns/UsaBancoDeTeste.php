<?php

namespace Tests\Concerns;

use PDO;
use PDOException;

/**
 * Isola os testes que escrevem no banco em um banco próprio.
 *
 * O `RefreshDatabase` roda `migrate:fresh`, e esse comando não pode encostar no
 * banco de desenvolvimento, que carrega os dados reais da aplicação. O trait cria
 * (uma única vez) o banco `stretor_testing` ao lado do principal e aponta a
 * conexão para ele antes de a suíte migrar — então `php artisan test` funciona
 * sem passo manual nenhum.
 *
 * A troca é feita por `config()`, e não por `.env.testing`: o compose injeta as
 * `DB_*` no ambiente do processo e o repositório de `env` do Laravel lê
 * `$_SERVER` primeiro, então um arquivo de ambiente seria ignorado. É o mesmo
 * motivo pelo qual o `TestCase` ajusta cache, sessão e fila por `config()`.
 */
trait UsaBancoDeTeste
{
    /** Banco dos testes — nunca o de desenvolvimento. */
    private static string $bancoDeTeste = 'stretor_testing';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::garantirBancoDeTeste();
    }

    /**
     * Aponta a conexão para o banco de teste antes de a suíte migrar.
     *
     * Roda depois de o app existir (para o `config()` valer) e antes de o
     * `setUpTraits()` migrar.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        config(['database.connections.pgsql.database' => self::$bancoDeTeste]);
    }

    /**
     * Cria o banco de teste ao lado do de desenvolvimento, se ainda não existir.
     *
     * Conecta ao banco principal só para o `CREATE DATABASE`; a conexão de
     * trabalho é trocada em seguida. O código `42P04` é o "banco já existe" do
     * Postgres — tratado como sucesso, já que o banco é reutilizado nas execuções
     * seguintes.
     */
    private static function garantirBancoDeTeste(): void
    {
        $host = $_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? 'postgres';
        $port = $_ENV['DB_PORT'] ?? $_SERVER['DB_PORT'] ?? '5432';
        $usuario = $_ENV['DB_USERNAME'] ?? $_SERVER['DB_USERNAME'] ?? 'stretor';
        $senha = $_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'] ?? 'secret';
        $principal = $_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? 'stretor';

        try {
            $pdo = new PDO("pgsql:host={$host};port={$port};dbname={$principal}", $usuario, $senha);
            $pdo->exec(sprintf('CREATE DATABASE %s OWNER %s', self::$bancoDeTeste, $usuario));
        } catch (PDOException $erro) {
            if ($erro->getCode() !== '42P04') {
                throw $erro;
            }
        }
    }
}
