<?php

declare(strict_types=1);

namespace App\Domain\Activity\Revision;

use App\Domain\Activity\Activity;
use App\Domain\Activity\Stream\ActivityStream;
use App\Domain\Activity\Stream\ActivityStreams;
use App\Domain\Activity\Stream\StreamType;
use App\Infrastructure\Measurement\Length\Kilometer;
use App\Infrastructure\Measurement\Length\Meter;
use App\Infrastructure\Measurement\Time\Seconds;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

/**
 * What an activity looked like before a merge replaced it.
 *
 * The numbers and an inventory of the streams, not the streams themselves. The
 * bytes that produced a version are already stored on the FileImport row that
 * imported them, so a version needs a description to be readable and the file
 * to be restorable, not a second copy of every sample.
 */
final readonly class ActivityRevisionSummary implements \JsonSerializable
{
    /**
     * @param array<string, int> $streamSamples stream type value => stored sample count
     */
    public function __construct(
        private SerializableDateTime $startedOn,
        private int $distanceInMetres,
        private int $elevationInMetres,
        private int $elapsedTimeInSeconds,
        private int $movingTimeInSeconds,
        private ?int $kilojoules,
        private ?int $averagePower,
        private ?int $maxPower,
        private ?int $averageHeartRate,
        private ?int $maxHeartRate,
        private float $averageSpeedInKmPerHour,
        private float $maxSpeedInKmPerHour,
        private array $streamSamples,
    ) {
    }

    public static function fromActivity(Activity $activity, ActivityStreams $streams): self
    {
        $streamSamples = [];
        foreach (StreamType::cases() as $streamType) {
            if (StreamType::MOVING === $streamType) {
                // MOVING is rebuilt from the timeline after every merge, so it
                // tells you nothing about a version. It also lives in the stored
                // streams but never in a merge result, which would make every
                // merge look like a change, even one that changed nothing.
                continue;
            }

            $stream = $streams->filterOnType($streamType);
            if (!$stream instanceof ActivityStream) {
                continue;
            }

            $streamSamples[$streamType->value] = count($stream->getData());
        }

        return new self(
            startedOn: $activity->getStartDate(),
            distanceInMetres: $activity->getDistance()->toMeter()->toInt(),
            elevationInMetres: $activity->getElevation()->toInt(),
            elapsedTimeInSeconds: $activity->getElapsedTimeInSeconds(),
            movingTimeInSeconds: $activity->getMovingTimeInSeconds(),
            kilojoules: $activity->getKilojoules(),
            averagePower: $activity->getAveragePower(),
            maxPower: $activity->getMaxPower(),
            averageHeartRate: $activity->getAverageHeartRate(),
            maxHeartRate: $activity->getMaxHeartRate(),
            averageSpeedInKmPerHour: $activity->getAverageSpeed()->toFloat(),
            maxSpeedInKmPerHour: $activity->getMaxSpeed()->toFloat(),
            streamSamples: $streamSamples,
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        $streamSamples = [];
        if (is_array($values['streamSamples'] ?? null)) {
            foreach ($values['streamSamples'] as $streamType => $sampleCount) {
                if (StreamType::MOVING->value === (string) $streamType) {
                    // Versions written before MOVING was dropped still carry it,
                    // and have to compare equal to the ones written after.
                    continue;
                }

                $streamSamples[(string) $streamType] = (int) $sampleCount;
            }
        }

        $optionalFloat = static fn (mixed $value): float => is_numeric($value) ? (float) $value : 0.0;
        $optionalInt = static fn (mixed $value): ?int => is_numeric($value) ? (int) $value : null;

        return new self(
            startedOn: SerializableDateTime::fromString((string) ($values['startedOn'] ?? 'now')),
            distanceInMetres: (int) ($values['distanceInMetres'] ?? 0),
            elevationInMetres: (int) ($values['elevationInMetres'] ?? 0),
            elapsedTimeInSeconds: (int) ($values['elapsedTimeInSeconds'] ?? 0),
            movingTimeInSeconds: (int) ($values['movingTimeInSeconds'] ?? 0),
            kilojoules: $optionalInt($values['kilojoules'] ?? null),
            averagePower: $optionalInt($values['averagePower'] ?? null),
            maxPower: $optionalInt($values['maxPower'] ?? null),
            averageHeartRate: $optionalInt($values['averageHeartRate'] ?? null),
            maxHeartRate: $optionalInt($values['maxHeartRate'] ?? null),
            averageSpeedInKmPerHour: $optionalFloat($values['averageSpeedInKmPerHour'] ?? 0),
            maxSpeedInKmPerHour: $optionalFloat($values['maxSpeedInKmPerHour'] ?? 0),
            streamSamples: $streamSamples,
        );
    }

    public static function fromJson(mixed $json): ?self
    {
        if (!is_string($json) || '' === $json) {
            return null;
        }

        $decoded = Json::decode($json);
        if (!is_array($decoded)) {
            return null;
        }

        return self::fromArray($decoded);
    }

    /**
     * True when nothing about the activity changed. A merge that adds nothing
     * must not put a version in the history that a user then has to read.
     */
    public function equals(self $other): bool
    {
        return $this->jsonSerialize() === $other->jsonSerialize();
    }

    public function getStartedOn(): SerializableDateTime
    {
        return $this->startedOn;
    }

    public function getDistance(): Kilometer
    {
        return Kilometer::from($this->distanceInMetres / 1000);
    }

    public function getDistanceInMetres(): int
    {
        return $this->distanceInMetres;
    }

    public function getElevation(): Meter
    {
        return Meter::from($this->elevationInMetres);
    }

    public function getElevationInMetres(): int
    {
        return $this->elevationInMetres;
    }

    public function getElapsedTime(): Seconds
    {
        return Seconds::from($this->elapsedTimeInSeconds);
    }

    public function getElapsedTimeInSeconds(): int
    {
        return $this->elapsedTimeInSeconds;
    }

    public function getMovingTime(): Seconds
    {
        return Seconds::from($this->movingTimeInSeconds);
    }

    public function getMovingTimeInSeconds(): int
    {
        return $this->movingTimeInSeconds;
    }

    public function getKilojoules(): ?int
    {
        return $this->kilojoules;
    }

    public function getAveragePower(): ?int
    {
        return $this->averagePower;
    }

    public function getMaxPower(): ?int
    {
        return $this->maxPower;
    }

    public function getAverageHeartRate(): ?int
    {
        return $this->averageHeartRate;
    }

    public function getMaxHeartRate(): ?int
    {
        return $this->maxHeartRate;
    }

    public function getAverageSpeedInKmPerHour(): float
    {
        return $this->averageSpeedInKmPerHour;
    }

    public function getMaxSpeedInKmPerHour(): float
    {
        return $this->maxSpeedInKmPerHour;
    }

    /**
     * @return array<string, int>
     */
    public function getStreamSamples(): array
    {
        return $this->streamSamples;
    }

    /**
     * The stream types a version has that another one does not.
     *
     * @return list<StreamType>
     */
    public function streamTypesGainedComparedTo(self $other): array
    {
        $gained = [];
        foreach ($this->streamSamples as $streamType => $sampleCount) {
            if (0 !== $sampleCount && !array_key_exists($streamType, $other->streamSamples)) {
                $gained[] = StreamType::from($streamType);
            }
        }

        return $gained;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'startedOn' => $this->startedOn->iso(),
            'distanceInMetres' => $this->distanceInMetres,
            'elevationInMetres' => $this->elevationInMetres,
            'elapsedTimeInSeconds' => $this->elapsedTimeInSeconds,
            'movingTimeInSeconds' => $this->movingTimeInSeconds,
            'kilojoules' => $this->kilojoules,
            'averagePower' => $this->averagePower,
            'maxPower' => $this->maxPower,
            'averageHeartRate' => $this->averageHeartRate,
            'maxHeartRate' => $this->maxHeartRate,
            'averageSpeedInKmPerHour' => $this->averageSpeedInKmPerHour,
            'maxSpeedInKmPerHour' => $this->maxSpeedInKmPerHour,
            'streamSamples' => $this->streamSamples,
        ];
    }
}
