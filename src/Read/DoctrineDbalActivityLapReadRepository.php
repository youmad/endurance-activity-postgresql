<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Read;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Youmad\Endurance\Activity\Application\Port\ActivityLapReadRepository;
use Youmad\Endurance\Activity\Application\Read\ActivityLapsReadModel;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\ActivityPostgresql\Exception\ActivityPersistenceException;

final readonly class DoctrineDbalActivityLapReadRepository implements ActivityLapReadRepository
{
    public function __construct(
        private Connection $connection,
        private ActivityLapReadRowMapper $rows,
    ) {
    }

    public function findForActivity(
        ActivityId $activityId,
    ): ?ActivityLapsReadModel {
        try {
            $activityExists = false !== $this->connection->fetchOne(
                <<<'SQL'
                    SELECT 1
                    FROM activities
                    WHERE id = :id
                      AND active_import_generation_id IS NOT NULL
                    SQL,
                [
                    'id' => $activityId->toString(),
                ],
                [
                    'id' => ParameterType::STRING,
                ],
            );

            if (!$activityExists) {
                return null;
            }

            $lapRows = $this->connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT
                        started_at::text,
                        finished_at::text,
                        timer_duration_microseconds,
                        payload::text
                    FROM active_activity_import_laps
                    WHERE activity_id = :id
                    ORDER BY started_at, id
                    SQL,
                [
                    'id' => $activityId->toString(),
                ],
                [
                    'id' => ParameterType::STRING,
                ],
            );
        } catch (\Throwable $exception) {
            throw ActivityPersistenceException::operationFailed(operation: sprintf('read laps of activity %s', $activityId->toString()), previous: $exception);
        }

        try {
            /* @var list<array{
             *     started_at: string,
             *     finished_at: string,
             *     timer_duration_microseconds: int|string,
             *     payload: string
             * }> $lapRows
             */
            return $this->rows->laps(
                activityId: $activityId,
                rows: $lapRows,
            );
        } catch (\Throwable $exception) {
            throw ActivityPersistenceException::invalidStoredState(activityId: $activityId, previous: $exception);
        }
    }
}
