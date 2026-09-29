<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Import;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Youmad\Endurance\Activity\Application\Port\FailedActivityImportCleaner;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportIdempotencyKey;
use Youmad\Endurance\ActivityPostgresql\Connection\DoctrineDbalActivityTransaction;

final readonly class DoctrineDbalFailedActivityImportCleaner implements FailedActivityImportCleaner
{
    public function __construct(
        private DoctrineDbalActivityTransaction $transaction,
    ) {
    }

    public function discard(
        ActivityId $activityId,
        ActivityImportIdempotencyKey $idempotencyKey,
    ): bool {
        return $this->transaction->run(
            function () use (
                $activityId,
                $idempotencyKey,
            ): bool {
                $connection = $this->transaction->connection();
                $activity = $this->lockActivity(
                    connection: $connection,
                    activityId: $activityId,
                );

                if (null === $activity) {
                    return false;
                }

                if (null !== $activity['active_import_generation_id']) {
                    return false;
                }

                $generations = $this->lockGenerations(
                    connection: $connection,
                    activityId: $activityId,
                );
                $matchingFailedGenerationExists = false;

                foreach ($generations as $generation) {
                    if (
                        !hash_equals(
                            $idempotencyKey->toString(),
                            $generation['idempotency_key'],
                        )
                    ) {
                        return false;
                    }

                    if (
                        ActivityImportGenerationStatus::Failed->value
                        !== $generation['status']
                    ) {
                        return false;
                    }

                    $matchingFailedGenerationExists = true;
                }

                if (!$matchingFailedGenerationExists) {
                    return false;
                }

                return 1 === $connection->executeStatement(
                    <<<'SQL'
                        DELETE FROM activities
                        WHERE id = :activity_id
                          AND active_import_generation_id IS NULL
                        SQL,
                    [
                        'activity_id' => $activityId->toString(),
                    ],
                    [
                        'activity_id' => ParameterType::STRING,
                    ],
                );
            },
        );
    }

    /**
     * @return array{active_import_generation_id: string|null}|null
     */
    private function lockActivity(
        Connection $connection,
        ActivityId $activityId,
    ): ?array {
        $row = $connection->fetchAssociative(
            <<<'SQL'
                SELECT active_import_generation_id::text
                FROM activities
                WHERE id = :activity_id
                FOR UPDATE
                SQL,
            [
                'activity_id' => $activityId->toString(),
            ],
            [
                'activity_id' => ParameterType::STRING,
            ],
        );

        if (false === $row) {
            return null;
        }

        return [
            'active_import_generation_id' => $row['active_import_generation_id'],
        ];
    }

    /**
     * @return list<array{idempotency_key: string, status: string}>
     */
    private function lockGenerations(
        Connection $connection,
        ActivityId $activityId,
    ): array {
        /** @var list<array{idempotency_key: string, status: string}> $rows */
        $rows = $connection->fetchAllAssociative(
            <<<'SQL'
                SELECT idempotency_key, status
                FROM activity_import_generations
                WHERE activity_id = :activity_id
                ORDER BY id
                FOR UPDATE
                SQL,
            [
                'activity_id' => $activityId->toString(),
            ],
            [
                'activity_id' => ParameterType::STRING,
            ],
        );

        return $rows;
    }
}
