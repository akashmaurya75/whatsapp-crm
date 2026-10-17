<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use Throwable;

/** Thin PDO wrapper. Prepared statements only; emulation disabled. */
final class Database
{
    private static ?Database $instance = null;

    public function __construct(public readonly PDO $pdo, public readonly string $driver)
    {
    }

    public static function connection(): self
    {
        if (self::$instance === null) {
            self::$instance = self::fromEnv();
        }
        return self::$instance;
    }

    public static function setConnection(?self $db): void
    {
        self::$instance = $db;
    }

    public static function fromEnv(): self
    {
        $driver = (string) env('DB_CONNECTION', 'mysql');
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        if ($driver === 'sqlite') {
            $path = (string) env('DB_DATABASE', base_path('storage/database.sqlite'));
            $pdo = new PDO('sqlite:' . $path, null, null, $options);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            return new self($pdo, 'sqlite');
        }
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            env('DB_HOST', '127.0.0.1'),
            env('DB_PORT', '3306'),
            env('DB_DATABASE', 'whatsapp_crm')
        );
        $pdo = new PDO($dsn, (string) env('DB_USERNAME', ''), (string) env('DB_PASSWORD', ''), $options);
        $pdo->exec("SET time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
        return new self($pdo, 'mysql');
    }

    /** @param array<int|string,mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /** @return array<string,mixed>|null */
    public function first(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    public function scalar(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /**
     * Insert a row. Column names come from code only (never user input).
     * @param array<string,mixed> $data
     */
    public function insert(string $table, array $data): int
    {
        self::assertIdentifier($table);
        $cols = array_keys($data);
        array_walk($cols, [self::class, 'assertIdentifier']);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', array_map(static fn ($c) => ':' . $c, $cols))
        );
        $this->run($sql, $data);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $where  equality conditions (AND)
     */
    public function update(string $table, array $data, array $where): int
    {
        self::assertIdentifier($table);
        $set = [];
        $params = [];
        foreach ($data as $col => $value) {
            self::assertIdentifier($col);
            $set[] = "$col = :set_$col";
            $params["set_$col"] = $value;
        }
        $conds = [];
        foreach ($where as $col => $value) {
            self::assertIdentifier($col);
            $conds[] = "$col = :w_$col";
            $params["w_$col"] = $value;
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $set), implode(' AND ', $conds));
        return $this->run($sql, $params)->rowCount();
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $fn();
        }
        $this->pdo->beginTransaction();
        try {
            $result = $fn();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function isUniqueViolation(Throwable $e): bool
    {
        $code = $e instanceof \PDOException ? (string) ($e->errorInfo[1] ?? $e->getCode()) : '';
        return $code === '1062' || $code === '19' || $code === '2067' || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }

    public static function assertIdentifier(string $name): void
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException("Invalid SQL identifier: $name");
        }
    }
}
