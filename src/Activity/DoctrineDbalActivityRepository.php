<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Activity;

use Doctrine\DBAL\ParameterType;
use Youmad\Endurance\Activity\Application\Port\ActivityRepository;
use Youmad\Endurance\Activity\Entity\Activity;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\ActivityPostgresql\Connection\DoctrineDbalActivityTransaction;
use Youmad\Endurance\ActivityPostgresql\Exception\ActivityPersistenceException;

final class DoctrineDbalActivityRepository implements ActivityRepository
{
    /** @var \WeakMap<Activity, int> */
    private \WeakMap $versions;

    public function __construct(
        private readonly DoctrineDbalActivityTransaction $transaction,
        private readonly ActivityRowMapper $rows,
    ) {
        $this->versions = new \WeakMap();
    }

    public function get(ActivityId $activityId): Activity
    {
        try {
            $row = $this
                ->transaction
                ->connection()
                ->fetchAssociative(
                    <<<'SQL'
                        SELECT
                            id::text,
                            started_at::text,
                            timer_started_at::text,
                            finished_at::text,
                            last_observation_at::text,
                            last_lap_finished_at::text,
                            last_session_finished_at::text,
                            last_session_timeline_finished_at::text,
                            last_detail_finished_at::text,
                            last_sequential_detail_finished_at::text,
                            session_count,
                            recorded_session_timer_duration_microseconds,
                            summary_reported_at::text,
                            activity_type,
                            local_time_offset_seconds,
                            paused_at::text,
                            accumulated_paused_duration_microseconds,
                            latest_timestamp::text,
                            version
                        FROM activities
                        WHERE id = :id
                        SQL,
                    [
                        'id' => $activityId->toString(),
                    ],
                    [
                        'id' => ParameterType::STRING,
                    ],
                );
        } catch (\Throwable $exception) {
            throw ActivityPersistenceException::operationFailed(operation: sprintf('load activity %s', $activityId->toString()), previous: $exception);
        }

        if (false === $row) {
            throw ActivityPersistenceException::notFound($activityId);
        }

        try {
            /** @var array{
             *     id: string,
             *     started_at: string,
             *     timer_started_at: ?string,
             *     finished_at: ?string,
             *     last_observation_at: ?string,
             *     last_lap_finished_at: ?string,
             *     last_session_finished_at: ?string,
             *     last_session_timeline_finished_at: ?string,
             *     last_detail_finished_at: ?string,
             *     last_sequential_detail_finished_at: string,
             *     session_count: int|string,
             *     recorded_session_timer_duration_microseconds: int|string,
             *     summary_reported_at: ?string,
             *     activity_type: ?string,
             *     local_time_offset_seconds: int|string|null,
             *     paused_at: ?string,
             *     accumulated_paused_duration_microseconds: int|string,
             *     latest_timestamp: string,
             *     version: int|string
             * } $row
             */
            $activity = $this->rows->activity($row);
        } catch (\Throwable $exception) {
            throw ActivityPersistenceException::invalidStoredState(activityId: $activityId, previous: $exception);
        }

        $this->versions[$activity] = (int) $row['version'];

        return $activity;
    }

    public function createIfAbsent(Activity $activity): bool
    {
        $parameters = $this->rows->parameters($activity);

        try {
            $affectedRows = $this
                ->transaction
                ->connection()
                ->executeStatement(
                    <<<'SQL'
                        INSERT INTO activities (
                            id,
                            started_at,
                            timer_started_at,
                            finished_at,
                            last_observation_at,
                            last_lap_finished_at,
                            last_session_finished_at,
                            last_session_timeline_finished_at,
                            last_detail_finished_at,
                            last_sequential_detail_finished_at,
                            session_count,
                            recorded_session_timer_duration_microseconds,
                            summary_reported_at,
                            activity_type,
                            local_time_offset_seconds,
                            paused_at,
                            accumulated_paused_duration_microseconds,
                            latest_timestamp
                        ) VALUES (
                            :id,
                            :started_at,
                            :timer_started_at,
                            :finished_at,
                            :last_observation_at,
                            :last_lap_finished_at,
                            :last_session_finished_at,
                            :last_session_timeline_finished_at,
                            :last_detail_finished_at,
                            CAST(:last_sequential_detail_finished_at AS jsonb),
                            :session_count,
                            :recorded_session_timer_duration_microseconds,
                            :summary_reported_at,
                            :activity_type,
                            :local_time_offset_seconds,
                            :paused_at,
                            :accumulated_paused_duration_microseconds,
                            :latest_timestamp
                        )
                        ON CONFLICT (id) DO NOTHING
                        SQL,
                    $parameters,
                    $this->parameterTypes($parameters),
                );
        } catch (\Throwable $exception) {
            throw ActivityPersistenceException::operationFailed(operation: sprintf('create activity %s if absent', $activity->id->toString()), previous: $exception);
        }

        if (1 !== $affectedRows) {
            return false;
        }

        $this->versions[$activity] = 1;

        return true;
    }

    public function save(Activity $activity): void
    {
        $version = $this->versions[$activity] ?? null;

        if (null === $version) {
            $this->insert($activity);

            return;
        }

        $this->update(
            activity: $activity,
            expectedVersion: $version,
        );
    }

    private function insert(Activity $activity): void
    {
        if ($this->createIfAbsent($activity)) {
            return;
        }

        throw ActivityPersistenceException::alreadyExists($activity->id);
    }

    private function update(
        Activity $activity,
        int $expectedVersion,
    ): void {
        $parameters = $this->rows->parameters($activity);
        unset($parameters['id']);
        $parameters['activity_id'] = $activity->id->toString();
        $parameters['expected_version'] = $expectedVersion;

        try {
            $affectedRows = $this
                ->transaction
                ->connection()
                ->executeStatement(
                    <<<'SQL'
                        UPDATE activities
                        SET
                            started_at = :started_at,
                            timer_started_at = :timer_started_at,
                            finished_at = :finished_at,
                            last_observation_at = :last_observation_at,
                            last_lap_finished_at = :last_lap_finished_at,
                            last_session_finished_at = :last_session_finished_at,
                            last_session_timeline_finished_at =
                                :last_session_timeline_finished_at,
                            last_detail_finished_at = :last_detail_finished_at,
                            last_sequential_detail_finished_at =
                                CAST(:last_sequential_detail_finished_at AS jsonb),
                            session_count = :session_count,
                            recorded_session_timer_duration_microseconds =
                                :recorded_session_timer_duration_microseconds,
                            summary_reported_at = :summary_reported_at,
                            activity_type = :activity_type,
                            local_time_offset_seconds =
                                :local_time_offset_seconds,
                            paused_at = :paused_at,
                            accumulated_paused_duration_microseconds =
                                :accumulated_paused_duration_microseconds,
                            latest_timestamp = :latest_timestamp,
                            version = version + 1,
                            updated_at = clock_timestamp()
                        WHERE id = :activity_id
                          AND version = :expected_version
                        SQL,
                    $parameters,
                    $this->parameterTypes($parameters),
                );
        } catch (\Throwable $exception) {
            throw ActivityPersistenceException::operationFailed(operation: sprintf('update activity %s', $activity->id->toString()), previous: $exception);
        }

        if (1 === $affectedRows) {
            $this->versions[$activity] = $expectedVersion + 1;

            return;
        }

        if (!$this->exists($activity->id)) {
            throw ActivityPersistenceException::notFound($activity->id);
        }

        throw ActivityPersistenceException::concurrentlyModified($activity->id);
    }

    private function exists(ActivityId $activityId): bool
    {
        try {
            return false !== $this
                ->transaction
                ->connection()
                ->fetchOne(
                    'SELECT 1 FROM activities WHERE id = :id',
                    [
                        'id' => $activityId->toString(),
                    ],
                    [
                        'id' => ParameterType::STRING,
                    ],
                );
        } catch (\Throwable $exception) {
            throw ActivityPersistenceException::operationFailed(operation: sprintf('verify activity %s', $activityId->toString()), previous: $exception);
        }
    }

    /**
     * @param array<string, int|string|null> $parameters
     *
     * @return array<string, ParameterType>
     */
    private function parameterTypes(array $parameters): array
    {
        $types = [];

        foreach ($parameters as $name => $value) {
            $types[$name] = match (true) {
                null === $value => ParameterType::NULL,
                is_int($value) => ParameterType::INTEGER,
                default => ParameterType::STRING,
            };
        }

        return $types;
    }
}
