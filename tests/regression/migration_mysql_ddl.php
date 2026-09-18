<?php

declare(strict_types=1);

namespace think {
    class Db
    {
        public static $executed = [];

        public static function startTrans()
        {
            throw new \RuntimeException('MySQL DDL must not be wrapped in a PDO transaction.');
        }

        public static function commit()
        {
            throw new \RuntimeException('MySQL DDL causes an implicit commit.');
        }

        public static function rollback()
        {
            throw new \RuntimeException('MySQL DDL leaves no active transaction to roll back.');
        }

        public static function execute($sql, array $bind = [])
        {
            self::$executed[] = [$sql, $bind];
            return 1;
        }
    }
}

namespace {
    require dirname(__DIR__, 2) . '/application/common/util/SchemaMigrationService.php';

    class MysqlDdlMigrationService extends \app\common\util\SchemaMigrationService
    {
        protected function appliedMigrations(): array
        {
            return [];
        }
    }

    $directory = sys_get_temp_dir() . '/maccms-mysql-ddl-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        fwrite(STDERR, "FAIL: unable to create migration fixture directory.\n");
        exit(1);
    }

    $path = $directory . '/20260918000100_mysql_ddl.sql';
    file_put_contents($path, "CREATE TABLE IF NOT EXISTS `__PREFIX__fixture` (`id` int NOT NULL);\n");

    try {
        $result = (new MysqlDdlMigrationService($directory, 'mac_'))->migrate();
        if ($result['applied'] !== ['20260918000100'] || count(\think\Db::$executed) !== 2) {
            fwrite(STDERR, "FAIL: migration must execute DDL and then record its ledger row.\n");
            exit(1);
        }
        if (strpos(\think\Db::$executed[0][0], '`mac_fixture`') === false
            || strpos(\think\Db::$executed[1][0], '`mac_schema_migration`') === false) {
            fwrite(STDERR, "FAIL: migration execution order or table prefix is incorrect.\n");
            exit(1);
        }

        fwrite(STDOUT, "OK: MySQL DDL migration avoids invalid transaction boundaries.\n");
    } finally {
        @unlink($path);
        @rmdir($directory);
    }
}
