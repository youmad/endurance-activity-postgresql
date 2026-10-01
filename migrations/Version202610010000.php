<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version202610010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist the initial timer start separately from the activity interval.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Tracker activity storage supports PostgreSQL only.',
        );
        $this->addSql('ALTER TABLE activities ADD COLUMN timer_started_at timestamptz NULL');
        $this->addSql(
            <<<'SQL'
            ALTER TABLE activities
            ADD CONSTRAINT chk_activities_timer_start
            CHECK (
                timer_started_at IS NULL
                OR (
                    timer_started_at >= started_at
                    AND timer_started_at <= latest_timestamp
                )
            )
            SQL,
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'Tracker activity storage supports PostgreSQL only.',
        );
        $this->addSql('ALTER TABLE activities DROP CONSTRAINT chk_activities_timer_start');
        $this->addSql('ALTER TABLE activities DROP COLUMN timer_started_at');
    }
}
