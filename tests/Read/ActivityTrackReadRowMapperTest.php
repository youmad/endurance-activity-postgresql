<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Tests\Read;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\ActivityPostgresql\Read\ActivityTrackReadRowMapper;

final class ActivityTrackReadRowMapperTest extends TestCase
{
    public function testMapsSupportedTrackMeasurements(): void
    {
        $page = (new ActivityTrackReadRowMapper())->page(
            activityId: ActivityId::generate(),
            rows: [
                [
                    'id' => '17',
                    'observed_at' => '2026-08-05 10:00:01+00',
                    'payload' => json_encode([
                        'timestamp' => '2026-08-05T10:00:01.000000Z',
                        'readings' => [
                            [
                                'measurement' => [
                                    'kind' => 'position',
                                    'type' => 'position',
                                    'coordinate' => [
                                        'latitude' => 59.4369,
                                        'longitude' => 24.7535,
                                    ],
                                ],
                            ],
                            [
                                'measurement' => [
                                    'kind' => 'scalar',
                                    'type' => 'speed',
                                    'value' => 8.75,
                                    'unit' => 'm/s',
                                ],
                            ],
                            [
                                'measurement' => [
                                    'kind' => 'scalar',
                                    'type' => 'heart_rate',
                                    'value' => 142,
                                    'unit' => 'bpm',
                                ],
                            ],
                            [
                                'measurement' => [
                                    'kind' => 'scalar',
                                    'type' => 'unsupported_metric',
                                    'value' => 1,
                                    'unit' => '1',
                                ],
                            ],
                        ],
                    ], JSON_THROW_ON_ERROR),
                ],
            ],
            limit: 100,
        );

        self::assertSame(1, $page->count());
        $point = $page->points()[0];
        self::assertSame(17, $point->cursor->observationId);
        self::assertSame(59.4369, $point->position?->latitude);
        self::assertSame(8.75, $point->speed?->value);
        self::assertSame('m/s', $point->speed->unit);
        self::assertSame(142, $point->heartRate?->value);
        self::assertSame(
            ['speed', 'heart_rate', 'unsupported_metric'],
            array_map(
                static fn ($measurement): string => $measurement->type,
                $point->measurements(),
            ),
        );
        self::assertSame(
            1,
            $point->measurements()[2]->value,
        );
        self::assertNull($page->nextCursor);
    }

    public function testUsesFinalVisiblePointAsNextCursor(): void
    {
        $rows = [];

        foreach ([1, 2, 3] as $id) {
            $timestamp = sprintf(
                '2026-08-05T10:00:0%d.000000Z',
                $id,
            );
            $rows[] = [
                'id' => $id,
                'observed_at' => $timestamp,
                'payload' => json_encode([
                    'timestamp' => $timestamp,
                    'readings' => [
                        [
                            'measurement' => [
                                'kind' => 'scalar',
                                'type' => 'power',
                                'value' => 180 + $id,
                                'unit' => 'W',
                            ],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR),
            ];
        }

        $page = (new ActivityTrackReadRowMapper())->page(
            activityId: ActivityId::generate(),
            rows: $rows,
            limit: 2,
        );

        self::assertSame(2, $page->count());
        self::assertSame(2, $page->nextCursor?->observationId);
    }

    public function testRejectsPayloadTimestampMismatch(): void
    {
        $this->expectException(\UnexpectedValueException::class);

        (new ActivityTrackReadRowMapper())->page(
            activityId: ActivityId::generate(),
            rows: [
                [
                    'id' => 1,
                    'observed_at' => '2026-08-05T10:00:00Z',
                    'payload' => json_encode([
                        'timestamp' => '2026-08-05T10:00:01.000000Z',
                        'readings' => [],
                    ], JSON_THROW_ON_ERROR),
                ],
            ],
            limit: 100,
        );
    }
}
