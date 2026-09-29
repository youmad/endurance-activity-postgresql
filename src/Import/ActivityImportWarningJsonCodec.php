<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Import;

use Youmad\Endurance\Activity\Application\Import\ActivityImportWarning;
use Youmad\Endurance\Activity\Application\Import\ActivityImportWarningCode;

final readonly class ActivityImportWarningJsonCodec
{
    /**
     * @param list<ActivityImportWarning> $warnings
     */
    public function encode(array $warnings): string
    {
        try {
            return json_encode(
                array_map(
                    static fn (ActivityImportWarning $warning): array => [
                        'code' => $warning->code->value,
                        'message' => $warning->message,
                        'context' => (object) $warning->context,
                    ],
                    $warnings,
                ),
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Activity import warnings could not be encoded.', previous: $exception);
        }
    }

    /** @return list<ActivityImportWarning> */
    public function decode(string $payload): array
    {
        try {
            $decoded = json_decode(
                $payload,
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Stored activity import warnings are not valid JSON.', previous: $exception);
        }

        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw new \RuntimeException('Stored activity import warnings must be a JSON array.');
        }

        $warnings = [];

        foreach ($decoded as $warning) {
            if (
                !is_array($warning)
                || !is_string($warning['code'] ?? null)
                || !is_string($warning['message'] ?? null)
                || !is_array($warning['context'] ?? null)
            ) {
                throw new \RuntimeException('Stored activity import warning has an invalid shape.');
            }

            /** @var array<string, bool|float|int|string|null> $context */
            $context = $warning['context'];

            try {
                $warnings[] = new ActivityImportWarning(
                    code: ActivityImportWarningCode::from($warning['code']),
                    message: $warning['message'],
                    context: $context,
                );
            } catch (\InvalidArgumentException|\ValueError $exception) {
                throw new \RuntimeException('Stored activity import warning contains invalid values.', previous: $exception);
            }
        }

        return $warnings;
    }
}
