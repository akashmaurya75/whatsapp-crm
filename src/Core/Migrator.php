<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Runs ordered migration files from database/migrations.
 * Each file returns list<string> of DDL using portable placeholders:
 *   {pk}      auto-increment primary key column definition
 *   {engine}  table options (InnoDB/utf8mb4 on MySQL, nothing on SQLite)
 */
final class Migrator
{
    public function __construct(private Database $db, private string $dir)
    {
    }

    public function translate(string $sql): string
    {
        return self::translateFor($this->db->driver, $sql);
    }

    public static function translateFor(string $driver, string $sql): string
    {
        $mysql = $driver === 'mysql';
        $sql = strtr($sql, [
            '{pk}' => $mysql ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT',
            '{engine}' => $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '',
        ]);
        return $mysql ? self::hoistForeignKeys($sql) : $sql;
    }

    /**
     * MySQL 8 parses but IGNORES column-level "REFERENCES" clauses. Rewrite them into
     * table-level FOREIGN KEY constraints so cascades/set-null are actually enforced.
     */
    public static function hoistForeignKeys(string $sql): string
    {
        if (!preg_match('/^\s*CREATE TABLE/i', $sql) || !str_contains($sql, 'REFERENCES')) {
            return $sql;
        }
        $constraints = [];
        $sql = (string) preg_replace_callback(
            '/^(\s*)([a-z_]+)(\s+[^\n]*?)\s+REFERENCES\s+([a-z_]+)\(([a-z_]+)\)((?:\s+ON DELETE (?:CASCADE|SET NULL|RESTRICT))?)(,?)\s*$/mi',
            static function (array $m) use (&$constraints): string {
                $constraints[] = "FOREIGN KEY ({$m[2]}) REFERENCES {$m[4]}({$m[5]}){$m[6]}";
                return $m[1] . $m[2] . $m[3] . $m[7];
            },
            $sql
        );
        if ($constraints === []) {
            return $sql;
        }
        // Insert the constraints before the closing ")" of the column list.
        $pos = strrpos($sql, ')');
        $head = rtrim(substr($sql, 0, (int) $pos));
        return $head . ",\n        " . implode(",\n        ", $constraints) . "\n    " . substr($sql, (int) $pos);
    }

    /** @return list<string> names of migrations applied in this run */
    public function migrate(): array
    {
        $this->db->pdo->exec($this->translate(
            'CREATE TABLE IF NOT EXISTS migrations (id {pk}, name VARCHAR(191) NOT NULL UNIQUE, ran_at DATETIME NOT NULL){engine}'
        ));
        $done = array_column($this->db->all('SELECT name FROM migrations'), 'name');
        $files = glob($this->dir . '/*.php') ?: [];
        sort($files);
        $applied = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if (in_array($name, $done, true)) {
                continue;
            }
            /** @var list<string> $statements */
            $statements = require $file;
            // MySQL DDL auto-commits, so statements run individually; migration is recorded last.
            foreach ($statements as $sql) {
                $this->db->pdo->exec($this->translate($sql));
            }
            $this->db->insert('migrations', ['name' => $name, 'ran_at' => now()]);
            $applied[] = $name;
        }
        return $applied;
    }
}
