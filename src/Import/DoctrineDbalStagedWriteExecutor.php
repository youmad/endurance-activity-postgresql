<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Import;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportGenerationId;
use Youmad\Endurance\ActivityPostgresql\Connection\DoctrineDbalActivityTransaction;
use Youmad\Endurance\ActivityPostgresql\Exception\ActivityImportPersistenceException;

final readonly class DoctrineDbalStagedWriteExecutor
{
    public function __construct(
        private DoctrineDbalActivityTransaction $transaction,
    ) {
    }

    /**
     * @param \Closure(Connection): void $operation
     */
    public function run(
        ActivityImportGenerationId $generationId,
        ActivityId $activityId,
        \Closure $operation,
    ): void {
        $connection = $this->transaction->connection();

        if ($connection->isTransactionActive()) {
            throw ActivityImportPersistenceException::inconsistentState('Staged activity import writes must not run inside an outer transaction.');
        }

        $this->transaction->run(
            function () use (
                $generationId,
                $activityId,
                $operation,
            ): void {
                $connection = $this->transaction->connection();
                $row = $connection->fetchAssociative(
                    <<<'SQL'
                        SELECT
                            activity_id::text AS activity_id,
                            status
                        FROM activity_import_generations
                        WHERE id = :generation_id
                        FOR SHARE
                        SQL,
                    [
                        'generation_id' => $generationId->toString(),
                    ],
                    [
                        'generation_id' => ParameterType::STRING,
                    ],
                );

                if (false === $row) {
                    throw ActivityImportPersistenceException::generationNotFound($generationId);
                }

                if ($row['activity_id'] !== $activityId->toString()) {
                    throw ActivityImportPersistenceException::generationActivityMismatch(generationId: $generationId, activityId: $activityId);
                }

                if (
                    ActivityImportGenerationStatus::Staging->value
                    !== $row['status']
                ) {
                    throw ActivityImportPersistenceException::generationNotStaging(generationId: $generationId, status: (string) $row['status']);
                }

                $operation($connection);
            },
        );
    }
}
