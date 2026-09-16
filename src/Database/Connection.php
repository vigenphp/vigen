<?php

declare(strict_types=1);

namespace Vigen\Database;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * A thin, lazy wrapper around PDO.
 *
 * The connection is only opened when a query actually runs, so a request
 * that never touches the database never pays for one - and a misconfigured
 * database does not break pages that do not use it.
 */
class Connection
{
    private static ?self $current = null;

    private ?PDO $pdo = null;

    /**
     * @param array<string, mixed> $config one entry from config/database.php's "connections"
     */
    public function __construct(
        private readonly array $config,
        private readonly string $basePath,
    ) {
    }

    /**
     * The connection configured for this application.
     */
    public static function current(): self
    {
        return self::$current ??= self::fromConfig();
    }

    /**
     * Replace the current connection - how a test points the framework at an
     * in-memory database.
     */
    public static function swap(?self $connection): void
    {
        self::$current = $connection;
    }

    /**
     * @param array<string, mixed>|null $config the whole config/database.php array
     */
    public static function fromConfig(?array $config = null, ?string $basePath = null): self
    {
        $config ??= (array) config('database', []);
        $default = (string) ($config['default'] ?? env('DB_CONNECTION', 'sqlite'));
        $connections = (array) ($config['connections'] ?? []);
        $settings = (array) ($connections[$default] ?? []);

        // A project without config/database.php still gets a working database
        // rather than an exception.
        $settings['driver'] ??= $default;

        return new self(self::applyEnvironment($settings), $basePath ?? base_path());
    }

    /**
     * Let .env override the stored config, so `DB_CONNECTION=mysql` works even
     * if config/database.php was never published.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private static function applyEnvironment(array $settings): array
    {
        $map = [
            'host' => 'DB_HOST',
            'port' => 'DB_PORT',
            'database' => 'DB_DATABASE',
            'username' => 'DB_USERNAME',
            'password' => 'DB_PASSWORD',
        ];

        foreach ($map as $key => $variable) {
            $value = env($variable);

            if ($value !== null && $value !== '') {
                $settings[$key] = $value;
            }
        }

        return $settings;
    }

    public function driver(): string
    {
        return strtolower((string) ($this->config['driver'] ?? 'sqlite'));
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= $this->connect();
    }

    /**
     * @param list<mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($bindings);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll();

        return $rows;
    }

    /**
     * Run a statement and return the number of affected rows.
     *
     * @param list<mixed> $bindings
     */
    public function statement(string $sql, array $bindings = []): int
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($bindings);

        return $statement->rowCount();
    }

    public function lastInsertId(): string|false
    {
        return $this->pdo()->lastInsertId();
    }

    /**
     * Run $callback inside a transaction, rolling back if it throws.
     *
     * @param callable(self): mixed $callback
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $result = $callback($this);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Quote a table or column name for this driver.
     *
     * Names must be plain identifiers - they come from migration files and
     * validation rules, which are partly model-authored, so anything that is
     * not a bare word is refused rather than interpolated.
     */
    public function quoteIdentifier(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new RuntimeException(sprintf(
                'Unsafe SQL identifier [%s]: table and column names must be plain words.',
                $name
            ));
        }

        $quote = $this->driver() === 'mysql' ? '`' : '"';

        return $quote . $name . $quote;
    }

    private function connect(): PDO
    {
        try {
            $pdo = match ($this->driver()) {
                'sqlite' => $this->connectSqlite(),
                'mysql' => $this->connectServer('mysql', 'charset=utf8mb4'),
                'pgsql' => $this->connectServer('pgsql'),
                default => throw new RuntimeException(sprintf(
                    'Unsupported database driver [%s]. Supported: sqlite, mysql, pgsql.',
                    $this->driver()
                )),
            };
        } catch (PDOException $e) {
            throw new RuntimeException($this->connectionFailure($e), 0, $e);
        }

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $pdo;
    }

    private function connectSqlite(): PDO
    {
        $database = (string) ($this->config['database'] ?? 'database/database.sqlite');

        if ($database !== ':memory:' && ! self::isAbsolute($database)) {
            $database = rtrim($this->basePath, '/\\') . '/' . ltrim($database, '/\\');
        }

        if ($database !== ':memory:') {
            $directory = dirname($database);

            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
        }

        $pdo = new PDO('sqlite:' . $database);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    private function connectServer(string $scheme, string $extra = ''): PDO
    {
        $database = (string) ($this->config['database'] ?? '');

        if ($database === '') {
            throw new RuntimeException(
                "No database name configured. Set DB_DATABASE in your .env or config/database.php."
            );
        }

        $dsn = sprintf(
            '%s:host=%s;port=%s;dbname=%s%s',
            $scheme,
            (string) ($this->config['host'] ?? '127.0.0.1'),
            (string) ($this->config['port'] ?? ($scheme === 'mysql' ? '3306' : '5432')),
            $database,
            $extra === '' ? '' : ';' . $extra
        );

        return new PDO(
            $dsn,
            (string) ($this->config['username'] ?? ''),
            (string) ($this->config['password'] ?? '')
        );
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1
            || str_starts_with($path, '\\\\');
    }

    /**
     * Turn a PDO failure into something that names the actual fix.
     *
     * "could not find driver" is not a configuration problem: it means this
     * PHP build has no PDO driver for the selected database. Telling the user
     * to "check config/database.php and .env" sends them to edit a file that
     * cannot solve it, so that case is reported separately - naming the
     * extension to enable and listing the drivers that *are* available.
     */
    private function connectionFailure(PDOException $e): string
    {
        $driver = $this->driver();
        $message = $e->getMessage();

        if (! str_contains(strtolower($message), 'could not find driver')) {
            return sprintf(
                'Vigen could not connect to the [%s] database: %s. Check config/database.php and your .env.',
                $driver,
                $message
            );
        }

        $extension = match ($driver) {
            'mysql' => 'pdo_mysql',
            'pgsql' => 'pdo_pgsql',
            default => 'pdo_sqlite',
        };

        $available = PDO::getAvailableDrivers();

        return sprintf(
            'The [%s] PDO driver is not available in this PHP build, so no connection can be made. '
            . 'Enable extension=%s in php.ini and restart PHP. Available drivers here: %s.',
            $driver,
            $extension,
            $available === [] ? 'none' : implode(', ', $available)
        );
    }
}
