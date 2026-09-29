<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Import;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Youmad\Endurance\Activity\Application\Port\ActivityImportDiagnosticsRepository;
use Youmad\Endurance\Activity\Application\Read\StaleActivityImportCandidate;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportGenerationId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportId;
use Youmad\Endurance\Foundation\ValueObject\Instant;

final readonly class DoctrineDbalActivityImportDiagnosticsRepository implements ActivityImportDiagnosticsRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function findStaleProcessing(
        int $staleAfterSeconds,
        int $limit,
    ): array {
        if ($staleAfterSeconds < 1) {
            throw new \InvalidArgumentException('Stale activity import threshold must be positive.');
        }

        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Stale activity import limit must be between 1 and 1000.');
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
            WITH stale_imports AS (
                SELECT id,
                       activity_id,
                       attempt_count,
                       processing_started_at,
                       processing_heartbeat_at,
                       updated_at
                FROM activity_imports
                WHERE status = 'processing'
                  AND processing_heartbeat_at <= clock_timestamp()
                      - make_interval(secs => :stale_after_seconds)
                ORDER BY processing_heartbeat_at, id
                LIMIT :candidate_limit
            )
            SELECT import_row.id::text AS import_id,
                   import_row.activity_id::text AS activity_id,
                   import_row.attempt_count,
                   import_row.processing_started_at::text,
                   import_row.processing_heartbeat_at::text
                       AS processing_heartbeat_at,
                   import_row.updated_at::text AS import_updated_at,
                   EXTRACT(
                       EPOCH FROM clock_timestamp()
                           - import_row.processing_heartbeat_at
                   )::bigint AS idle_age_seconds,
                   generation.id::text AS generation_id,
                   generation.status AS generation_status,
                   generation.attempt_count AS generation_attempt_count,
                   generation.staging_started_at::text
                       AS generation_staging_started_at,
                   generation.updated_at::text AS generation_updated_at,
                   CASE
                       WHEN generation.id IS NULL THEN NULL
                       ELSE EXTRACT(
                           EPOCH FROM clock_timestamp() - generation.updated_at
                       )::bigint
                   END AS generation_idle_age_seconds,
                   CASE
                       WHEN generation.id IS NULL THEN 0
                       ELSE
                           (
                               SELECT COUNT(*)
                               FROM activity_import_observations
                               WHERE import_generation_id = generation.id
                           )
                           + (
                               SELECT COUNT(*)
                               FROM activity_import_device_status_observations
                               WHERE import_generation_id = generation.id
                           )
                           + (
                               SELECT COUNT(*)
                               FROM activity_import_laps
                               WHERE import_generation_id = generation.id
                           )
                           + (
                               SELECT COUNT(*)
                               FROM activity_import_details
                               WHERE import_generation_id = generation.id
                           )
                           + (
                               SELECT COUNT(*)
                               FROM activity_import_sessions
                               WHERE import_generation_id = generation.id
                           )
                           + (
                               SELECT COUNT(*)
                               FROM activity_import_devices
                               WHERE import_generation_id = generation.id
                           )
                   END AS generation_row_count
            FROM stale_imports AS import_row
            LEFT JOIN activity_import_generations AS generation
                ON generation.activity_id = import_row.activity_id
               AND generation.idempotency_key = import_row.id::text
            ORDER BY import_row.processing_heartbeat_at, import_row.id
            SQL,
            [
                'stale_after_seconds' => $staleAfterSeconds,
                'candidate_limit' => $limit,
            ],
            [
                'stale_after_seconds' => ParameterType::INTEGER,
                'candidate_limit' => ParameterType::INTEGER,
            ],
        );

        return array_map(
            fn (array $row): StaleActivityImportCandidate => $this->candidate(
                $row,
            ),
            $rows,
        );
    }

    /** @param array<string, mixed> $row */
    private function candidate(array $row): StaleActivityImportCandidate
    {
        $generationId = null === $row['generation_id']
            ? null
            : ActivityImportGenerationId::fromString(
                (string) $row['generation_id'],
            );

        return new StaleActivityImportCandidate(
            importId: ActivityImportId::fromString((string) $row['import_id']),
            activityId: ActivityId::fromString((string) $row['activity_id']),
            attemptCount: (int) $row['attempt_count'],
            processingStartedAt: $this->instant(
                (string) $row['processing_started_at'],
            ),
            processingHeartbeatAt: $this->instant(
                (string) $row['processing_heartbeat_at'],
            ),
            updatedAt: $this->instant((string) $row['import_updated_at']),
            idleAgeSeconds: (int) $row['idle_age_seconds'],
            generationId: $generationId,
            generationStatus: null === $row['generation_status']
                ? null
                : (string) $row['generation_status'],
            generationAttemptCount: null === $row['generation_attempt_count']
                ? null
                : (int) $row['generation_attempt_count'],
            generationStagingStartedAt: null === $row['generation_staging_started_at']
                    ? null
                    : $this->instant(
                        (string) $row['generation_staging_started_at'],
                    ),
            generationUpdatedAt: null === $row['generation_updated_at']
                ? null
                : $this->instant((string) $row['generation_updated_at']),
            generationIdleAgeSeconds: null === $row['generation_idle_age_seconds']
                    ? null
                    : (int) $row['generation_idle_age_seconds'],
            generationRowCount: (int) $row['generation_row_count'],
        );
    }

    private function instant(string $value): Instant
    {
        return Instant::fromDateTimeImmutable(
            new \DateTimeImmutable($value),
        );
    }
}
