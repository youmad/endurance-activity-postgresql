<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Import;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Youmad\Endurance\Activity\Application\Port\StagedLapWriter;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportGenerationId;
use Youmad\Endurance\Activity\ValueObject\Lap;
use Youmad\Endurance\ActivityPostgresql\Serialization\ActivityImportPayloadEncoder;

final readonly class DoctrineDbalStagedLapWriter implements StagedLapWriter
{
    public function __construct(
        private DoctrineDbalStagedWriteExecutor $writes,
        private ActivityImportPayloadEncoder $payloads =
            new ActivityImportPayloadEncoder(),
    ) {
    }

    public function append(
        ActivityImportGenerationId $generationId,
        ActivityId $activityId,
        Lap $lap,
    ): void {
        $this->writes->run(
            generationId: $generationId,
            activityId: $activityId,
            operation: function (Connection $connection) use (
                $generationId,
                $activityId,
                $lap,
            ): void {
                $connection->executeStatement(
                    <<<'SQL'
                        INSERT INTO activity_import_laps (
                            import_generation_id,
                            activity_id,
                            started_at,
                            finished_at,
                            timer_duration_microseconds,
                            payload
                        ) VALUES (
                            :generation_id,
                            :activity_id,
                            :started_at,
                            :finished_at,
                            :timer_duration_microseconds,
                            CAST(:payload AS jsonb)
                        )
                        SQL,
                    [
                        'generation_id' => $generationId->toString(),
                        'activity_id' => $activityId->toString(),
                        'started_at' => $this->payloads->instant(
                            $lap->startedAt,
                        ),
                        'finished_at' => $this->payloads->instant(
                            $lap->finishedAt,
                        ),
                        'timer_duration_microseconds' => $lap->timerDuration->toMicroseconds(),
                        'payload' => $this->payloads->lap($lap),
                    ],
                    [
                        'generation_id' => ParameterType::STRING,
                        'activity_id' => ParameterType::STRING,
                        'started_at' => ParameterType::STRING,
                        'finished_at' => ParameterType::STRING,
                        'timer_duration_microseconds' => ParameterType::INTEGER,
                        'payload' => ParameterType::STRING,
                    ],
                );
            },
        );
    }
}
