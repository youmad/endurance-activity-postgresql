<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Exception;

use Youmad\Endurance\Activity\Application\Import\ActivityImportFileCleanupClaim;

final class ActivityImportFileCleanupClaimLost extends \RuntimeException
{
    public static function forClaim(
        ActivityImportFileCleanupClaim $claim,
    ): self {
        return new self(sprintf(
            'Activity import file cleanup claim %s for import %s is no longer active.',
            $claim->claimId->toString(),
            $claim->importId->toString(),
        ));
    }
}
