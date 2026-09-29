<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Import;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Youmad\Endurance\Activity\Application\Import\ActivityImportGenerationClaim;
use Youmad\Endurance\Activity\Application\Port\ActivityImportGenerationRepository;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportGenerationId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportIdempotencyKey;
use Youmad\Endurance\ActivityPostgresql\Connection\DoctrineDbalActivityTransaction;
use Youmad\Endurance\ActivityPostgresql\Exception\ActivityImportPersistenceException;

final readonly class DoctrineDbalActivityImportGenerationRepository implements ActivityImportGenerationRepository
{
    private const array IMPORT_TABLES = [
        'activity_import_observations',
        'activity_import_device_status_observations',
        'activity_import_laps',
        'activity_import_details',
        'activity_import_sessions',
        'activity_import_devices',
    ];

    public function __construct(
        private DoctrineDbalActivityTransaction $transaction,
    ) {
    }

    public function claim(
        ActivityId $activityId,
        ActivityImportIdempotencyKey $idempotencyKey,
    ): ActivityImportGenerationClaim {
        return $this->transaction->run(
            function () use (
                $activityId,
                $idempotencyKey,
            ): ActivityImportGenerationClaim {
                $connection = $this->transaction->connection();
                $activity = $this->lockActivity(
                    connection: $connection,
                    activityId: $activityId,
                );
                $activeGenerationId = $activity[
                    'active_import_generation_id'
                ];

                if (null !== $activeGenerationId) {
                    return $this->claimActiveDuplicate(
                        connection: $connection,
                        activityId: $activityId,
                        activeGenerationId: ActivityImportGenerationId::fromString(
                            $activeGenerationId,
                        ),
                        idempotencyKey: $idempotencyKey,
                    );
                }

                $existing = $this->lockGenerationByKey(
                    connection: $connection,
                    activityId: $activityId,
                    idempotencyKey: $idempotencyKey,
                );

                if (null !== $existing) {
                    return $this->claimExistingGeneration(
                        connection: $connection,
                        activityId: $activityId,
                        existing: $existing,
                    );
                }

                if (
                    null !== $this->lockGenerationByStatus(
                        connection: $connection,
                        activityId: $activityId,
                        status: ActivityImportGenerationStatus::Staging,
                    )
                ) {
                    throw ActivityImportPersistenceException::generationAlreadyInProgress($activityId);
                }

                if (
                    null !== $this->lockGenerationByStatus(
                        connection: $connection,
                        activityId: $activityId,
                        status: ActivityImportGenerationStatus::Active,
                    )
                ) {
                    throw ActivityImportPersistenceException::inconsistentState(sprintf('Activity %s has an active import generation but no active generation pointer.', $activityId->toString()));
                }

                $generationId = ActivityImportGenerationId::generate();
                $connection->executeStatement(
                    <<<'SQL'
                        INSERT INTO activity_import_generations (
                            id,
                            activity_id,
                            idempotency_key,
                            status
                        ) VALUES (
                            :id,
                            :activity_id,
                            :idempotency_key,
                            :status
                        )
                        SQL,
                    [
                        'id' => $generationId->toString(),
                        'activity_id' => $activityId->toString(),
                        'idempotency_key' => $idempotencyKey->toString(),
                        'status' => ActivityImportGenerationStatus::Staging->value,
                    ],
                    [
                        'id' => ParameterType::STRING,
                        'activity_id' => ParameterType::STRING,
                        'idempotency_key' => ParameterType::STRING,
                        'status' => ParameterType::STRING,
                    ],
                );

                return ActivityImportGenerationClaim::acquired(
                    $generationId,
                );
            },
        );
    }

    public function activate(
        ActivityImportGenerationId $generationId,
    ): void {
        $activityId = $this->findGenerationActivityId(
            $generationId,
        );

        $this->transaction->run(
            function () use (
                $generationId,
                $activityId,
            ): void {
                $connection = $this->transaction->connection();
                $activity = $this->lockActivity(
                    connection: $connection,
                    activityId: $activityId,
                );
                $generation = $this->lockGenerationById(
                    connection: $connection,
                    generationId: $generationId,
                );

                $this->assertGenerationActivity(
                    generation: $generation,
                    generationId: $generationId,
                    activityId: $activityId,
                );

                if (
                    ActivityImportGenerationStatus::Active->value
                    === $generation['status']
                ) {
                    if (
                        $activity['active_import_generation_id']
                        === $generationId->toString()
                    ) {
                        return;
                    }

                    throw ActivityImportPersistenceException::inconsistentState(sprintf('Active generation %s is not attached to activity %s.', $generationId->toString(), $activityId->toString()));
                }

                if (
                    ActivityImportGenerationStatus::Staging->value
                    !== $generation['status']
                ) {
                    throw ActivityImportPersistenceException::generationNotStaging(generationId: $generationId, status: (string) $generation['status']);
                }

                if (null !== $activity['active_import_generation_id']) {
                    throw ActivityImportPersistenceException::activityAlreadyImported($activityId);
                }

                $connection->executeStatement(
                    <<<'SQL'
                        UPDATE activities
                        SET
                            active_import_generation_id = :generation_id,
                            updated_at = clock_timestamp()
                        WHERE id = :activity_id
                        SQL,
                    [
                        'generation_id' => $generationId->toString(),
                        'activity_id' => $activityId->toString(),
                    ],
                    [
                        'generation_id' => ParameterType::STRING,
                        'activity_id' => ParameterType::STRING,
                    ],
                );

                $connection->executeStatement(
                    <<<'SQL'
                        UPDATE activity_import_generations
                        SET
                            status = :status,
                            activated_at = clock_timestamp(),
                            failed_at = NULL,
                            updated_at = clock_timestamp()
                        WHERE id = :generation_id
                        SQL,
                    [
                        'status' => ActivityImportGenerationStatus::Active->value,
                        'generation_id' => $generationId->toString(),
                    ],
                    [
                        'status' => ParameterType::STRING,
                        'generation_id' => ParameterType::STRING,
                    ],
                );
            },
        );
    }

    public function fail(
        ActivityImportGenerationId $generationId,
    ): void {
        $activityId = $this->findGenerationActivityId(
            $generationId,
        );

        $this->transaction->run(
            function () use (
                $generationId,
                $activityId,
            ): void {
                $connection = $this->transaction->connection();
                $this->lockActivity(
                    connection: $connection,
                    activityId: $activityId,
                );
                $generation = $this->lockGenerationById(
                    connection: $connection,
                    generationId: $generationId,
                );

                $this->assertGenerationActivity(
                    generation: $generation,
                    generationId: $generationId,
                    activityId: $activityId,
                );

                if (
                    ActivityImportGenerationStatus::Failed->value
                    === $generation['status']
                ) {
                    return;
                }

                if (
                    ActivityImportGenerationStatus::Staging->value
                    !== $generation['status']
                ) {
                    throw ActivityImportPersistenceException::generationNotStaging(generationId: $generationId, status: (string) $generation['status']);
                }

                $connection->executeStatement(
                    <<<'SQL'
                        UPDATE activity_import_generations
                        SET
                            status = :status,
                            activated_at = NULL,
                            failed_at = clock_timestamp(),
                            updated_at = clock_timestamp()
                        WHERE id = :generation_id
                        SQL,
                    [
                        'status' => ActivityImportGenerationStatus::Failed->value,
                        'generation_id' => $generationId->toString(),
                    ],
                    [
                        'status' => ParameterType::STRING,
                        'generation_id' => ParameterType::STRING,
                    ],
                );
            },
        );
    }

    /**
     * @return array{
     *     active_import_generation_id: string|null
     * }
     */
    private function lockActivity(
        Connection $connection,
        ActivityId $activityId,
    ): array {
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
            throw ActivityImportPersistenceException::activityNotFound($activityId);
        }

        return [
            'active_import_generation_id' => $row['active_import_generation_id'],
        ];
    }

    private function claimActiveDuplicate(
        Connection $connection,
        ActivityId $activityId,
        ActivityImportGenerationId $activeGenerationId,
        ActivityImportIdempotencyKey $idempotencyKey,
    ): ActivityImportGenerationClaim {
        $generation = $this->lockGenerationById(
            connection: $connection,
            generationId: $activeGenerationId,
        );

        $this->assertGenerationActivity(
            generation: $generation,
            generationId: $activeGenerationId,
            activityId: $activityId,
        );

        if (
            ActivityImportGenerationStatus::Active->value
            !== $generation['status']
        ) {
            throw ActivityImportPersistenceException::inconsistentState(sprintf('Activity %s points to non-active generation %s.', $activityId->toString(), $activeGenerationId->toString()));
        }

        if (
            hash_equals(
                (string) $generation['idempotency_key'],
                $idempotencyKey->toString(),
            )
        ) {
            return ActivityImportGenerationClaim::alreadyCompleted(
                $activeGenerationId,
            );
        }

        throw ActivityImportPersistenceException::activityAlreadyImported($activityId);
    }

    /**
     * @param array{
     *     id: string,
     *     activity_id: string,
     *     idempotency_key: string,
     *     status: string
     * } $existing
     */
    private function claimExistingGeneration(
        Connection $connection,
        ActivityId $activityId,
        array $existing,
    ): ActivityImportGenerationClaim {
        $generationId = ActivityImportGenerationId::fromString(
            $existing['id'],
        );

        return match ($existing['status']) {
            ActivityImportGenerationStatus::Staging->value => throw ActivityImportPersistenceException::generationAlreadyInProgress($activityId),
            ActivityImportGenerationStatus::Active->value => throw ActivityImportPersistenceException::inconsistentState(sprintf('Generation %s is active but activity %s has no active generation pointer.', $generationId->toString(), $activityId->toString())),
            ActivityImportGenerationStatus::Failed->value => $this->reclaimFailedGeneration(
                connection: $connection,
                generationId: $generationId,
            ),
            default => throw ActivityImportPersistenceException::inconsistentState(sprintf('Generation %s has unsupported status %s.', $generationId->toString(), $existing['status'])),
        };
    }

    private function reclaimFailedGeneration(
        Connection $connection,
        ActivityImportGenerationId $generationId,
    ): ActivityImportGenerationClaim {
        foreach (self::IMPORT_TABLES as $table) {
            $connection->executeStatement(
                sprintf(
                    'DELETE FROM %s WHERE import_generation_id = :generation_id',
                    $table,
                ),
                [
                    'generation_id' => $generationId->toString(),
                ],
                [
                    'generation_id' => ParameterType::STRING,
                ],
            );
        }

        // Rotating the generation id is the fencing token for a retry. Any
        // worker that still holds the previous id can no longer append rows,
        // activate, or fail the newly reclaimed generation.
        $reclaimedGenerationId = ActivityImportGenerationId::generate();
        $affected = $connection->executeStatement(
            <<<'SQL'
                UPDATE activity_import_generations
                SET
                    id = :reclaimed_generation_id,
                    status = :status,
                    attempt_count = attempt_count + 1,
                    staging_started_at = clock_timestamp(),
                    activated_at = NULL,
                    failed_at = NULL,
                    updated_at = clock_timestamp()
                WHERE id = :generation_id
                SQL,
            [
                'reclaimed_generation_id' => $reclaimedGenerationId->toString(),
                'status' => ActivityImportGenerationStatus::Staging->value,
                'generation_id' => $generationId->toString(),
            ],
            [
                'reclaimed_generation_id' => ParameterType::STRING,
                'status' => ParameterType::STRING,
                'generation_id' => ParameterType::STRING,
            ],
        );

        if (1 !== $affected) {
            throw ActivityImportPersistenceException::inconsistentState(sprintf('Failed generation %s could not be reclaimed.', $generationId->toString()));
        }

        return ActivityImportGenerationClaim::acquired(
            $reclaimedGenerationId,
        );
    }

    private function findGenerationActivityId(
        ActivityImportGenerationId $generationId,
    ): ActivityId {
        $activityId = $this
            ->transaction
            ->connection()
            ->fetchOne(
                <<<'SQL'
                    SELECT activity_id::text
                    FROM activity_import_generations
                    WHERE id = :generation_id
                    SQL,
                [
                    'generation_id' => $generationId->toString(),
                ],
                [
                    'generation_id' => ParameterType::STRING,
                ],
            );

        if (false === $activityId) {
            throw ActivityImportPersistenceException::generationNotFound($generationId);
        }

        return ActivityId::fromString((string) $activityId);
    }

    /**
     * @return array{
     *     id: string,
     *     activity_id: string,
     *     idempotency_key: string,
     *     status: string
     * }
     */
    private function lockGenerationById(
        Connection $connection,
        ActivityImportGenerationId $generationId,
    ): array {
        $row = $connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    id::text,
                    activity_id::text,
                    idempotency_key,
                    status
                FROM activity_import_generations
                WHERE id = :generation_id
                FOR UPDATE
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

        return $row;
    }

    /**
     * @return array{
     *     id: string,
     *     activity_id: string,
     *     idempotency_key: string,
     *     status: string
     * }|null
     */
    private function lockGenerationByKey(
        Connection $connection,
        ActivityId $activityId,
        ActivityImportIdempotencyKey $idempotencyKey,
    ): ?array {
        $row = $connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    id::text,
                    activity_id::text,
                    idempotency_key,
                    status
                FROM activity_import_generations
                WHERE activity_id = :activity_id
                  AND idempotency_key = :idempotency_key
                FOR UPDATE
                SQL,
            [
                'activity_id' => $activityId->toString(),
                'idempotency_key' => $idempotencyKey->toString(),
            ],
            [
                'activity_id' => ParameterType::STRING,
                'idempotency_key' => ParameterType::STRING,
            ],
        );

        return false === $row ? null : $row;
    }

    /**
     * @return array{
     *     id: string,
     *     activity_id: string,
     *     idempotency_key: string,
     *     status: string
     * }|null
     */
    private function lockGenerationByStatus(
        Connection $connection,
        ActivityId $activityId,
        ActivityImportGenerationStatus $status,
    ): ?array {
        $row = $connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    id::text,
                    activity_id::text,
                    idempotency_key,
                    status
                FROM activity_import_generations
                WHERE activity_id = :activity_id
                  AND status = :status
                FOR UPDATE
                SQL,
            [
                'activity_id' => $activityId->toString(),
                'status' => $status->value,
            ],
            [
                'activity_id' => ParameterType::STRING,
                'status' => ParameterType::STRING,
            ],
        );

        return false === $row ? null : $row;
    }

    /**
     * @param array{
     *     id: string,
     *     activity_id: string,
     *     idempotency_key: string,
     *     status: string
     * } $generation
     */
    private function assertGenerationActivity(
        array $generation,
        ActivityImportGenerationId $generationId,
        ActivityId $activityId,
    ): void {
        if ($generation['activity_id'] === $activityId->toString()) {
            return;
        }

        throw ActivityImportPersistenceException::generationActivityMismatch(generationId: $generationId, activityId: $activityId);
    }
}
