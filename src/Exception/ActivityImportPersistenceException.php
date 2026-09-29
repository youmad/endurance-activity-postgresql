<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Exception;

use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\Activity\ValueObject\ActivityImportGenerationId;

final class ActivityImportPersistenceException extends \RuntimeException
{
    public static function activityNotFound(
        ActivityId $activityId,
    ): self {
        return new self(
            sprintf(
                'Activity %s does not exist.',
                $activityId->toString(),
            ),
        );
    }

    public static function generationNotFound(
        ActivityImportGenerationId $generationId,
    ): self {
        return new self(
            sprintf(
                'Activity import generation %s does not exist.',
                $generationId->toString(),
            ),
        );
    }

    public static function generationAlreadyInProgress(
        ActivityId $activityId,
    ): self {
        return new self(
            sprintf(
                'Activity %s already has an import generation in progress.',
                $activityId->toString(),
            ),
        );
    }

    public static function activityAlreadyImported(
        ActivityId $activityId,
    ): self {
        return new self(
            sprintf(
                'Activity %s was already imported with a different idempotency key.',
                $activityId->toString(),
            ),
        );
    }

    public static function generationNotStaging(
        ActivityImportGenerationId $generationId,
        string $status,
    ): self {
        return new self(
            sprintf(
                'Activity import generation %s has status %s; staging was required.',
                $generationId->toString(),
                $status,
            ),
        );
    }

    public static function generationActivityMismatch(
        ActivityImportGenerationId $generationId,
        ActivityId $activityId,
    ): self {
        return new self(
            sprintf(
                'Activity import generation %s does not belong to activity %s.',
                $generationId->toString(),
                $activityId->toString(),
            ),
        );
    }

    public static function inconsistentState(string $message): self
    {
        return new self($message);
    }

    public static function unsupportedPayload(
        object $value,
    ): self {
        return new self(
            sprintf(
                'Cannot serialize unsupported activity import payload %s.',
                $value::class,
            ),
        );
    }

    public static function operationFailed(
        string $operation,
        \Throwable $previous,
    ): self {
        return new self(
            sprintf(
                'PostgreSQL activity import operation failed: %s.',
                $operation,
            ),
            previous: $previous,
        );
    }
}
