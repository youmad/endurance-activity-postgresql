<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Connection;

use Doctrine\DBAL\Connection;
use Youmad\Endurance\Activity\Application\Port\ActivityTransaction;

final readonly class DoctrineDbalActivityTransaction implements ActivityTransaction
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function run(\Closure $operation): mixed
    {
        return $this->connection->transactional(
            static fn (Connection $connection): mixed => $operation(),
        );
    }
}
