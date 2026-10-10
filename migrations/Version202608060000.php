<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version202608060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the initial activity storage schema.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform()
                instanceof PostgreSQLPlatform,
            'Endurance Activity storage supports PostgreSQL only.',
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
            'Endurance Activity storage supports PostgreSQL only.',
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
            CREATE TABLE activities (
                id uuid PRIMARY KEY,
                started_at timestamptz NOT NULL,
                finished_at timestamptz NULL,
                last_observation_at timestamptz NULL,
                last_lap_finished_at timestamptz NULL,
                last_session_finished_at timestamptz NULL,
                last_detail_finished_at timestamptz NULL,
                last_sequential_detail_finished_at jsonb NOT NULL DEFAULT '{}'::jsonb,
                session_count integer NOT NULL DEFAULT 0,
                recorded_session_timer_duration_microseconds bigint NOT NULL DEFAULT 0,
                summary_reported_at timestamptz NULL,
                activity_type varchar(64) NULL,
                paused_at timestamptz NULL,
                accumulated_paused_duration_microseconds bigint NOT NULL DEFAULT 0,
                latest_timestamp timestamptz NOT NULL,
                version bigint NOT NULL DEFAULT 1,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT chk_activities_finished_interval
                    CHECK (finished_at IS NULL OR finished_at >= started_at),
                CONSTRAINT chk_activities_last_observation
                    CHECK (
                        last_observation_at IS NULL
                        OR (
                            last_observation_at >= started_at
                            AND last_observation_at <= latest_timestamp
                        )
                    ),
                CONSTRAINT chk_activities_last_lap
                    CHECK (
                        last_lap_finished_at IS NULL
                        OR (
                            last_lap_finished_at >= started_at
                            AND last_lap_finished_at <= latest_timestamp
                        )
                    ),
                CONSTRAINT chk_activities_last_session
                    CHECK (
                        last_session_finished_at IS NULL
                        OR (
                            last_session_finished_at >= started_at
                            AND last_session_finished_at <= latest_timestamp
                        )
                    ),
                CONSTRAINT chk_activities_last_detail
                    CHECK (
                        last_detail_finished_at IS NULL
                        OR (
                            last_detail_finished_at >= started_at
                            AND last_detail_finished_at <= latest_timestamp
                        )
                    ),
                CONSTRAINT chk_activities_sequential_details
                    CHECK (
                        jsonb_typeof(last_sequential_detail_finished_at) = 'object'
                    ),
                CONSTRAINT chk_activities_session_count
                    CHECK (session_count >= 0),
                CONSTRAINT chk_activities_session_state
                    CHECK (
                        (
                            session_count = 0
                            AND last_session_finished_at IS NULL
                            AND recorded_session_timer_duration_microseconds = 0
                            AND summary_reported_at IS NULL
                        )
                        OR (
                            session_count > 0
                            AND last_session_finished_at IS NOT NULL
                        )
                    ),
                CONSTRAINT chk_activities_recorded_session_duration
                    CHECK (recorded_session_timer_duration_microseconds >= 0),
                CONSTRAINT chk_activities_type
                    CHECK (
                        activity_type IS NULL
                        OR activity_type ~ '^[a-z][a-z0-9]*(_[a-z0-9]+)*$'
                    ),
                CONSTRAINT chk_activities_pause_state
                    CHECK (
                        paused_at IS NULL
                        OR (
                            finished_at IS NULL
                            AND paused_at >= started_at
                            AND paused_at <= latest_timestamp
                        )
                    ),
                CONSTRAINT chk_activities_paused_duration
                    CHECK (accumulated_paused_duration_microseconds >= 0),
                CONSTRAINT chk_activities_latest_timestamp
                    CHECK (
                        latest_timestamp >= started_at
                        AND (
                            finished_at IS NULL
                            OR finished_at = latest_timestamp
                        )
                    ),
                CONSTRAINT chk_activities_summary
                    CHECK (
                        summary_reported_at IS NULL
                        OR (
                            finished_at IS NOT NULL
                            AND summary_reported_at >= started_at
                        )
                    ),
                CONSTRAINT chk_activities_version
                    CHECK (version > 0)
            )
            SQL_1,
            <<<'SQL_2'
            CREATE TABLE activity_import_generations (
                id uuid PRIMARY KEY,
                activity_id uuid NOT NULL,
                idempotency_key varchar(255) NOT NULL,
                status varchar(16) NOT NULL,
                attempt_count integer NOT NULL DEFAULT 1,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                staging_started_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                activated_at timestamptz NULL,
                failed_at timestamptz NULL,
                updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT fk_activity_import_generations_activity
                    FOREIGN KEY (activity_id)
                    REFERENCES activities (id)
                    ON DELETE CASCADE,
                CONSTRAINT uq_activity_import_generations_key
                    UNIQUE (activity_id, idempotency_key),
                CONSTRAINT uq_activity_import_generations_activity_id
                    UNIQUE (activity_id, id),
                CONSTRAINT chk_activity_import_generations_key
                    CHECK (octet_length(idempotency_key) BETWEEN 1 AND 255),
                CONSTRAINT chk_activity_import_generations_attempt_count
                    CHECK (attempt_count > 0),
                CONSTRAINT chk_activity_import_generations_status
                    CHECK (status IN ('staging', 'active', 'failed')),
                CONSTRAINT chk_activity_import_generations_timestamps
                    CHECK (
                        (
                            status = 'staging'
                            AND activated_at IS NULL
                            AND failed_at IS NULL
                        )
                        OR (
                            status = 'active'
                            AND activated_at IS NOT NULL
                            AND failed_at IS NULL
                        )
                        OR (
                            status = 'failed'
                            AND activated_at IS NULL
                            AND failed_at IS NOT NULL
                        )
                    )
            )
            SQL_2,
            <<<'SQL_3'
            CREATE UNIQUE INDEX uq_activity_import_generations_staging
                ON activity_import_generations (activity_id)
                WHERE status = 'staging'
            SQL_3,
            <<<'SQL_4'
            CREATE UNIQUE INDEX uq_activity_import_generations_active
                ON activity_import_generations (activity_id)
                WHERE status = 'active'
            SQL_4,
            <<<'SQL_5'
            CREATE INDEX ix_activity_import_generations_status_updated
                ON activity_import_generations (status, updated_at)
            SQL_5,
            <<<'SQL_6'
            ALTER TABLE activities
                ADD COLUMN active_import_generation_id uuid NULL
            SQL_6,
            <<<'SQL_7'
            ALTER TABLE activities
                ADD CONSTRAINT fk_activities_active_import_generation
                FOREIGN KEY (id, active_import_generation_id)
                REFERENCES activity_import_generations (activity_id, id)
                DEFERRABLE INITIALLY IMMEDIATE
            SQL_7,
            <<<'SQL_8'
            CREATE TABLE activity_import_observations (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                import_generation_id uuid NOT NULL,
                activity_id uuid NOT NULL,
                observed_at timestamptz NOT NULL,
                payload_version smallint NOT NULL DEFAULT 1,
                payload jsonb NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT fk_activity_import_observations_generation
                    FOREIGN KEY (activity_id, import_generation_id)
                    REFERENCES activity_import_generations (activity_id, id)
                    ON DELETE CASCADE,
                CONSTRAINT chk_activity_import_observations_payload_version
                    CHECK (payload_version > 0),
                CONSTRAINT chk_activity_import_observations_payload
                    CHECK (jsonb_typeof(payload) = 'object')
            )
            SQL_8,
            <<<'SQL_9'
            CREATE INDEX ix_activity_import_observations_generation
                ON activity_import_observations (import_generation_id, id)
            SQL_9,
            <<<'SQL_10'
            CREATE INDEX ix_activity_import_observations_activity_time
                ON activity_import_observations (activity_id, observed_at, id)
            SQL_10,
            <<<'SQL_11'
            CREATE TABLE activity_import_device_status_observations (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                import_generation_id uuid NOT NULL,
                activity_id uuid NOT NULL,
                device_id uuid NOT NULL,
                observed_at timestamptz NULL,
                payload_version smallint NOT NULL DEFAULT 1,
                payload jsonb NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT fk_activity_import_device_status_generation
                    FOREIGN KEY (activity_id, import_generation_id)
                    REFERENCES activity_import_generations (activity_id, id)
                    ON DELETE CASCADE,
                CONSTRAINT chk_activity_import_device_status_payload_version
                    CHECK (payload_version > 0),
                CONSTRAINT chk_activity_import_device_status_payload
                    CHECK (jsonb_typeof(payload) = 'object')
            )
            SQL_11,
            <<<'SQL_12'
            CREATE INDEX ix_activity_import_device_status_generation
                ON activity_import_device_status_observations (
                    import_generation_id,
                    id
                )
            SQL_12,
            <<<'SQL_13'
            CREATE INDEX ix_activity_import_device_status_activity_device_time
                ON activity_import_device_status_observations (
                    activity_id,
                    device_id,
                    observed_at,
                    id
                )
            SQL_13,
            <<<'SQL_14'
            CREATE TABLE activity_import_laps (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                import_generation_id uuid NOT NULL,
                activity_id uuid NOT NULL,
                started_at timestamptz NOT NULL,
                finished_at timestamptz NOT NULL,
                timer_duration_microseconds bigint NOT NULL,
                payload_version smallint NOT NULL DEFAULT 1,
                payload jsonb NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT fk_activity_import_laps_generation
                    FOREIGN KEY (activity_id, import_generation_id)
                    REFERENCES activity_import_generations (activity_id, id)
                    ON DELETE CASCADE,
                CONSTRAINT chk_activity_import_laps_interval
                    CHECK (finished_at >= started_at),
                CONSTRAINT chk_activity_import_laps_timer_duration
                    CHECK (timer_duration_microseconds >= 0),
                CONSTRAINT chk_activity_import_laps_payload_version
                    CHECK (payload_version > 0),
                CONSTRAINT chk_activity_import_laps_payload
                    CHECK (jsonb_typeof(payload) = 'object')
            )
            SQL_14,
            <<<'SQL_15'
            CREATE INDEX ix_activity_import_laps_generation
                ON activity_import_laps (import_generation_id, id)
            SQL_15,
            <<<'SQL_16'
            CREATE INDEX ix_activity_import_laps_activity_time
                ON activity_import_laps (activity_id, started_at, id)
            SQL_16,
            <<<'SQL_17'
            CREATE TABLE activity_import_details (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                import_generation_id uuid NOT NULL,
                activity_id uuid NOT NULL,
                detail_type varchar(64) NOT NULL,
                sequence_name varchar(64) NULL,
                started_at timestamptz NOT NULL,
                finished_at timestamptz NOT NULL,
                timer_duration_microseconds bigint NOT NULL,
                payload_version smallint NOT NULL DEFAULT 1,
                payload jsonb NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT fk_activity_import_details_generation
                    FOREIGN KEY (activity_id, import_generation_id)
                    REFERENCES activity_import_generations (activity_id, id)
                    ON DELETE CASCADE,
                CONSTRAINT chk_activity_import_details_type
                    CHECK (detail_type ~ '^[a-z][a-z0-9]*(_[a-z0-9]+)*$'),
                CONSTRAINT chk_activity_import_details_sequence
                    CHECK (
                        sequence_name IS NULL
                        OR sequence_name ~ '^[a-z][a-z0-9]*(_[a-z0-9]+)*$'
                    ),
                CONSTRAINT chk_activity_import_details_interval
                    CHECK (finished_at >= started_at),
                CONSTRAINT chk_activity_import_details_timer_duration
                    CHECK (timer_duration_microseconds >= 0),
                CONSTRAINT chk_activity_import_details_payload_version
                    CHECK (payload_version > 0),
                CONSTRAINT chk_activity_import_details_payload
                    CHECK (jsonb_typeof(payload) = 'object')
            )
            SQL_17,
            <<<'SQL_18'
            CREATE INDEX ix_activity_import_details_generation
                ON activity_import_details (import_generation_id, id)
            SQL_18,
            <<<'SQL_19'
            CREATE INDEX ix_activity_import_details_activity_time
                ON activity_import_details (activity_id, started_at, id)
            SQL_19,
            <<<'SQL_20'
            CREATE TABLE activity_import_sessions (
                id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                import_generation_id uuid NOT NULL,
                activity_id uuid NOT NULL,
                started_at timestamptz NOT NULL,
                finished_at timestamptz NOT NULL,
                timer_duration_microseconds bigint NOT NULL,
                payload_version smallint NOT NULL DEFAULT 1,
                payload jsonb NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT fk_activity_import_sessions_generation
                    FOREIGN KEY (activity_id, import_generation_id)
                    REFERENCES activity_import_generations (activity_id, id)
                    ON DELETE CASCADE,
                CONSTRAINT chk_activity_import_sessions_interval
                    CHECK (finished_at >= started_at),
                CONSTRAINT chk_activity_import_sessions_timer_duration
                    CHECK (timer_duration_microseconds >= 0),
                CONSTRAINT chk_activity_import_sessions_payload_version
                    CHECK (payload_version > 0),
                CONSTRAINT chk_activity_import_sessions_payload
                    CHECK (jsonb_typeof(payload) = 'object')
            )
            SQL_20,
            <<<'SQL_21'
            CREATE INDEX ix_activity_import_sessions_generation
                ON activity_import_sessions (import_generation_id, id)
            SQL_21,
            <<<'SQL_22'
            CREATE INDEX ix_activity_import_sessions_activity_time
                ON activity_import_sessions (activity_id, started_at, id)
            SQL_22,
            <<<'SQL_23'
            CREATE TABLE activity_import_devices (
                import_generation_id uuid NOT NULL,
                activity_id uuid NOT NULL,
                device_id uuid NOT NULL,
                payload_version smallint NOT NULL DEFAULT 1,
                payload jsonb NOT NULL,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                PRIMARY KEY (import_generation_id, device_id),
                CONSTRAINT fk_activity_import_devices_generation
                    FOREIGN KEY (activity_id, import_generation_id)
                    REFERENCES activity_import_generations (activity_id, id)
                    ON DELETE CASCADE,
                CONSTRAINT chk_activity_import_devices_payload_version
                    CHECK (payload_version > 0),
                CONSTRAINT chk_activity_import_devices_payload
                    CHECK (jsonb_typeof(payload) = 'object')
            )
            SQL_23,
            <<<'SQL_24'
            CREATE INDEX ix_activity_import_devices_activity
                ON activity_import_devices (activity_id, device_id)
            SQL_24,
            <<<'SQL_25'
            CREATE VIEW active_activity_import_observations AS
            SELECT imported.*
            FROM activity_import_observations AS imported
            INNER JOIN activities AS activity
                ON activity.id = imported.activity_id
                AND activity.active_import_generation_id = imported.import_generation_id
            INNER JOIN activity_import_generations AS generation
                ON generation.id = imported.import_generation_id
                AND generation.activity_id = imported.activity_id
                AND generation.status = 'active'
            SQL_25,
            <<<'SQL_26'
            CREATE VIEW active_activity_import_device_status_observations AS
            SELECT imported.*
            FROM activity_import_device_status_observations AS imported
            INNER JOIN activities AS activity
                ON activity.id = imported.activity_id
                AND activity.active_import_generation_id = imported.import_generation_id
            INNER JOIN activity_import_generations AS generation
                ON generation.id = imported.import_generation_id
                AND generation.activity_id = imported.activity_id
                AND generation.status = 'active'
            SQL_26,
            <<<'SQL_27'
            CREATE VIEW active_activity_import_laps AS
            SELECT imported.*
            FROM activity_import_laps AS imported
            INNER JOIN activities AS activity
                ON activity.id = imported.activity_id
                AND activity.active_import_generation_id = imported.import_generation_id
            INNER JOIN activity_import_generations AS generation
                ON generation.id = imported.import_generation_id
                AND generation.activity_id = imported.activity_id
                AND generation.status = 'active'
            SQL_27,
            <<<'SQL_28'
            CREATE VIEW active_activity_import_details AS
            SELECT imported.*
            FROM activity_import_details AS imported
            INNER JOIN activities AS activity
                ON activity.id = imported.activity_id
                AND activity.active_import_generation_id = imported.import_generation_id
            INNER JOIN activity_import_generations AS generation
                ON generation.id = imported.import_generation_id
                AND generation.activity_id = imported.activity_id
                AND generation.status = 'active'
            SQL_28,
            <<<'SQL_29'
            CREATE VIEW active_activity_import_sessions AS
            SELECT imported.*
            FROM activity_import_sessions AS imported
            INNER JOIN activities AS activity
                ON activity.id = imported.activity_id
                AND activity.active_import_generation_id = imported.import_generation_id
            INNER JOIN activity_import_generations AS generation
                ON generation.id = imported.import_generation_id
                AND generation.activity_id = imported.activity_id
                AND generation.status = 'active'
            SQL_29,
            <<<'SQL_30'
            CREATE VIEW active_activity_import_devices AS
            SELECT imported.*
            FROM activity_import_devices AS imported
            INNER JOIN activities AS activity
                ON activity.id = imported.activity_id
                AND activity.active_import_generation_id = imported.import_generation_id
            INNER JOIN activity_import_generations AS generation
                ON generation.id = imported.import_generation_id
                AND generation.activity_id = imported.activity_id
                AND generation.status = 'active'
            SQL_30,
            <<<'SQL_31'
            CREATE TABLE activity_imports (
                id uuid PRIMARY KEY,
                activity_id uuid NOT NULL,
                status varchar(16) NOT NULL,
                attempt_count integer NOT NULL DEFAULT 0,
                created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                processing_started_at timestamptz NULL,
                completed_at timestamptz NULL,
                failed_at timestamptz NULL,
                error_code varchar(64) NULL,
                error_message varchar(512) NULL,
                warnings jsonb NOT NULL DEFAULT '[]'::jsonb,
                source_file_cleanup_claim_id uuid NULL,
                source_file_cleanup_claimed_at timestamptz NULL,
                source_file_deleted_at timestamptz NULL,
                updated_at timestamptz NOT NULL DEFAULT clock_timestamp(),
                CONSTRAINT uq_activity_imports_activity
                    UNIQUE (activity_id),
                CONSTRAINT chk_activity_imports_status
                    CHECK (
                        status IN (
                            'queued',
                            'processing',
                            'completed',
                            'failed'
                        )
                    ),
                CONSTRAINT chk_activity_imports_attempt_count
                    CHECK (attempt_count >= 0),
                CONSTRAINT chk_activity_imports_error_code
                    CHECK (
                        error_code IS NULL
                        OR error_code ~ '^[a-z][a-z0-9_]*$'
                    ),
                CONSTRAINT chk_activity_imports_error_message
                    CHECK (
                        error_message IS NULL
                        OR octet_length(error_message) BETWEEN 1 AND 512
                    ),
                CONSTRAINT chk_activity_imports_warnings
                    CHECK (
                        jsonb_typeof(warnings) = 'array'
                        AND jsonb_array_length(warnings) <= 64
                        AND (
                            status = 'completed'
                            OR warnings = '[]'::jsonb
                        )
                    ),
                CONSTRAINT chk_activity_imports_timestamps
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
                    ),
                CONSTRAINT chk_activity_imports_state
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
                    ),
                CONSTRAINT chk_activity_imports_source_file_cleanup
                    CHECK (
                        (
                            source_file_cleanup_claim_id IS NULL
                            AND source_file_cleanup_claimed_at IS NULL
                        )
                        OR (
                            source_file_cleanup_claim_id IS NOT NULL
                            AND source_file_cleanup_claimed_at IS NOT NULL
                            AND source_file_deleted_at IS NULL
                            AND status IN ('completed', 'failed')
                        )
                    ),
                CONSTRAINT chk_activity_imports_source_file_deleted
                    CHECK (
                        source_file_deleted_at IS NULL
                        OR (
                            status IN ('completed', 'failed')
                            AND source_file_cleanup_claim_id IS NULL
                            AND source_file_cleanup_claimed_at IS NULL
                        )
                    )
            )
            SQL_31,
            <<<'SQL_32'
            CREATE INDEX ix_activity_imports_status_updated
                ON activity_imports (status, updated_at, id)
            SQL_32,
            <<<'SQL_33'
            CREATE INDEX ix_activity_imports_source_file_cleanup
                ON activity_imports (
                    status,
                    COALESCE(completed_at, failed_at),
                    id
                )
                WHERE source_file_deleted_at IS NULL
                  AND status IN ('completed', 'failed')
            SQL_33,
        ];
    }

    /** @return non-empty-list<string> */
    private static function downStatements(): array
    {
        return [
            <<<'SQL_1'
            DROP TABLE activity_imports
            SQL_1,
            <<<'SQL_2'
            DROP VIEW IF EXISTS active_activity_import_devices
            SQL_2,
            <<<'SQL_3'
            DROP VIEW IF EXISTS active_activity_import_sessions
            SQL_3,
            <<<'SQL_4'
            DROP VIEW IF EXISTS active_activity_import_details
            SQL_4,
            <<<'SQL_5'
            DROP VIEW IF EXISTS active_activity_import_laps
            SQL_5,
            <<<'SQL_6'
            DROP VIEW IF EXISTS active_activity_import_device_status_observations
            SQL_6,
            <<<'SQL_7'
            DROP VIEW IF EXISTS active_activity_import_observations
            SQL_7,
            <<<'SQL_8'
            DROP TABLE IF EXISTS activity_import_devices
            SQL_8,
            <<<'SQL_9'
            DROP TABLE IF EXISTS activity_import_sessions
            SQL_9,
            <<<'SQL_10'
            DROP TABLE IF EXISTS activity_import_details
            SQL_10,
            <<<'SQL_11'
            DROP TABLE IF EXISTS activity_import_laps
            SQL_11,
            <<<'SQL_12'
            DROP TABLE IF EXISTS activity_import_device_status_observations
            SQL_12,
            <<<'SQL_13'
            DROP TABLE IF EXISTS activity_import_observations
            SQL_13,
            <<<'SQL_14'
            ALTER TABLE activities
                DROP CONSTRAINT IF EXISTS fk_activities_active_import_generation
            SQL_14,
            <<<'SQL_15'
            ALTER TABLE activities
                DROP COLUMN IF EXISTS active_import_generation_id
            SQL_15,
            <<<'SQL_16'
            DROP TABLE IF EXISTS activity_import_generations
            SQL_16,
            <<<'SQL_17'
            DROP TABLE IF EXISTS activities
            SQL_17,
        ];
    }
}
