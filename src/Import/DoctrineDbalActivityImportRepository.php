<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Import;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Youmad\Endurance\Activity\Application\Import\ActivityImport;
use Youmad\Endurance\Activity\Application\Import\ActivityImportProcessingClaim;
use Youmad\Endurance\Activity\Application\Port\ActivityImportRepository;
use Youmad\Endurance\Activity\Exception\ActivityImportNotFound;
use Youmad\Endurance\Activity\Exception\ActivityImportProcessingClaimLost;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportProcessingClaimId;
use Youmad\Endurance\ActivityPostgresql\Connection\DoctrineDbalActivityTransaction;

final readonly class DoctrineDbalActivityImportRepository implements ActivityImportRepository
{
    public function __construct(
        private DoctrineDbalActivityTransaction $transaction,
        private ActivityImportRowMapper $rows,
        private ActivityImportWarningJsonCodec $warnings =
            new ActivityImportWarningJsonCodec(),
    ) {
    }

    public function enqueue(
        ActivityImportId $importId,
        ActivityId $activityId,
    ): void {
        $this->connection()->executeStatement(
            <<<'SQL'
            INSERT INTO activity_imports (
                id,
                activity_id,
                status,
                attempt_count,
                created_at,
                updated_at
            ) VALUES (
                :id,
                :activity_id,
                'queued',
                0,
                clock_timestamp(),
                clock_timestamp()
            )
            SQL,
            [
                'id' => $importId->toString(),
                'activity_id' => $activityId->toString(),
            ],
        );
    }

    public function start(
        ActivityImportId $importId,
    ): ?ActivityImportProcessingClaim {
        return $this->transaction->run(
            function () use ($importId): ?ActivityImportProcessingClaim {
                $row = $this->connection()->fetchAssociative(
                    <<<'SQL'
                    SELECT status,
                           attempt_count,
                           source_file_cleanup_claim_id::text,
                           source_file_deleted_at::text
                    FROM activity_imports
                    WHERE id = :id
                    FOR UPDATE
                    SQL,
                    ['id' => $importId->toString()],
                );

                if (false === $row) {
                    throw ActivityImportNotFound::withId($importId);
                }

                /** @var array{
                 *     status: string,
                 *     attempt_count: int|string,
                 *     source_file_cleanup_claim_id: ?string,
                 *     source_file_deleted_at: ?string
                 * } $row
                 */
                if (
                    in_array($row['status'], ['processing', 'recovering'], true)
                    || 'completed' === $row['status']
                    || (
                        'failed' === $row['status']
                        && 0 < (int) $row['attempt_count']
                    )
                    || null !== $row['source_file_cleanup_claim_id']
                    || null !== $row['source_file_deleted_at']
                ) {
                    return null;
                }

                $claimId = ActivityImportProcessingClaimId::generate();
                $attemptCount = (int) $row['attempt_count'] + 1;

                $affected = $this->connection()->executeStatement(
                    <<<'SQL'
                    UPDATE activity_imports
                    SET status = 'processing',
                        attempt_count = :attempt_count,
                        processing_started_at = clock_timestamp(),
                        processing_claim_id = :processing_claim_id,
                        processing_heartbeat_at = clock_timestamp(),
                        completed_at = NULL,
                        failed_at = NULL,
                        error_code = NULL,
                        error_message = NULL,
                        warnings = '[]'::jsonb,
                        updated_at = clock_timestamp()
                    WHERE id = :id
                    SQL,
                    [
                        'id' => $importId->toString(),
                        'attempt_count' => $attemptCount,
                        'processing_claim_id' => $claimId->toString(),
                    ],
                    [
                        'id' => ParameterType::STRING,
                        'attempt_count' => ParameterType::INTEGER,
                        'processing_claim_id' => ParameterType::STRING,
                    ],
                );

                if (1 !== $affected) {
                    throw ActivityImportNotFound::withId($importId);
                }

                return new ActivityImportProcessingClaim(
                    importId: $importId,
                    claimId: $claimId,
                    attemptCount: $attemptCount,
                );
            },
        );
    }

    public function heartbeat(ActivityImportProcessingClaim $claim): void
    {
        $affected = $this->connection()->executeStatement(
            <<<'SQL'
            UPDATE activity_imports
            SET processing_heartbeat_at = clock_timestamp(),
                updated_at = clock_timestamp()
            WHERE id = :id
              AND status = 'processing'
              AND processing_claim_id = :processing_claim_id
            SQL,
            [
                'id' => $claim->importId->toString(),
                'processing_claim_id' => $claim->claimId->toString(),
            ],
        );

        if (1 !== $affected) {
            $this->throwClaimFailure($claim);
        }
    }

    public function release(ActivityImportProcessingClaim $claim): void
    {
        $affected = $this->connection()->executeStatement(
            <<<'SQL'
            UPDATE activity_imports
            SET status = 'queued',
                processing_started_at = NULL,
                processing_claim_id = NULL,
                processing_heartbeat_at = NULL,
                completed_at = NULL,
                failed_at = NULL,
                error_code = NULL,
                error_message = NULL,
                warnings = '[]'::jsonb,
                updated_at = clock_timestamp()
            WHERE id = :id
              AND status = 'processing'
              AND processing_claim_id = :processing_claim_id
            SQL,
            [
                'id' => $claim->importId->toString(),
                'processing_claim_id' => $claim->claimId->toString(),
            ],
        );

        if (1 !== $affected) {
            $this->throwClaimFailure($claim);
        }
    }

    public function complete(
        ActivityImportProcessingClaim $claim,
        array $warnings = [],
    ): void {
        $affected = $this->connection()->executeStatement(
            <<<'SQL'
            UPDATE activity_imports
            SET status = 'completed',
                processing_claim_id = NULL,
                processing_heartbeat_at = clock_timestamp(),
                completed_at = clock_timestamp(),
                failed_at = NULL,
                error_code = NULL,
                error_message = NULL,
                warnings = CAST(:warnings AS jsonb),
                updated_at = clock_timestamp()
            WHERE id = :id
              AND status = 'processing'
              AND processing_claim_id = :processing_claim_id
            SQL,
            [
                'id' => $claim->importId->toString(),
                'processing_claim_id' => $claim->claimId->toString(),
                'warnings' => $this->warnings->encode($warnings),
            ],
        );

        if (1 !== $affected) {
            $this->throwClaimFailure($claim);
        }
    }

    public function fail(
        ActivityImportProcessingClaim $claim,
        string $errorCode,
        string $errorMessage,
    ): void {
        $affected = $this->connection()->executeStatement(
            <<<'SQL'
            UPDATE activity_imports
            SET status = 'failed',
                processing_claim_id = NULL,
                processing_heartbeat_at = clock_timestamp(),
                completed_at = NULL,
                failed_at = clock_timestamp(),
                error_code = :error_code,
                error_message = :error_message,
                warnings = '[]'::jsonb,
                updated_at = clock_timestamp()
            WHERE id = :id
              AND status = 'processing'
              AND processing_claim_id = :processing_claim_id
            SQL,
            [
                'id' => $claim->importId->toString(),
                'processing_claim_id' => $claim->claimId->toString(),
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ],
        );

        if (1 !== $affected) {
            $this->throwClaimFailure($claim);
        }
    }

    public function failQueued(
        ActivityImportId $importId,
        string $errorCode,
        string $errorMessage,
    ): bool {
        $affected = $this->connection()->executeStatement(
            <<<'SQL'
            UPDATE activity_imports
            SET status = 'failed',
                processing_claim_id = NULL,
                processing_heartbeat_at = NULL,
                completed_at = NULL,
                failed_at = clock_timestamp(),
                error_code = :error_code,
                error_message = :error_message,
                warnings = '[]'::jsonb,
                updated_at = clock_timestamp()
            WHERE id = :id
              AND status = 'queued'
              AND processing_claim_id IS NULL
            SQL,
            [
                'id' => $importId->toString(),
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ],
        );

        if (1 === $affected) {
            return true;
        }

        $this->ensureExists($importId);

        return false;
    }

    public function find(ActivityImportId $importId): ?ActivityImport
    {
        $row = $this->connection()->fetchAssociative(
            <<<'SQL'
            SELECT id::text,
                   activity_id::text,
                   status,
                   attempt_count,
                   created_at::text,
                   processing_started_at::text,
                   completed_at::text,
                   failed_at::text,
                   error_code,
                   error_message,
                   warnings::text
            FROM activity_imports
            WHERE id = :id
            SQL,
            ['id' => $importId->toString()],
        );

        if (false === $row) {
            return null;
        }

        /* @var array{
         *     id: string,
         *     activity_id: string,
         *     status: string,
         *     attempt_count: int|string,
         *     created_at: string,
         *     processing_started_at: ?string,
         *     completed_at: ?string,
         *     failed_at: ?string,
         *     error_code: ?string,
         *     error_message: ?string,
         *     warnings: string
         * } $row
         */
        return $this->rows->import($row);
    }

    private function throwClaimFailure(
        ActivityImportProcessingClaim $claim,
    ): never {
        $this->ensureExists($claim->importId);

        throw ActivityImportProcessingClaimLost::forClaim($claim);
    }

    private function ensureExists(ActivityImportId $importId): void
    {
        $exists = $this->connection()->fetchOne(
            'SELECT 1 FROM activity_imports WHERE id = :id',
            ['id' => $importId->toString()],
        );

        if (false === $exists) {
            throw ActivityImportNotFound::withId($importId);
        }
    }

    private function connection(): Connection
    {
        return $this->transaction->connection();
    }
}
