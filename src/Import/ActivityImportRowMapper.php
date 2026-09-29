<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Import;

use Youmad\Endurance\Activity\Application\Import\ActivityImport;
use Youmad\Endurance\Activity\Application\Import\ActivityImportStatus;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportId;
use Youmad\Endurance\Foundation\ValueObject\Instant;

final readonly class ActivityImportRowMapper
{
    public function __construct(
        private ActivityImportWarningJsonCodec $warnings =
            new ActivityImportWarningJsonCodec(),
    ) {
    }

    /**
     * @param array{
     *     id: string,
     *     activity_id: string,
     *     status: string,
     *     attempt_count: int|string,
     *     created_at: string,
     *     processing_started_at: ?string,
     *     completed_at: ?string,
     *     failed_at: ?string,
     *     error_code: ?string,
     *     error_message: ?string,
     *     warnings: string
     * } $row
     */
    public function import(array $row): ActivityImport
    {
        return new ActivityImport(
            id: ActivityImportId::fromString($row['id']),
            activityId: ActivityId::fromString($row['activity_id']),
            status: ActivityImportStatus::from($row['status']),
            attemptCount: (int) $row['attempt_count'],
            createdAt: $this->instant($row['created_at']),
            processingStartedAt: $this->optionalInstant(
                $row['processing_started_at'],
            ),
            completedAt: $this->optionalInstant($row['completed_at']),
            failedAt: $this->optionalInstant($row['failed_at']),
            errorCode: $row['error_code'],
            errorMessage: $row['error_message'],
            warnings: $this->warnings->decode($row['warnings']),
        );
    }

    private function instant(string $value): Instant
    {
        return Instant::fromDateTimeImmutable(
            new \DateTimeImmutable($value),
        );
    }

    private function optionalInstant(?string $value): ?Instant
    {
        return null === $value
            ? null
            : $this->instant($value);
    }
}
