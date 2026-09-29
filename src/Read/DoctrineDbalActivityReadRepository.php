<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Read;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Youmad\Endurance\Activity\Application\Port\ActivityReadRepository;
use Youmad\Endurance\Activity\Application\Read\ActivityReadModel;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\ActivityPostgresql\Exception\ActivityPersistenceException;

final readonly class DoctrineDbalActivityReadRepository implements ActivityReadRepository
{
    public function __construct(
        private Connection $connection,
        private ActivityReadRowMapper $rows,
    ) {
    }

    public function find(ActivityId $activityId): ?ActivityReadModel
    {
        try {
            $activityRow = $this->connection->fetchAssociative(
                <<<'SQL'
                    SELECT
                        id::text,
                        started_at::text,
                        finished_at::text,
                        activity_type,
                        local_time_offset_seconds,
                        session_count,
                        recorded_session_timer_duration_microseconds,
                        accumulated_paused_duration_microseconds
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

            if (false === $activityRow) {
                return null;
            }

            $sessionRows = $this->connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT
                        started_at::text,
                        finished_at::text,
                        timer_duration_microseconds,
                        payload::text
                    FROM active_activity_import_sessions
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
            throw ActivityPersistenceException::operationFailed(operation: sprintf('read activity %s', $activityId->toString()), previous: $exception);
        }

        try {
            /* @var array{
             *     id: string,
             *     started_at: string,
             *     finished_at: ?string,
             *     activity_type: ?string,
             *     local_time_offset_seconds: int|string|null,
             *     session_count: int|string,
             *     recorded_session_timer_duration_microseconds: int|string,
             *     accumulated_paused_duration_microseconds: int|string
             * } $activityRow
             * @var list<array{
             *     started_at: string,
             *     finished_at: string,
             *     timer_duration_microseconds: int|string,
             *     payload: string
             * }> $sessionRows
             */
            return $this->rows->activity(
                activityRow: $activityRow,
                sessionRows: $sessionRows,
            );
        } catch (\Throwable $exception) {
            throw ActivityPersistenceException::invalidStoredState(activityId: $activityId, previous: $exception);
        }
    }
}
