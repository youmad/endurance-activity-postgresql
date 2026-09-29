<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Read;

use Youmad\Endurance\Activity\ValueObject\SummaryAdjacency;
use Youmad\Endurance\Activity\ValueObject\SummaryAdjacencyPolicy;
use Youmad\Endurance\Foundation\ValueObject\TemporalResolution;

final class SummaryAdjacencyPolicyReadMapper
{
    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload, TemporalResolution $resolution): SummaryAdjacencyPolicy
    {
        if (!array_key_exists('adjacency_policy', $payload)) {
            return SummaryAdjacency::legacyPolicy($resolution);
        }

        $value = $payload['adjacency_policy'];
        if (!is_string($value)) {
            throw new \UnexpectedValueException('Summary adjacency policy must be a string.');
        }

        return SummaryAdjacencyPolicy::tryFrom($value)
            ?? throw new \UnexpectedValueException('Unsupported summary adjacency policy: '.$value);
    }
}
