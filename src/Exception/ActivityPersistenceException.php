<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Exception;

use Youmad\Endurance\Activity\ValueObject\ActivityId;

final class ActivityPersistenceException extends \RuntimeException
{
    public static function notFound(ActivityId $activityId): self
    {
        return new self(
            sprintf(
                'Activity %s does not exist.',
                $activityId->toString(),
            ),
        );
    }

    public static function alreadyExists(
        ActivityId $activityId,
        ?\Throwable $previous = null,
    ): self {
        return new self(
            sprintf(
                'Activity %s already exists.',
                $activityId->toString(),
            ),
            previous: $previous,
        );
    }

    public static function concurrentlyModified(
        ActivityId $activityId,
    ): self {
        return new self(
            sprintf(
                'Activity %s was modified concurrently.',
                $activityId->toString(),
            ),
        );
    }

    public static function invalidStoredState(
        ActivityId $activityId,
        \Throwable $previous,
    ): self {
        return new self(
            sprintf(
                'Stored state of activity %s is invalid.',
                $activityId->toString(),
            ),
            previous: $previous,
        );
    }

    public static function operationFailed(
        string $operation,
        \Throwable $previous,
    ): self {
        return new self(
            sprintf(
                'PostgreSQL activity operation failed: %s.',
                $operation,
            ),
            previous: $previous,
        );
    }
}
