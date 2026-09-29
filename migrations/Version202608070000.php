<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version202608070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add processing ownership and heartbeat metadata to activity imports.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform()
                instanceof PostgreSQLPlatform,
            'Tracker activity storage supports PostgreSQL only.',
        );

        foreach (self::upStatements() as $statement) {
            $this->addSql($statement);
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform()
                instanceof PostgreSQLPlatform,
            'Tracker activity storage supports PostgreSQL only.',
        );

        foreach (self::downStatements() as $statement) {
            $this->addSql($statement);
        }
    }

    /** @return non-empty-list<string> */
    private static function upStatements(): array
    {
        return [
            <<<'SQL_1'
            ALTER TABLE activity_imports
                ADD COLUMN processing_claim_id uuid NULL,
                ADD COLUMN processing_heartbeat_at timestamptz NULL
            SQL_1,
            <<<'SQL_2'
            ALTER TABLE activity_imports
                ADD CONSTRAINT uq_activity_imports_processing_claim
                    UNIQUE (processing_claim_id)
            SQL_2,
            <<<'SQL_3'
            ALTER TABLE activity_imports
                DROP CONSTRAINT chk_activity_imports_timestamps,
                DROP CONSTRAINT chk_activity_imports_state
            SQL_3,
            <<<'SQL_4'
            UPDATE activity_import_generations AS generation
            SET status = 'failed',
                failed_at = clock_timestamp(),
                activated_at = NULL,
                updated_at = clock_timestamp()
            FROM activity_imports AS import_row
            WHERE import_row.status = 'processing'
              AND generation.activity_id = import_row.activity_id
              AND generation.idempotency_key = import_row.id::text
              AND generation.status = 'staging'
            SQL_4,
            <<<'SQL_5'
            UPDATE activity_imports
            SET status = 'queued',
                processing_started_at = NULL,
                completed_at = NULL,
                failed_at = NULL,
                error_code = NULL,
                error_message = NULL,
                warnings = '[]'::jsonb,
                updated_at = clock_timestamp()
            WHERE status = 'processing'
            SQL_5,
            <<<'SQL_6'
            UPDATE activity_imports
            SET processing_heartbeat_at = CASE
                    WHEN status = 'completed'
                        THEN GREATEST(completed_at, processing_started_at)
                    WHEN status = 'failed'
                         AND processing_started_at IS NOT NULL
                        THEN GREATEST(failed_at, processing_started_at)
                    ELSE NULL
                END
            WHERE status IN ('completed', 'failed')
            SQL_6,
            <<<'SQL_7'
            ALTER TABLE activity_imports
                ADD CONSTRAINT chk_activity_imports_timestamps
                    CHECK (
                        updated_at >= created_at
                        AND (
                            processing_started_at IS NULL
                            OR processing_started_at >= created_at
                        )
                        AND (
                            processing_heartbeat_at IS NULL
                            OR processing_heartbeat_at >= created_at
                        )
                        AND (
                            processing_started_at IS NULL
                            OR processing_heartbeat_at IS NULL
                            OR processing_heartbeat_at >= processing_started_at
                        )
                        AND (
                            completed_at IS NULL
                            OR completed_at >= created_at
                        )
                        AND (
                            failed_at IS NULL
                            OR failed_at >= created_at
                        )
                        AND (
                            source_file_cleanup_claimed_at IS NULL
                            OR source_file_cleanup_claimed_at >= created_at
                        )
                        AND (
                            source_file_deleted_at IS NULL
                            OR source_file_deleted_at >= created_at
                        )
                    )
            SQL_7,
            <<<'SQL_8'
            ALTER TABLE activity_imports
                ADD CONSTRAINT chk_activity_imports_state
                    CHECK (
                        (
                            status = 'queued'
                            AND attempt_count >= 0
                            AND processing_started_at IS NULL
                            AND processing_claim_id IS NULL
                            AND processing_heartbeat_at IS NULL
                            AND completed_at IS NULL
                            AND failed_at IS NULL
                            AND error_code IS NULL
                            AND error_message IS NULL
                            AND warnings = '[]'::jsonb
                        )
                        OR (
                            status = 'processing'
                            AND attempt_count > 0
                            AND processing_started_at IS NOT NULL
                            AND processing_claim_id IS NOT NULL
                            AND processing_heartbeat_at IS NOT NULL
                            AND completed_at IS NULL
                            AND failed_at IS NULL
                            AND error_code IS NULL
                            AND error_message IS NULL
                            AND warnings = '[]'::jsonb
                        )
                        OR (
                            status = 'completed'
                            AND attempt_count > 0
                            AND processing_started_at IS NOT NULL
                            AND processing_claim_id IS NULL
                            AND processing_heartbeat_at IS NOT NULL
                            AND completed_at IS NOT NULL
                            AND failed_at IS NULL
                            AND error_code IS NULL
                            AND error_message IS NULL
                        )
                        OR (
                            status = 'failed'
                            AND processing_claim_id IS NULL
                            AND (
                                (
                                    processing_started_at IS NULL
                                    AND processing_heartbeat_at IS NULL
                                )
                                OR (
                                    processing_started_at IS NOT NULL
                                    AND processing_heartbeat_at IS NOT NULL
                                )
                            )
                            AND completed_at IS NULL
                            AND failed_at IS NOT NULL
                            AND error_code IS NOT NULL
                            AND error_message IS NOT NULL
                            AND warnings = '[]'::jsonb
                        )
                    )
            SQL_8,
            <<<'SQL_9'
            CREATE INDEX ix_activity_imports_status_heartbeat
                ON activity_imports (
                    status,
                    processing_heartbeat_at,
                    id
                )
                WHERE status = 'processing'
            SQL_9,
        ];
    }

    /** @return non-empty-list<string> */
    private static function downStatements(): array
    {
        return [
            <<<'SQL_1'
            DROP INDEX ix_activity_imports_status_heartbeat
            SQL_1,
            <<<'SQL_2'
            ALTER TABLE activity_imports
                DROP CONSTRAINT chk_activity_imports_state,
                DROP CONSTRAINT chk_activity_imports_timestamps,
                DROP CONSTRAINT uq_activity_imports_processing_claim
            SQL_2,
            <<<'SQL_3'
            UPDATE activity_imports
            SET attempt_count = 0
            WHERE status = 'queued'
            SQL_3,
            <<<'SQL_4'
            ALTER TABLE activity_imports
                DROP COLUMN processing_heartbeat_at,
                DROP COLUMN processing_claim_id
            SQL_4,
            <<<'SQL_5'
            ALTER TABLE activity_imports
                ADD CONSTRAINT chk_activity_imports_timestamps
                    CHECK (
                        updated_at >= created_at
                        AND (
                            processing_started_at IS NULL
                            OR processing_started_at >= created_at
                        )
                        AND (
                            completed_at IS NULL
                            OR completed_at >= created_at
                        )
                        AND (
                            failed_at IS NULL
                            OR failed_at >= created_at
                        )
                        AND (
                            source_file_cleanup_claimed_at IS NULL
                            OR source_file_cleanup_claimed_at >= created_at
                        )
                        AND (
                            source_file_deleted_at IS NULL
                            OR source_file_deleted_at >= created_at
                        )
                    )
            SQL_5,
            <<<'SQL_6'
            ALTER TABLE activity_imports
                ADD CONSTRAINT chk_activity_imports_state
                    CHECK (
                        (
                            status = 'queued'
                            AND attempt_count = 0
                            AND processing_started_at IS NULL
                            AND completed_at IS NULL
                            AND failed_at IS NULL
                            AND error_code IS NULL
                            AND error_message IS NULL
                        )
                        OR (
                            status = 'processing'
                            AND attempt_count > 0
                            AND processing_started_at IS NOT NULL
                            AND completed_at IS NULL
                            AND failed_at IS NULL
                            AND error_code IS NULL
                            AND error_message IS NULL
                        )
                        OR (
                            status = 'completed'
                            AND attempt_count > 0
                            AND processing_started_at IS NOT NULL
                            AND completed_at IS NOT NULL
                            AND failed_at IS NULL
                            AND error_code IS NULL
                            AND error_message IS NULL
                        )
                        OR (
                            status = 'failed'
                            AND completed_at IS NULL
                            AND failed_at IS NOT NULL
                            AND error_code IS NOT NULL
                            AND error_message IS NOT NULL
                        )
                    )
            SQL_6,
        ];
    }
}
