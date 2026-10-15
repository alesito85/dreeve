<?php

declare(strict_types=1);

namespace App\Domain\Activity\Stream;

use App\Domain\Activity\ActivityId;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

/**
 * Lays the streams of a re-imported file over the ones an activity already has.
 *
 * The axis is the union of both files' seconds, not sample indexes, because the
 * two files rarely have the same samples: a file that stopped halfway has no
 * tail, a re-export can drop a field for a stretch, and the merged activity has
 * to keep both. A newer sample wins wherever it exists and a hole keeps the
 * stored value, which is what makes a re-import primarily an addition. Seconds
 * with no value are filled the way the parser fills them, by carrying the last
 * known value, so a merged stream reads like a parsed one.
 *
 * The axis stays sparse where the files were sparse. A file that recorded every
 * ten seconds merged with itself has to come out the shape it went in, or a
 * merge that changed nothing still rewrites every stream.
 */
final readonly class ActivityStreamMerger
{
    // The identity matcher only ever matched a file inside this window, so a
    // larger gap between the two starts means the clocks disagree rather than
    // the rides being apart. Aligning head to head is then the safe reading.
    private const int MAX_START_OFFSET_IN_SECONDS = 30;

    /**
     * @param ActivityId $activityId the activity being merged into, so the streams keep one owner
     */
    public function merge(
        ActivityId $activityId,
        ActivityStreams $existingStreams,
        SerializableDateTime $existingStart,
        ActivityStreams $incomingStreams,
        SerializableDateTime $incomingStart,
    ): ActivityStreams {
        $existingSeconds = $this->secondOffsetsFor($existingStreams);
        $incomingSeconds = $this->secondOffsetsFor($incomingStreams);
        $offsetForIncoming = $this->resolveOffsetForIncoming($existingStart, $incomingStart);
        $axis = $this->unionOfSeconds($existingSeconds, $incomingSeconds, $offsetForIncoming);

        $merged = ActivityStreams::empty();
        foreach ($this->streamTypesOnEitherSide($existingStreams, $incomingStreams) as $streamType) {
            if (StreamType::MOVING === $streamType) {
                // MOVING is derived from speed, so stitching two files would put
                // a flag against a sample the other file measured differently.
                // Dropping it here lets CalculateMovingStream rebuild it from the
                // merged timeline, which is the only version that is true.
                continue;
            }

            if (StreamType::TIME === $streamType) {
                // The axis is the timeline itself, so it is written as it was
                // built. Every other stream is read against it by index.
                $merged->add(ActivityStream::create(
                    activityId: $activityId,
                    streamType: $streamType,
                    streamData: $axis,
                    createdOn: $this->createdOnFor($existingStreams, $incomingStreams, $streamType, $existingStart),
                ));

                continue;
            }

            $existingValues = $this->valuesBySecond(
                $existingStreams->filterOnType($streamType),
                $existingSeconds,
                0,
            );
            $incomingValues = $this->valuesBySecond(
                $incomingStreams->filterOnType($streamType),
                $incomingSeconds,
                $offsetForIncoming,
            );
            $data = [];
            foreach ($axis as $second) {
                $data[] = $incomingValues[$second] ?? $existingValues[$second] ?? null;
            }
            $data = $this->carryKnownValuesForward($data);

            // The same rule the parsers use: a stream with nothing to show is
            // not stored.
            if ([] === array_filter($data)) {
                continue;
            }

            $merged->add(ActivityStream::create(
                activityId: $activityId,
                streamType: $streamType,
                streamData: $data,
                createdOn: $this->createdOnFor($existingStreams, $incomingStreams, $streamType, $existingStart),
            ));
        }

        return $merged;
    }

    /**
     * The seconds each sample sits at, from the TIME stream when there is one.
     *
     * @return array<int, int>
     */
    private function secondOffsetsFor(ActivityStreams $streams): array
    {
        $time = $streams->filterOnType(StreamType::TIME);
        if (!$time instanceof ActivityStream) {
            // No TIME stream means no clock, which leaves the sample index as
            // the only one there is. Parsers always write TIME.
            $length = 0;
            foreach ($streams as $stream) {
                $length = max($length, count($stream->getData()));
            }

            return 0 === $length ? [] : array_combine(range(0, $length - 1), range(0, $length - 1));
        }

        $seconds = [];
        foreach ($time->getData() as $index => $value) {
            $seconds[(int) $index] = (int) $value;
        }

        return $seconds;
    }

    /**
     * The seconds of both files, in order, without duplicates.
     *
     * @param array<int, int> $existingSeconds
     * @param array<int, int> $incomingSeconds
     *
     * @return list<int>
     */
    private function unionOfSeconds(array $existingSeconds, array $incomingSeconds, int $offsetInSeconds): array
    {
        $seconds = [];
        foreach ($existingSeconds as $second) {
            $seconds[$second] = true;
        }
        foreach ($incomingSeconds as $second) {
            $seconds[$second + $offsetInSeconds] = true;
        }
        $axis = [];
        foreach (array_keys($seconds) as $second) {
            if ($second < 0) {
                // A sample before the start the activity is stored at. It has
                // nowhere on the axis to go.
                continue;
            }

            $axis[] = $second;
        }
        sort($axis);

        return $axis;
    }

    private function resolveOffsetForIncoming(
        SerializableDateTime $existingStart,
        SerializableDateTime $incomingStart,
    ): int {
        $offset = $incomingStart->getTimestamp() - $existingStart->getTimestamp();

        return abs($offset) > self::MAX_START_OFFSET_IN_SECONDS ? 0 : $offset;
    }

    /**
     * The same carry ActivityStreamsMapper runs while parsing.
     *
     * A merged stream has to look like a parsed one, otherwise a chart that read
     * a carried value before the merge reads a hole after it.
     *
     * @param array<int, mixed> $data
     *
     * @return array<int, mixed>
     */
    private function carryKnownValuesForward(array $data): array
    {
        $previous = null;
        foreach ($data as $index => $value) {
            if (null !== $value) {
                if (null === $previous && $index > 0) {
                    // Nothing before this point had a value, so the first one
                    // there is reaches back to the start.
                    $data = array_replace($data, array_fill(0, $index, $value));
                }
                $previous = $value;

                continue;
            }

            $data[$index] = $previous;
        }

        return $data;
    }

    /**
     * @param array<int, int> $seconds
     *
     * @return array<int, mixed>
     */
    private function valuesBySecond(?ActivityStream $stream, array $seconds, int $offsetInSeconds): array
    {
        if (!$stream instanceof ActivityStream) {
            return [];
        }

        $values = [];
        foreach ($stream->getData() as $index => $value) {
            $second = ($seconds[(int) $index] ?? $index) + $offsetInSeconds;
            if ($second < 0) {
                // A sample before the start the activity is stored at. It has
                // nowhere on the axis to go.
                continue;
            }

            $values[$second] = $value;
        }

        return $values;
    }

    /**
     * @return list<StreamType>
     */
    private function streamTypesOnEitherSide(ActivityStreams ...$streams): array
    {
        $streamTypes = [];
        foreach ($streams as $streamCollection) {
            foreach ($streamCollection as $stream) {
                $streamTypes[$stream->getStreamType()->value] = $stream->getStreamType();
            }
        }

        return array_values($streamTypes);
    }

    private function createdOnFor(
        ActivityStreams $existingStreams,
        ActivityStreams $incomingStreams,
        StreamType $streamType,
        SerializableDateTime $existingStart,
    ): SerializableDateTime {
        foreach ([$existingStreams, $incomingStreams] as $streams) {
            if (($stream = $streams->filterOnType($streamType)) instanceof ActivityStream) {
                return $stream->getCreatedOn();
            }
        }

        return $existingStart;
    }
}
