<?php

declare(strict_types=1);

namespace Youmad\Endurance\ActivityPostgresql\Read;

use Youmad\Endurance\Activity\Application\Read\ActivityScalarMeasurement;
use Youmad\Endurance\Activity\Telemetry\MeasurementOrigin;
use Youmad\Endurance\Activity\Telemetry\MeasurementSource;
use Youmad\Endurance\Activity\Telemetry\SourceAttribution;
use Youmad\Endurance\Activity\ValueObject\ActivityDeviceId;

final readonly class ActivityScalarMeasurementReadRowMapper
{
    /**
     * Summary read models retain the domain's unique-type contract.
     *
     * @return array<string, ActivityScalarMeasurement>
     */
    public function map(mixed $readings, string $subject): array
    {
        $measurements = [];
        foreach ($this->readings($readings, $subject) as $measurement) {
            if (isset($measurements[$measurement->type])) {
                throw new \UnexpectedValueException(sprintf('%s contains scalar measurement type %s more than once.', $subject, $measurement->type));
            }
            $measurements[$measurement->type] = $measurement;
        }

        return $measurements;
    }

    /**
     * @return list<ActivityScalarMeasurement>
     */
    public function readings(
        mixed $readings,
        string $subject,
    ): array {
        if (!is_array($readings) || !array_is_list($readings)) {
            throw new \UnexpectedValueException(sprintf('%s readings must be a JSON array.', $subject));
        }

        $measurements = [];

        foreach ($readings as $reading) {
            if (!is_array($reading) || array_is_list($reading)) {
                throw new \UnexpectedValueException(sprintf('%s reading must be a JSON object.', $subject));
            }

            $measurement = $reading['measurement'] ?? null;

            if (!is_array($measurement) || array_is_list($measurement)) {
                throw new \UnexpectedValueException(sprintf('%s reading must contain a measurement.', $subject));
            }

            if ('scalar' !== ($measurement['kind'] ?? null)) {
                continue;
            }

            $type = $measurement['type'] ?? null;
            $value = $measurement['value'] ?? null;
            $unit = $measurement['unit'] ?? null;

            if (
                !is_string($type)
                || !is_int($value) && !is_float($value)
                || !is_string($unit)
            ) {
                throw new \UnexpectedValueException(sprintf('%s scalar measurement has an invalid shape.', $subject));
            }

            $measurements[] = new ActivityScalarMeasurement(
                type: $type,
                value: $value,
                unit: $unit,
                origin: $this->origin($reading, $subject),
                source: $this->source($reading, $subject),
            );
        }

        return $measurements;
    }

    /** @param array<string, mixed> $reading */
    private function origin(array $reading, string $subject): ?MeasurementOrigin
    {
        // Older payloads may lack provenance; do not invent a reported origin.
        if (!array_key_exists('origin', $reading)) {
            return null;
        }
        $value = $reading['origin'];
        $origin = is_string($value) ? MeasurementOrigin::tryFrom($value) : null;

        return $origin ?? throw new \UnexpectedValueException(sprintf('%s scalar reading has an invalid origin.', $subject));
    }

    /** @param array<string, mixed> $reading */
    private function source(array $reading, string $subject): MeasurementSource
    {
        if (!array_key_exists('source', $reading)) {
            return MeasurementSource::unknown();
        }
        $source = $reading['source'];
        if (!is_array($source) || array_is_list($source)) {
            throw new \UnexpectedValueException(sprintf('%s scalar reading has an invalid source.', $subject));
        }
        $rawAttribution = $source['attribution'] ?? null;
        $attribution = is_string($rawAttribution) ? SourceAttribution::tryFrom($rawAttribution) : null;
        $deviceId = $source['device_id'] ?? null;
        if (
            null === $attribution
            || !array_key_exists('device_id', $source)
        ) {
            throw new \UnexpectedValueException(sprintf('%s scalar reading has an inconsistent source.', $subject));
        }
        if (SourceAttribution::Unknown === $attribution) {
            if (null !== $deviceId) {
                throw new \UnexpectedValueException(sprintf('%s unknown source must not name a device.', $subject));
            }

            return MeasurementSource::unknown();
        }

        if (!is_string($deviceId)) {
            throw new \UnexpectedValueException(sprintf('%s scalar reading requires a device ID.', $subject));
        }
        $id = ActivityDeviceId::fromString($deviceId);

        return SourceAttribution::Explicit === $attribution
            ? MeasurementSource::explicit($id)
            : MeasurementSource::inferred($id);
    }
}
