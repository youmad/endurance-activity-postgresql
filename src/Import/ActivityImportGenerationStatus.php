<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Import;

enum ActivityImportGenerationStatus: string
{
    case Staging = 'staging';
    case Active = 'active';
    case Failed = 'failed';
}
