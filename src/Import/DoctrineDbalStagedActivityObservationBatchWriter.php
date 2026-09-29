<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Import;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Youmad\Endurance\Activity\Application\Port\StagedActivityObservationBatchWriter;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportGenerationId;
use Youmad\Endurance\ActivityPostgresql\Serialization\ActivityImportPayloadEncoder;

final readonly class DoctrineDbalStagedActivityObservationBatchWriter implements StagedActivityObservationBatchWriter
{
    public function __construct(
        private DoctrineDbalStagedWriteExecutor $writes,
        private ActivityImportPayloadEncoder $payloads =
            new ActivityImportPayloadEncoder(),
    ) {
    }

    public function appendBatch(
        ActivityImportGenerationId $generationId,
        ActivityId $activityId,
        array $observations,
    ): void {
        // @phpstan-ignore identical.alwaysFalse (Reject batches that violate the interface PHPDoc at runtime.)
        if ([] === $observations) {
            throw new \InvalidArgumentException('Staged activity observation batch cannot be empty.');
        }

        $this->writes->run(
            generationId: $generationId,
            activityId: $activityId,
            operation: function (Connection $connection) use (
                $generationId,
                $activityId,
                $observations,
            ): void {
                $rows = [];
                $parameters = [];
                $types = [];

                foreach ($observations as $observation) {
                    $rows[] = '(?, ?, ?, CAST(? AS jsonb))';
                    $parameters[] = $generationId->toString();
                    $types[] = ParameterType::STRING;
                    $parameters[] = $activityId->toString();
                    $types[] = ParameterType::STRING;
                    $parameters[] = $this->payloads->instant(
                        $observation->timestamp,
                    );
                    $types[] = ParameterType::STRING;
                    $parameters[] = $this->payloads->observation(
                        $observation,
                    );
                    $types[] = ParameterType::STRING;
                }

                $connection->executeStatement(
                    sprintf(
                        <<<'SQL'
                            INSERT INTO activity_import_observations (
                                import_generation_id,
                                activity_id,
                                observed_at,
                                payload
                            ) VALUES %s
                            SQL,
                        implode(', ', $rows),
                    ),
                    $parameters,
                    $types,
                );
            },
        );
    }
}
