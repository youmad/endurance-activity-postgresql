<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version202608180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store the local-time offset reported by FIT activity summaries.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform()
                instanceof PostgreSQLPlatform,
            'Endurance Activity storage supports PostgreSQL only.',
        );

        $this->addSql(
            'ALTER TABLE activities ADD COLUMN local_time_offset_seconds bigint NULL',
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform()
                instanceof PostgreSQLPlatform,
            'Endurance Activity storage supports PostgreSQL only.',
        );

        $this->addSql(
            'ALTER TABLE activities DROP COLUMN local_time_offset_seconds',
        );
    }
}
