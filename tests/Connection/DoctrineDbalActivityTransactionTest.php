<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Tests\Connection;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Youmad\Endurance\ActivityPostgresql\Connection\DoctrineDbalActivityTransaction;

final class DoctrineDbalActivityTransactionTest extends TestCase
{
    public function testDelegatesTransactionBoundaryToDoctrineDbal(): void
    {
        $expected = bin2hex(random_bytes(8));
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('transactional')
            ->willReturnCallback(
                static fn (callable $operation): mixed => $operation($connection),
            );
        $transaction = new DoctrineDbalActivityTransaction(
            $connection,
        );

        $result = $transaction->run(
            static fn (): string => $expected,
        );

        self::assertSame($expected, $result);
        self::assertSame($connection, $transaction->connection());
    }
}
