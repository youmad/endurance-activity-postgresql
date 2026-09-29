<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Import;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Youmad\Endurance\Activity\Application\Recovery\ActivityImportRecoveryCleanupBatch;
use Youmad\Endurance\Activity\ValueObject\ActivityImportGenerationId;

final readonly class DoctrineDbalAbandonedActivityImportGenerationCleaner
{
    private const array IMPORT_TABLES = [
        'activity_import_observations',
        'activity_import_device_status_observations',
        'activity_import_laps',
        'activity_import_details',
        'activity_import_sessions',
        'activity_import_devices',
    ];

    public function cleanupBatch(
        Connection $connection,
        ActivityImportGenerationId $generationId,
        int $batchSize,
    ): ActivityImportRecoveryCleanupBatch {
        foreach (self::IMPORT_TABLES as $table) {
            $deleted = $connection->executeStatement(
                sprintf(
                    <<<'SQL'
                    DELETE FROM %s
                    WHERE ctid IN (
                        SELECT ctid
                        FROM %s
                        WHERE import_generation_id = :generation_id
                        LIMIT :batch_size
                    )
                    SQL,
                    $table,
                    $table,
                ),
                [
                    'generation_id' => $generationId->toString(),
                    'batch_size' => $batchSize,
                ],
                [
                    'generation_id' => ParameterType::STRING,
                    'batch_size' => ParameterType::INTEGER,
                ],
            );

            if (0 < $deleted) {
                return new ActivityImportRecoveryCleanupBatch(
                    deletedRows: $deleted,
                    complete: false,
                );
            }
        }

        return new ActivityImportRecoveryCleanupBatch(
            deletedRows: 0,
            complete: true,
        );
    }

    public function isClean(
        Connection $connection,
        ActivityImportGenerationId $generationId,
    ): bool {
        foreach (self::IMPORT_TABLES as $table) {
            $exists = $connection->fetchOne(
                sprintf(
                    'SELECT 1 FROM %s WHERE import_generation_id = :generation_id LIMIT 1',
                    $table,
                ),
                ['generation_id' => $generationId->toString()],
            );

            if (false !== $exists) {
                return false;
            }
        }

        return true;
    }
}
