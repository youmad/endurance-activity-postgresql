<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version202608190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist the resolution-normalized activity session timeline.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform()
                instanceof PostgreSQLPlatform,
            'Tracker activity storage supports PostgreSQL only.',
        );

        $this->addSql(
            'ALTER TABLE activities ADD COLUMN last_session_timeline_finished_at timestamptz NULL',
        );
        $this->addSql(
            <<<'SQL'
            UPDATE activities
            SET last_session_timeline_finished_at = last_session_finished_at
            WHERE session_count > 0
            SQL,
        );
        $this->addSql(
            <<<'SQL'
            ALTER TABLE activities
            ADD CONSTRAINT chk_activities_last_session_timeline
            CHECK (
                (
                    session_count = 0
                    AND last_session_timeline_finished_at IS NULL
                )
                OR (
                    session_count > 0
                    AND last_session_timeline_finished_at IS NOT NULL
                    AND last_session_finished_at IS NOT NULL
                    AND last_session_timeline_finished_at >= last_session_finished_at
                    AND (
                        summary_reported_at IS NULL
                        OR finished_at >= last_session_timeline_finished_at
                    )
                )
            )
            SQL,
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform()
                instanceof PostgreSQLPlatform,
            'Tracker activity storage supports PostgreSQL only.',
        );

        $this->addSql(
            'ALTER TABLE activities DROP CONSTRAINT chk_activities_last_session_timeline',
        );
        $this->addSql(
            'ALTER TABLE activities DROP COLUMN last_session_timeline_finished_at',
        );
    }
}
