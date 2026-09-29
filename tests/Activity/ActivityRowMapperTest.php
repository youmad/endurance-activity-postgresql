<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Tests\Activity;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Entity\Activity;
use Youmad\Endurance\ActivityPostgresql\Activity\ActivityRowMapper;
use Youmad\Endurance\Foundation\ValueObject\Instant;

final class ActivityRowMapperTest extends TestCase
{
    public function testMapsStartedActivityInBothDirections(): void
    {
        $activity = Activity::start(
            $this->instant('2026-01-15T10:00:00.123456Z'),
        );
        $activity->pause(
            $this->instant('2026-01-15T10:01:00.654321Z'),
        );
        $mapper = new ActivityRowMapper();
        $parameters = $mapper->parameters($activity);

        $restored = $mapper->activity(
            $parameters + ['version' => 1],
        );

        self::assertTrue($activity->id->equals($restored->id));
        self::assertTrue(
            $activity->startedAt->equals($restored->startedAt),
        );
        self::assertTrue(
            $activity->pausedAt?->equals($restored->pausedAt),
        );
        self::assertSame(
            '{}',
            $parameters['last_sequential_detail_finished_at'],
        );
    }

    private function instant(string $value): Instant
    {
        return Instant::fromDateTimeImmutable(
            new \DateTimeImmutable($value),
        );
    }
}
