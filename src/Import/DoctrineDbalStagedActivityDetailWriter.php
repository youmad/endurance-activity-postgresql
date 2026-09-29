<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Import;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Youmad\Endurance\Activity\Application\Port\StagedActivityDetailWriter;
use Youmad\Endurance\Activity\Detail\ActivityDetail;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportGenerationId;
use Youmad\Endurance\ActivityPostgresql\Serialization\ActivityImportPayloadEncoder;

final readonly class DoctrineDbalStagedActivityDetailWriter implements StagedActivityDetailWriter
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
        ActivityDetail $detail,
    ): void {
        $this->writes->run(
            generationId: $generationId,
            activityId: $activityId,
            operation: function (Connection $connection) use (
                $generationId,
                $activityId,
                $detail,
            ): void {
                $interval = $detail->interval();
                $sequenceName = $this->payloads->sequenceName($detail);
                $connection->executeStatement(
                    <<<'SQL'
                        INSERT INTO activity_import_details (
                            import_generation_id,
                            activity_id,
                            detail_type,
                            sequence_name,
                            started_at,
                            finished_at,
                            timer_duration_microseconds,
                            payload
                        ) VALUES (
                            :generation_id,
                            :activity_id,
                            :detail_type,
                            :sequence_name,
                            :started_at,
                            :finished_at,
                            :timer_duration_microseconds,
                            CAST(:payload AS jsonb)
                        )
                        SQL,
                    [
                        'generation_id' => $generationId->toString(),
                        'activity_id' => $activityId->toString(),
                        'detail_type' => $this->payloads->detailType($detail),
                        'sequence_name' => $sequenceName,
                        'started_at' => $this->payloads->instant(
                            $interval->startedAt,
                        ),
                        'finished_at' => $this->payloads->instant(
                            $interval->finishedAt,
                        ),
                        'timer_duration_microseconds' => $interval->timerDuration->toMicroseconds(),
                        'payload' => $this->payloads->detail($detail),
                    ],
                    [
                        'generation_id' => ParameterType::STRING,
                        'activity_id' => ParameterType::STRING,
                        'detail_type' => ParameterType::STRING,
                        'sequence_name' => null === $sequenceName
                            ? ParameterType::NULL
                            : ParameterType::STRING,
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
