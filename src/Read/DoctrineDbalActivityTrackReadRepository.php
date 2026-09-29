<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Read;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Youmad\Endurance\Activity\Application\Port\ActivityTrackReadRepository;
use Youmad\Endurance\Activity\Application\Read\ActivityTrackCursor;
use Youmad\Endurance\Activity\Application\Read\ActivityTrackPage;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\ActivityPostgresql\Exception\ActivityPersistenceException;

final readonly class DoctrineDbalActivityTrackReadRepository implements ActivityTrackReadRepository
{
    public function __construct(
        private Connection $connection,
        private ActivityTrackReadRowMapper $rows,
    ) {
    }

    public function findPage(
        ActivityId $activityId,
        int $limit,
        ?ActivityTrackCursor $after,
    ): ?ActivityTrackPage {
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

            $rowLimit = $limit + 1;

            if (null === $after) {
                $trackRows = $this->connection->fetchAllAssociative(
                    <<<'SQL'
                        SELECT
                            id,
                            observed_at::text,
                            payload::text
                        FROM active_activity_import_observations
                        WHERE activity_id = :id
                        ORDER BY observed_at, id
                        LIMIT :row_limit
                        SQL,
                    [
                        'id' => $activityId->toString(),
                        'row_limit' => $rowLimit,
                    ],
                    [
                        'id' => ParameterType::STRING,
                        'row_limit' => ParameterType::INTEGER,
                    ],
                );
            } else {
                $trackRows = $this->connection->fetchAllAssociative(
                    <<<'SQL'
                        SELECT
                            id,
                            observed_at::text,
                            payload::text
                        FROM active_activity_import_observations
                        WHERE activity_id = :id
                          AND (observed_at, id) > (
                              CAST(:after_observed_at AS timestamptz),
                              :after_observation_id
                          )
                        ORDER BY observed_at, id
                        LIMIT :row_limit
                        SQL,
                    [
                        'id' => $activityId->toString(),
                        'after_observed_at' => $after
                            ->observedAt
                            ->toDateTimeImmutable()
                            ->setTimezone(new \DateTimeZone('UTC'))
                            ->format('Y-m-d\TH:i:s.u\Z'),
                        'after_observation_id' => $after->observationId,
                        'row_limit' => $rowLimit,
                    ],
                    [
                        'id' => ParameterType::STRING,
                        'after_observed_at' => ParameterType::STRING,
                        'after_observation_id' => ParameterType::INTEGER,
                        'row_limit' => ParameterType::INTEGER,
                    ],
                );
            }
        } catch (\Throwable $exception) {
            throw ActivityPersistenceException::operationFailed(operation: sprintf('read track of activity %s', $activityId->toString()), previous: $exception);
        }

        try {
            /* @var list<array{
             *     id: int|string,
             *     observed_at: string,
             *     payload: string
             * }> $trackRows
             */
            return $this->rows->page(
                activityId: $activityId,
                rows: $trackRows,
                limit: $limit,
            );
        } catch (\Throwable $exception) {
            throw ActivityPersistenceException::invalidStoredState(activityId: $activityId, previous: $exception);
        }
    }
}
