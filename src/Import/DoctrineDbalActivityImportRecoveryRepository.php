<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Import;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Youmad\Endurance\Activity\Application\Port\ActivityImportRecoveryRepository;
use Youmad\Endurance\Activity\Application\Recovery\ActivityImportRecoveryAction;
use Youmad\Endurance\Activity\Application\Recovery\ActivityImportRecoveryClaim;
use Youmad\Endurance\Activity\Application\Recovery\ActivityImportRecoveryCleanupBatch;
use Youmad\Endurance\Activity\Application\Recovery\ActivityImportRecoveryResult;
use Youmad\Endurance\Activity\Exception\ActivityImportRecoveryClaimLost;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportGenerationId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportRecoveryClaimId;
use Youmad\Endurance\ActivityPostgresql\Connection\DoctrineDbalActivityTransaction;
use Youmad\Endurance\ActivityPostgresql\Exception\ActivityImportPersistenceException;

final readonly class DoctrineDbalActivityImportRecoveryRepository implements ActivityImportRecoveryRepository
{
    private const string EXHAUSTED_ERROR_CODE =
        'stale_processing_exhausted';

    private const string EXHAUSTED_ERROR_MESSAGE =
        'Activity import exceeded the maximum number of stale processing recovery attempts.';

    public function __construct(
        private DoctrineDbalActivityTransaction $transaction,
        private DoctrineDbalAbandonedActivityImportGenerationCleaner $cleaner =
            new DoctrineDbalAbandonedActivityImportGenerationCleaner(),
    ) {
    }

    public function claimNext(
        int $staleAfterSeconds,
        int $recoveryLeaseSeconds,
    ): ?ActivityImportRecoveryClaim {
        $connection = $this->connection();
        $this->assertTransaction($connection);

        $row = $connection->fetchAssociative(
            <<<'SQL'
            SELECT id::text,
                   activity_id::text,
                   status,
                   attempt_count,
                   recovery_claim_id::text
            FROM activity_imports
            WHERE (
                    status = 'processing'
                    AND processing_heartbeat_at <= clock_timestamp()
                        - make_interval(secs => :stale_after_seconds)
                )
               OR (
                    status = 'recovering'
                    AND recovery_claimed_at <= clock_timestamp()
                        - make_interval(secs => :recovery_lease_seconds)
                )
            ORDER BY CASE
                         WHEN status = 'processing'
                             THEN processing_heartbeat_at
                         ELSE recovery_claimed_at
                     END,
                     id
            FOR UPDATE SKIP LOCKED
            LIMIT 1
            SQL,
            [
                'stale_after_seconds' => $staleAfterSeconds,
                'recovery_lease_seconds' => $recoveryLeaseSeconds,
            ],
            [
                'stale_after_seconds' => ParameterType::INTEGER,
                'recovery_lease_seconds' => ParameterType::INTEGER,
            ],
        );

        if (false === $row) {
            return null;
        }

        /** @var array{
         *     id: string,
         *     activity_id: string,
         *     status: string,
         *     attempt_count: int|string,
         *     recovery_claim_id: ?string
         * } $row
         */
        $importId = ActivityImportId::fromString($row['id']);
        $activityId = ActivityId::fromString($row['activity_id']);
        $generationId = $this->fenceGeneration(
            connection: $connection,
            importId: $importId,
            activityId: $activityId,
            newlyRecovering: 'processing' === $row['status'],
        );
        $claimId = ActivityImportRecoveryClaimId::generate();

        $affected = $connection->executeStatement(
            <<<'SQL'
            UPDATE activity_imports
            SET status = 'recovering',
                processing_claim_id = NULL,
                recovery_claim_id = :recovery_claim_id,
                recovery_claimed_at = clock_timestamp(),
                updated_at = clock_timestamp()
            WHERE id = :id
              AND status = :expected_status
            SQL,
            [
                'id' => $importId->toString(),
                'expected_status' => $row['status'],
                'recovery_claim_id' => $claimId->toString(),
            ],
        );

        if (1 !== $affected) {
            throw ActivityImportPersistenceException::inconsistentState(sprintf('Activity import %s could not be claimed for recovery.', $importId->toString()));
        }

        return new ActivityImportRecoveryClaim(
            importId: $importId,
            activityId: $activityId,
            claimId: $claimId,
            attemptCount: (int) $row['attempt_count'],
            generationId: $generationId,
        );
    }

    public function cleanupBatch(
        ActivityImportRecoveryClaim $claim,
        int $batchSize,
    ): ActivityImportRecoveryCleanupBatch {
        $connection = $this->connection();
        $this->assertTransaction($connection);
        $this->lockRecoveryClaim($connection, $claim);

        if (null === $claim->generationId) {
            $this->refreshRecoveryLease($connection, $claim);

            return new ActivityImportRecoveryCleanupBatch(
                deletedRows: 0,
                complete: true,
            );
        }

        $this->assertFailedGeneration($connection, $claim);

        $cleanup = $this->cleaner->cleanupBatch(
            connection: $connection,
            generationId: $claim->generationId,
            batchSize: $batchSize,
        );
        $this->refreshRecoveryLease($connection, $claim);

        return $cleanup;
    }

    public function finalize(
        ActivityImportRecoveryClaim $claim,
        int $maxAttempts,
    ): ActivityImportRecoveryResult {
        $connection = $this->connection();
        $this->assertTransaction($connection);
        $this->lockRecoveryClaim($connection, $claim);
        $this->assertGenerationClean($connection, $claim);

        if ($claim->attemptCount >= $maxAttempts) {
            $this->markExhausted($connection, $claim);

            return new ActivityImportRecoveryResult(
                importId: $claim->importId,
                activityId: $claim->activityId,
                attemptCount: $claim->attemptCount,
                action: ActivityImportRecoveryAction::Failed,
                generationId: $claim->generationId,
            );
        }

        $affected = $connection->executeStatement(
            <<<'SQL'
            UPDATE activity_imports
            SET status = 'queued',
                processing_started_at = NULL,
                processing_claim_id = NULL,
                processing_heartbeat_at = NULL,
                recovery_claim_id = NULL,
                recovery_claimed_at = NULL,
                completed_at = NULL,
                failed_at = NULL,
                error_code = NULL,
                error_message = NULL,
                warnings = '[]'::jsonb,
                updated_at = clock_timestamp()
            WHERE id = :id
              AND status = 'recovering'
              AND recovery_claim_id = :recovery_claim_id
            SQL,
            [
                'id' => $claim->importId->toString(),
                'recovery_claim_id' => $claim->claimId->toString(),
            ],
        );

        if (1 !== $affected) {
            throw ActivityImportRecoveryClaimLost::forClaim($claim);
        }

        return new ActivityImportRecoveryResult(
            importId: $claim->importId,
            activityId: $claim->activityId,
            attemptCount: $claim->attemptCount,
            action: ActivityImportRecoveryAction::Requeued,
            generationId: $claim->generationId,
        );
    }

    private function fenceGeneration(
        Connection $connection,
        ActivityImportId $importId,
        ActivityId $activityId,
        bool $newlyRecovering,
    ): ?ActivityImportGenerationId {
        $activity = $connection->fetchAssociative(
            <<<'SQL'
            SELECT active_import_generation_id::text
            FROM activities
            WHERE id = :activity_id
            FOR UPDATE
            SQL,
            ['activity_id' => $activityId->toString()],
        );

        if (false === $activity) {
            return null;
        }

        $generation = $connection->fetchAssociative(
            <<<'SQL'
            SELECT id::text, status
            FROM activity_import_generations
            WHERE activity_id = :activity_id
              AND idempotency_key = :idempotency_key
            FOR UPDATE
            SQL,
            [
                'activity_id' => $activityId->toString(),
                'idempotency_key' => $importId->toString(),
            ],
        );

        if (false === $generation) {
            return null;
        }

        $generationId = ActivityImportGenerationId::fromString(
            (string) $generation['id'],
        );
        $status = (string) $generation['status'];

        if (ActivityImportGenerationStatus::Active->value === $status) {
            throw ActivityImportPersistenceException::inconsistentState(sprintf('Stale processing import %s references active generation %s.', $importId->toString(), $generationId->toString()));
        }

        if (ActivityImportGenerationStatus::Failed->value === $status) {
            return $generationId;
        }

        if (
            ActivityImportGenerationStatus::Staging->value !== $status
            || !$newlyRecovering
        ) {
            throw ActivityImportPersistenceException::inconsistentState(sprintf('Recovering import %s references generation %s in unexpected status %s.', $importId->toString(), $generationId->toString(), $status));
        }

        $connection->executeStatement(
            <<<'SQL'
            UPDATE activity_import_generations
            SET status = 'failed',
                activated_at = NULL,
                failed_at = clock_timestamp(),
                updated_at = clock_timestamp()
            WHERE id = :generation_id
              AND status = 'staging'
            SQL,
            ['generation_id' => $generationId->toString()],
        );

        return $generationId;
    }

    private function lockRecoveryClaim(
        Connection $connection,
        ActivityImportRecoveryClaim $claim,
    ): void {
        $owned = $connection->fetchOne(
            <<<'SQL'
            SELECT 1
            FROM activity_imports
            WHERE id = :id
              AND status = 'recovering'
              AND recovery_claim_id = :recovery_claim_id
            FOR UPDATE
            SQL,
            [
                'id' => $claim->importId->toString(),
                'recovery_claim_id' => $claim->claimId->toString(),
            ],
        );

        if (false === $owned) {
            throw ActivityImportRecoveryClaimLost::forClaim($claim);
        }
    }

    private function assertFailedGeneration(
        Connection $connection,
        ActivityImportRecoveryClaim $claim,
    ): void {
        $status = $connection->fetchOne(
            <<<'SQL'
            SELECT status
            FROM activity_import_generations
            WHERE id = :generation_id
              AND activity_id = :activity_id
            FOR UPDATE
            SQL,
            [
                'generation_id' => $claim->generationId?->toString(),
                'activity_id' => $claim->activityId->toString(),
            ],
        );

        if (ActivityImportGenerationStatus::Failed->value !== $status) {
            throw ActivityImportPersistenceException::inconsistentState(sprintf('Recovery generation %s is not failed.', $claim->generationId?->toString() ?? 'none'));
        }
    }

    private function refreshRecoveryLease(
        Connection $connection,
        ActivityImportRecoveryClaim $claim,
    ): void {
        $affected = $connection->executeStatement(
            <<<'SQL'
            UPDATE activity_imports
            SET recovery_claimed_at = clock_timestamp(),
                updated_at = clock_timestamp()
            WHERE id = :id
              AND status = 'recovering'
              AND recovery_claim_id = :recovery_claim_id
            SQL,
            [
                'id' => $claim->importId->toString(),
                'recovery_claim_id' => $claim->claimId->toString(),
            ],
        );

        if (1 !== $affected) {
            throw ActivityImportRecoveryClaimLost::forClaim($claim);
        }
    }

    private function assertGenerationClean(
        Connection $connection,
        ActivityImportRecoveryClaim $claim,
    ): void {
        if (null === $claim->generationId) {
            return;
        }

        if (!$this->cleaner->isClean($connection, $claim->generationId)) {
            throw ActivityImportPersistenceException::inconsistentState(sprintf('Recovery generation %s still contains staged rows.', $claim->generationId->toString()));
        }
    }

    private function markExhausted(
        Connection $connection,
        ActivityImportRecoveryClaim $claim,
    ): void {
        $affected = $connection->executeStatement(
            <<<'SQL'
            UPDATE activity_imports
            SET status = 'failed',
                processing_claim_id = NULL,
                processing_heartbeat_at = clock_timestamp(),
                recovery_claim_id = NULL,
                recovery_claimed_at = NULL,
                completed_at = NULL,
                failed_at = clock_timestamp(),
                error_code = :error_code,
                error_message = :error_message,
                warnings = '[]'::jsonb,
                updated_at = clock_timestamp()
            WHERE id = :id
              AND status = 'recovering'
              AND recovery_claim_id = :recovery_claim_id
            SQL,
            [
                'id' => $claim->importId->toString(),
                'recovery_claim_id' => $claim->claimId->toString(),
                'error_code' => self::EXHAUSTED_ERROR_CODE,
                'error_message' => self::EXHAUSTED_ERROR_MESSAGE,
            ],
        );

        if (1 !== $affected) {
            throw ActivityImportRecoveryClaimLost::forClaim($claim);
        }
    }

    private function assertTransaction(Connection $connection): void
    {
        if (!$connection->isTransactionActive()) {
            throw ActivityImportPersistenceException::inconsistentState('Activity import recovery operations require an outer transaction.');
        }
    }

    private function connection(): Connection
    {
        return $this->transaction->connection();
    }
}
