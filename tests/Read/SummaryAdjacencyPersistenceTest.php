<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Tests\Read;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Entity\Activity;
use Youmad\Endurance\Activity\Exception\CannotRecordActivitySession;
use Youmad\Endurance\Activity\Exception\CannotRecordLap;
use Youmad\Endurance\Activity\Session\ActivitySession;
use Youmad\Endurance\Activity\ValueObject\ActivityId;
use Youmad\Endurance\Activity\ValueObject\Lap;
use Youmad\Endurance\Activity\ValueObject\SummaryAdjacencyPolicy;
use Youmad\Endurance\ActivityPostgresql\Read\ActivityLapReadRowMapper;
use Youmad\Endurance\ActivityPostgresql\Read\ActivityReadRowMapper;
use Youmad\Endurance\ActivityPostgresql\Read\SummaryAdjacencyPolicyReadMapper;
use Youmad\Endurance\ActivityPostgresql\Serialization\ActivityImportPayloadEncoder;
use Youmad\Endurance\Foundation\ValueObject\Duration;
use Youmad\Endurance\Foundation\ValueObject\Instant;
use Youmad\Endurance\Foundation\ValueObject\TemporalResolution;

final class SummaryAdjacencyPersistenceTest extends TestCase
{
    /** @return iterable<string, array{bool, TemporalResolution, SummaryAdjacencyPolicy, int, bool}> */
    public static function policies(): iterable
    {
        foreach ([false, true] as $lap) {
            $kind = $lap ? 'lap' : 'session';
            yield $kind.' second precision with strict gap' => [$lap, TemporalResolution::Second, SummaryAdjacencyPolicy::NonOverlapping, 20, true];
            yield $kind.' second precision with strict overlap' => [$lap, TemporalResolution::Second, SummaryAdjacencyPolicy::NonOverlapping, 9, false];
            yield $kind.' microsecond precision with abutting overlap' => [$lap, TemporalResolution::Microsecond, SummaryAdjacencyPolicy::AbutWithinTwoWholeSeconds, 9, true];
            yield $kind.' microsecond precision with abutting gap' => [$lap, TemporalResolution::Microsecond, SummaryAdjacencyPolicy::AbutWithinTwoWholeSeconds, 20, false];
        }
    }

    #[DataProvider('policies')]
    public function testWriteAndReadUsePersistedPolicy(
        bool $lap,
        TemporalResolution $resolution,
        SummaryAdjacencyPolicy $policy,
        int $nextStart,
        bool $allowed,
    ): void {
        $factory = fn (int $start, int $finish) => $lap
            ? Lap::create($this->at($start), $this->at($finish), Duration::fromMicroseconds(1_000_000), adjacencyPolicy: $policy, timelineResolution: $resolution)
            : ActivitySession::create($this->at($start), $this->at($finish), Duration::fromMicroseconds(1_000_000), adjacencyPolicy: $policy, timelineResolution: $resolution);
        $summaries = [$factory(0, 10), $factory($nextStart, 30)];
        $activity = Activity::start($this->at(0));
        try {
            foreach ($summaries as $summary) {
                if ($summary instanceof Lap) {
                    $activity->recordLap($summary);
                } else {
                    $activity->recordSession($summary);
                }
            }
            self::assertTrue($allowed, 'Write side must reject this sequence.');
        } catch (CannotRecordLap|CannotRecordActivitySession) {
            self::assertFalse($allowed, 'Write side must accept this sequence.');
        }

        $encoder = new ActivityImportPayloadEncoder();
        $rows = [];
        foreach ($summaries as $summary) {
            $json = $summary instanceof Lap ? $encoder->lap($summary) : $encoder->session($summary);
            $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($policy->value, $payload['adjacency_policy']);
            $rows[] = [
                'started_at' => $encoder->instant($summary->startedAt),
                'finished_at' => $encoder->instant($summary->finishedAt),
                'timer_duration_microseconds' => 1_000_000,
                'payload' => $json,
            ];
        }

        try {
            if ($lap) {
                $restored = (new ActivityLapReadRowMapper())->laps(ActivityId::generate(), $rows)->laps();
            } else {
                $restored = (new ActivityReadRowMapper())->activity([
                    'id' => ActivityId::generate()->toString(),
                    'started_at' => $encoder->instant($this->at(0)),
                    'finished_at' => $encoder->instant($this->at(30)),
                    'activity_type' => null,
                    'local_time_offset_seconds' => null,
                    'session_count' => 2,
                    'recorded_session_timer_duration_microseconds' => 2_000_000,
                    'accumulated_paused_duration_microseconds' => 0,
                ], $rows)->sessions();
            }
            self::assertTrue($allowed, 'Read side must reject this sequence.');
            foreach ($restored as $summary) {
                self::assertSame($policy, $summary->adjacencyPolicy);
                self::assertSame($resolution, $summary->timelineResolution);
            }
        } catch (\InvalidArgumentException) {
            self::assertFalse($allowed, 'Read side must accept this sequence.');
        }
    }

    public function testLegacyPayloadUsesResolutionOnlyWhenPolicyIsAbsent(): void
    {
        self::assertSame(SummaryAdjacencyPolicy::NonOverlapping, SummaryAdjacencyPolicyReadMapper::fromPayload([], TemporalResolution::Microsecond));
        self::assertSame(SummaryAdjacencyPolicy::AbutWithinTwoWholeSeconds, SummaryAdjacencyPolicyReadMapper::fromPayload([], TemporalResolution::Second));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidPolicies(): iterable
    {
        yield 'null' => [null];
        yield 'integer' => [1];
        yield 'array' => [[]];
        yield 'unknown' => ['future_policy'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidPolicies')]
    public function testMalformedPresentPolicyIsNotTreatedAsLegacy(mixed $value): void
    {
        $this->expectException(\UnexpectedValueException::class);
        SummaryAdjacencyPolicyReadMapper::fromPayload(['adjacency_policy' => $value], TemporalResolution::Second);
    }

    private function at(int $seconds): Instant
    {
        return Instant::fromDateTimeImmutable(new \DateTimeImmutable(sprintf('2026-09-27T10:00:%02dZ', $seconds)));
    }
}
