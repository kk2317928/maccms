<?php

namespace app\common\util;

use InvalidArgumentException;
use RuntimeException;
use think\Db;

class SchemaMigrationService
{
    private $directory;
    private $prefix;

    public function __construct(string $directory, string $prefix)
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
            throw new InvalidArgumentException('Invalid database table prefix.');
        }

        $this->directory = rtrim($directory, '/\\');
        $this->prefix = $prefix;
    }

    public function pending(): array
    {
        $migrations = $this->discoverMigrations();
        $applied = $this->appliedMigrations();
        $pending = [];

        foreach ($migrations as $migration) {
            $version = $migration['version'];
            if (isset($applied[$version])) {
                if (!hash_equals((string) $applied[$version], $migration['checksum'])) {
                    throw new RuntimeException('Applied migration checksum changed: ' . $version);
                }
                continue;
            }
            $pending[] = $migration;
        }

        return $pending;
    }

    public function migrate(): array
    {
        $all = $this->discoverMigrations();
        $appliedRows = $this->appliedMigrations();
        $pending = [];
        $skipped = [];

        foreach ($all as $migration) {
            $version = $migration['version'];
            if (isset($appliedRows[$version])) {
                if (!hash_equals((string) $appliedRows[$version], $migration['checksum'])) {
                    throw new RuntimeException('Applied migration checksum changed: ' . $version);
                }
                $skipped[] = $version;
            } else {
                $pending[] = $migration;
            }
        }

        $applied = [];
        foreach ($pending as $migration) {
            $originalSql = file_get_contents($migration['path']);
            if ($originalSql === false) {
                throw new RuntimeException('Unable to read migration: ' . $migration['filename']);
            }
            $sql = str_replace('__PREFIX__', $this->prefix, $originalSql);
            $statements = $this->splitStatements($sql);

            // MySQL implicitly commits CREATE/ALTER/DROP statements. Wrapping DDL in
            // a PDO transaction therefore leaves no active transaction to commit or
            // roll back. Migration SQL must be idempotent; record the ledger row only
            // after every statement has completed successfully.
            foreach ($statements as $statement) {
                Db::execute($statement);
            }
            Db::execute(
                'INSERT INTO `' . $this->prefix . 'schema_migration` '
                . '(`version`,`name`,`checksum`,`executed_at`) VALUES (?,?,?,?)',
                [$migration['version'], $migration['name'], $migration['checksum'], time()]
            );

            $applied[] = $migration['version'];
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    protected function appliedMigrations(): array
    {
        $this->ensureLedger();
        $rows = Db::query(
            'SELECT `version`,`checksum` FROM `' . $this->prefix . 'schema_migration` ORDER BY `version` ASC'
        );
        $applied = [];
        foreach ($rows as $row) {
            $applied[(string) $row['version']] = (string) $row['checksum'];
        }

        return $applied;
    }

    private function ensureLedger(): void
    {
        Db::execute(
            'CREATE TABLE IF NOT EXISTS `' . $this->prefix . 'schema_migration` ('
            . '`version` varchar(32) NOT NULL,'
            . '`name` varchar(191) NOT NULL,'
            . '`checksum` char(64) NOT NULL,'
            . '`executed_at` int(10) unsigned NOT NULL,'
            . 'PRIMARY KEY (`version`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private function discoverMigrations(): array
    {
        if (!is_dir($this->directory)) {
            throw new RuntimeException('Migration directory does not exist: ' . $this->directory);
        }

        $paths = glob($this->directory . '/*.sql');
        if ($paths === false) {
            throw new RuntimeException('Unable to scan migration directory: ' . $this->directory);
        }

        $migrations = [];
        foreach ($paths as $path) {
            $filename = basename($path);
            if (!preg_match('/^(\d{14})_([a-z0-9_]+)\.sql$/', $filename, $matches)) {
                continue;
            }
            $sql = file_get_contents($path);
            if ($sql === false) {
                throw new RuntimeException('Unable to read migration: ' . $filename);
            }
            $migrations[] = [
                'version' => $matches[1],
                'name' => $matches[2],
                'filename' => $filename,
                'path' => $path,
                'checksum' => hash('sha256', $sql),
            ];
        }

        usort($migrations, function ($left, $right) {
            return strcmp($left['version'], $right['version']);
        });

        for ($index = 1, $count = count($migrations); $index < $count; $index++) {
            if ($migrations[$index - 1]['version'] === $migrations[$index]['version']) {
                throw new RuntimeException('Duplicate migration version: ' . $migrations[$index]['version']);
            }
        }

        return $migrations;
    }

    private function splitStatements(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $quote = '';
        $lineComment = false;
        $blockComment = false;
        $length = strlen($sql);

        for ($index = 0; $index < $length; $index++) {
            $char = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : '';

            if ($lineComment) {
                if ($char === "\n") {
                    $lineComment = false;
                    $buffer .= $char;
                }
                continue;
            }
            if ($blockComment) {
                if ($char === '*' && $next === '/') {
                    $blockComment = false;
                    $index++;
                }
                continue;
            }

            if ($quote === '') {
                if (($char === '-' && $next === '-' && ($index + 2 >= $length || ctype_space($sql[$index + 2]))) || $char === '#') {
                    $lineComment = true;
                    if ($char === '-') {
                        $index++;
                    }
                    continue;
                }
                if ($char === '/' && $next === '*') {
                    $blockComment = true;
                    $index++;
                    continue;
                }
                if ($char === "'" || $char === '"' || $char === '`') {
                    $quote = $char;
                    $buffer .= $char;
                    continue;
                }
                if ($char === ';') {
                    $statement = trim($buffer);
                    if ($statement !== '') {
                        $statements[] = $statement;
                    }
                    $buffer = '';
                    continue;
                }
                $buffer .= $char;
                continue;
            }

            $buffer .= $char;
            if ($char === '\\' && $index + 1 < $length) {
                $buffer .= $sql[++$index];
                continue;
            }
            if ($char === $quote) {
                if ($next === $quote) {
                    $buffer .= $next;
                    $index++;
                } else {
                    $quote = '';
                }
            }
        }

        if ($quote !== '' || $blockComment) {
            throw new RuntimeException('Unterminated quote or comment in migration SQL.');
        }
        $statement = trim($buffer);
        if ($statement !== '') {
            $statements[] = $statement;
        }

        return $statements;
    }
}
