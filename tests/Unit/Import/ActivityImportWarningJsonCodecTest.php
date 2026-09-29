<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Tests\Unit\Import;

use PHPUnit\Framework\TestCase;
use Youmad\Endurance\Activity\Application\Import\ActivityImportWarning;
use Youmad\Endurance\Activity\Application\Import\ActivityImportWarningCode;
use Youmad\Endurance\ActivityPostgresql\Import\ActivityImportWarningJsonCodec;

final class ActivityImportWarningJsonCodecTest extends TestCase
{
    public function testRoundTripsWarnings(): void
    {
        $codec = new ActivityImportWarningJsonCodec();
        $warnings = [
            new ActivityImportWarning(
                code: ActivityImportWarningCode::ActivityTimerMismatch,
                message: 'Activity timer differs from session timing.',
                context: ['differenceMicroseconds' => 10],
            ),
        ];

        $decoded = $codec->decode($codec->encode($warnings));

        self::assertCount(1, $decoded);
        self::assertSame($warnings[0]->code, $decoded[0]->code);
        self::assertSame($warnings[0]->context, $decoded[0]->context);
    }

    public function testRejectsUnknownWarningCode(): void
    {
        $this->expectException(\RuntimeException::class);

        (new ActivityImportWarningJsonCodec())->decode(
            '[{"code":"unknown","message":"Unknown.","context":{}}]',
        );
    }
}
