<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Import;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Youmad\Endurance\Activity\Application\Port\StagedActivityDeviceWriter;
use Youmad\Endurance\Activity\Device\ActivityDevice;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportGenerationId;
use Youmad\Endurance\ActivityPostgresql\Serialization\ActivityImportPayloadEncoder;

final readonly class DoctrineDbalStagedActivityDeviceWriter implements StagedActivityDeviceWriter
{
    public function __construct(
        private DoctrineDbalStagedWriteExecutor $writes,
        private ActivityImportPayloadEncoder $payloads =
            new ActivityImportPayloadEncoder(),
    ) {
    }

    public function save(
        ActivityImportGenerationId $generationId,
        ActivityId $activityId,
        ActivityDevice $device,
    ): void {
        $this->writes->run(
            generationId: $generationId,
            activityId: $activityId,
            operation: function (Connection $connection) use (
                $generationId,
                $activityId,
                $device,
            ): void {
                $connection->executeStatement(
                    <<<'SQL'
                        INSERT INTO activity_import_devices (
                            import_generation_id,
                            activity_id,
                            device_id,
                            payload
                        ) VALUES (
                            :generation_id,
                            :activity_id,
                            :device_id,
                            CAST(:payload AS jsonb)
                        )
                        ON CONFLICT (import_generation_id, device_id)
                        DO UPDATE SET
                            payload = EXCLUDED.payload,
                            payload_version = EXCLUDED.payload_version,
                            updated_at = clock_timestamp()
                        SQL,
                    [
                        'generation_id' => $generationId->toString(),
                        'activity_id' => $activityId->toString(),
                        'device_id' => $device->id->toString(),
                        'payload' => $this->payloads->device($device),
                    ],
                    [
                        'generation_id' => ParameterType::STRING,
                        'activity_id' => ParameterType::STRING,
                        'device_id' => ParameterType::STRING,
                        'payload' => ParameterType::STRING,
                    ],
                );
            },
        );
    }
}
