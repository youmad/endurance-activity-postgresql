<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Tests\Support;

use Doctrine\DBAL\Connection;

final class PostgreSqlTestDatabase
{
    private const MIGRATION_TABLE = 'doctrine_migration_versions';

    public static function truncate(Connection $connection): void
    {
        $tables = array_map(
            static fn (mixed $table): string => (string) $table,
            $connection->fetchFirstColumn(
                <<<'SQL'
                SELECT format('%I.%I', schemaname, tablename)
                FROM pg_tables
                WHERE schemaname = 'public'
                  AND tablename <> :migration_table
                ORDER BY tablename
                SQL,
                [
                    'migration_table' => self::MIGRATION_TABLE,
                ],
            ),
        );

        if ([] === $tables) {
            return;
        }

        $connection->executeStatement(sprintf(
            'TRUNCATE TABLE %s RESTART IDENTITY CASCADE',
            implode(', ', $tables),
        ));
    }
}
