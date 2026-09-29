<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Import;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Youmad\Endurance\Activity\Application\Import\ActivityImportFileCleanupClaim;
use Youmad\Endurance\Activity\Application\Port\ActivityImportFileCleanupRepository;
use Youmad\Endurance\Activity\ValueObject\ActivityImportId;
use Youmad\Endurance\ActivityPostgresql\Connection\DoctrineDbalActivityTransaction;
use Youmad\Endurance\ActivityPostgresql\Exception\ActivityImportFileCleanupClaimLost;
use Youmad\Endurance\Foundation\ValueObject\Instant;
use Youmad\Endurance\Foundation\ValueObject\Uuid;

final readonly class DoctrineDbalActivityImportFileCleanupRepository implements ActivityImportFileCleanupRepository
{
    public function __construct(
        private DoctrineDbalActivityTransaction $transaction,
    ) {
    }

    public function claimEligibleFiles(
        Instant $completedBefore,
        Instant $failedBefore,
        Instant $staleClaimBefore,
        int $limit,
    ): array {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Activity import file cleanup limit must be positive.');
        }

        return $this->transaction->run(
            function () use (
                $completedBefore,
                $failedBefore,
                $staleClaimBefore,
                $limit,
            ): array {
                $claimId = Uuid::generate();
                $rows = $this->connection()->fetchFirstColumn(
                    <<<'SQL'
                    WITH candidates AS (
                        SELECT id
                        FROM activity_imports
                        WHERE source_file_deleted_at IS NULL
                          AND (
                              source_file_cleanup_claimed_at IS NULL
                              OR source_file_cleanup_claimed_at
                                  < :stale_claim_before
                          )
                          AND (
                              (
                                  status = 'completed'
                                  AND completed_at < :completed_before
                              )
                              OR (
                                  status = 'failed'
                                  AND failed_at < :failed_before
                              )
                          )
                        ORDER BY COALESCE(completed_at, failed_at), id
                        FOR UPDATE SKIP LOCKED
                        LIMIT :limit
                    )
                    UPDATE activity_imports AS imports
                    SET source_file_cleanup_claim_id = :claim_id,
                        source_file_cleanup_claimed_at = clock_timestamp(),
                        updated_at = clock_timestamp()
                    FROM candidates
                    WHERE imports.id = candidates.id
                    RETURNING imports.id::text
                    SQL,
                    [
                        'completed_before' => $this->timestamp(
                            $completedBefore,
                        ),
                        'failed_before' => $this->timestamp(
                            $failedBefore,
                        ),
                        'stale_claim_before' => $this->timestamp(
                            $staleClaimBefore,
                        ),
                        'limit' => $limit,
                        'claim_id' => $claimId->toString(),
                    ],
                    [
                        'limit' => ParameterType::INTEGER,
                    ],
                );

                return array_map(
                    static fn (mixed $id): ActivityImportFileCleanupClaim => new ActivityImportFileCleanupClaim(
                        importId: ActivityImportId::fromString(
                            (string) $id,
                        ),
                        claimId: $claimId,
                    ),
                    $rows,
                );
            },
        );
    }

    public function markFileDeleted(
        ActivityImportFileCleanupClaim $claim,
    ): void {
        $affected = $this->connection()->executeStatement(
            <<<'SQL'
            UPDATE activity_imports
            SET source_file_deleted_at = clock_timestamp(),
                source_file_cleanup_claim_id = NULL,
                source_file_cleanup_claimed_at = NULL,
                updated_at = clock_timestamp()
            WHERE id = :id
              AND source_file_cleanup_claim_id = :claim_id
              AND source_file_deleted_at IS NULL
            SQL,
            [
                'id' => $claim->importId->toString(),
                'claim_id' => $claim->claimId->toString(),
            ],
        );

        if (1 !== $affected) {
            throw ActivityImportFileCleanupClaimLost::forClaim($claim);
        }
    }

    public function releaseFile(
        ActivityImportFileCleanupClaim $claim,
    ): void {
        $this->connection()->executeStatement(
            <<<'SQL'
            UPDATE activity_imports
            SET source_file_cleanup_claim_id = NULL,
                source_file_cleanup_claimed_at = NULL,
                updated_at = clock_timestamp()
            WHERE id = :id
              AND source_file_cleanup_claim_id = :claim_id
              AND source_file_deleted_at IS NULL
            SQL,
            [
                'id' => $claim->importId->toString(),
                'claim_id' => $claim->claimId->toString(),
            ],
        );
    }

    public function importExists(ActivityImportId $importId): bool
    {
        return false !== $this->connection()->fetchOne(
            'SELECT 1 FROM activity_imports WHERE id = :id',
            ['id' => $importId->toString()],
        );
    }

    private function timestamp(Instant $instant): string
    {
        return $instant
            ->toDateTimeImmutable()
            ->format('Y-m-d H:i:s.uP');
    }

    private function connection(): Connection
    {
        return $this->transaction->connection();
    }
}
