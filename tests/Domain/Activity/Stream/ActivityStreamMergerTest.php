<?php

declare(strict_types=1);

namespace App\Tests\Domain\Activity\Stream;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\Stream\ActivityStream;
use App\Domain\Activity\Stream\ActivityStreamMerger;
use App\Domain\Activity\Stream\ActivityStreams;
use App\Domain\Activity\Stream\StreamType;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use PHPUnit\Framework\TestCase;

class ActivityStreamMergerTest extends TestCase
{
    public function testItLetsTheNewerSampleWinAndKeepsWhatTheHoleHad(): void
    {
        $merged = $this->merge(
            existing: $this->streams([
                StreamType::TIME->value => [0, 1, 2, 3],
                StreamType::WATTS->value => [100, 200, null, 400],
            ]),
            incoming: $this->streams([
                StreamType::TIME->value => [0, 1, 2, 3],
                StreamType::WATTS->value => [null, 250, null, null],
            ]),
        );

        // Second 1 takes the newer sample, second 3 keeps the stored one because
        // the new file had a hole there, and second 2 carries the last known
        // value the way the parser would have.
        $this->assertSame([0, 1, 2, 3], $this->dataFor($merged, StreamType::TIME));
        $this->assertSame([100, 250, 250, 400], $this->dataFor($merged, StreamType::WATTS));
    }

    public function testItAlignsTheIncomingSamplesOnTheirOwnStartInstant(): void
    {
        // A device that stamps its first record three seconds later must not
        // slide the whole file three seconds into the stored timeline.
        $merged = $this->merge(
            existing: $this->streams([
                StreamType::TIME->value => [0, 1, 2],
                StreamType::WATTS->value => [10, 20, 30],
            ]),
            incoming: $this->streams([
                StreamType::TIME->value => [0, 1],
                StreamType::WATTS->value => [200, 210],
            ]),
            incomingStart: SerializableDateTime::fromString('2023-10-10 12:00:03'),
        );

        $this->assertSame([0, 1, 2, 3, 4], $this->dataFor($merged, StreamType::TIME));
        $this->assertSame([10, 20, 30, 200, 210], $this->dataFor($merged, StreamType::WATTS));
    }

    public function testItAlignsOnTheSecondsOfTheTimeStreamAndNotOnTheSampleIndex(): void
    {
        $merged = $this->merge(
            existing: $this->streams([
                StreamType::TIME->value => [0, 2, 4],
                StreamType::WATTS->value => [1, 2, 3],
            ]),
            incoming: $this->streams([
                StreamType::TIME->value => [0, 1],
                StreamType::WATTS->value => [10, 20],
            ]),
        );

        // The axis is both files' seconds, so the second 3 neither file measured
        // is not invented just because it sits between two that were.
        $this->assertSame([0, 1, 2, 4], $this->dataFor($merged, StreamType::TIME));
        $this->assertSame([10, 20, 2, 3], $this->dataFor($merged, StreamType::WATTS));
    }

    public function testATruncatedFileKeepsTheTailTheActivityAlreadyHad(): void
    {
        $merged = $this->merge(
            existing: $this->streams([
                StreamType::TIME->value => [0, 1, 2, 3, 4],
                StreamType::WATTS->value => [1, 2, 3, 4, 5],
            ]),
            incoming: $this->streams([
                StreamType::TIME->value => [0, 1],
                StreamType::WATTS->value => [9, 9],
            ]),
        );

        $this->assertSame([0, 1, 2, 3, 4], $this->dataFor($merged, StreamType::TIME));
        $this->assertSame([9, 9, 3, 4, 5], $this->dataFor($merged, StreamType::WATTS));
    }

    public function testAFullerFileAppendsTheTailThatWasMissing(): void
    {
        $merged = $this->merge(
            existing: $this->streams([
                StreamType::TIME->value => [0, 1],
                StreamType::WATTS->value => [1, 2],
            ]),
            incoming: $this->streams([
                StreamType::TIME->value => [0, 1, 2, 3],
                StreamType::WATTS->value => [10, 20, 30, 40],
            ]),
        );

        $this->assertSame([0, 1, 2, 3], $this->dataFor($merged, StreamType::TIME));
        $this->assertSame([10, 20, 30, 40], $this->dataFor($merged, StreamType::WATTS));
    }

    public function testItPutsAStreamOnlyTheNewFileHasOnTheUnionAxis(): void
    {
        // The per-side pedal smoothness case: the stored activity never saw the
        // stream, so it starts where the new samples are and is empty elsewhere.
        $merged = $this->merge(
            existing: $this->streams([
                StreamType::TIME->value => [0, 1, 2, 3],
                StreamType::WATTS->value => [100, 200, 300, 400],
            ]),
            incoming: $this->streams([
                StreamType::TIME->value => [0, 1],
                StreamType::LEFT_PEDAL_SMOOTHNESS->value => [30, 31],
            ]),
        );

        // The samples sit at their own seconds and the rest carries, which is
        // exactly how this file's own two samples would have read on their own.
        $this->assertSame([30, 31, 31, 31], $this->dataFor($merged, StreamType::LEFT_PEDAL_SMOOTHNESS));
    }

    public function testItKeepsAStreamOnlyTheActivityHas(): void
    {
        $merged = $this->merge(
            existing: $this->streams([
                StreamType::TIME->value => [0, 1],
                StreamType::CADENCE->value => [80, 81],
            ]),
            incoming: $this->streams([
                StreamType::TIME->value => [0, 1, 2, 3],
                StreamType::WATTS->value => [10, 20, 30, 40],
            ]),
        );

        // Kept, and carried over the seconds the new file alone measured, so the
        // stream does not end in holes the stored activity never had.
        $this->assertSame([80, 81, 81, 81], $this->dataFor($merged, StreamType::CADENCE));
    }

    public function testItReachesBackToTheStartForAStreamThatOnlyReportsLater(): void
    {
        $merged = $this->merge(
            existing: $this->streams([
                StreamType::TIME->value => [0, 1, 2, 3],
                StreamType::TEMP->value => [null, null, 21, 22],
            ]),
            incoming: $this->streams([
                StreamType::TIME->value => [0, 1, 2, 3],
            ]),
        );

        $this->assertSame([21, 21, 21, 22], $this->dataFor($merged, StreamType::TEMP));
    }

    public function testAMergeThatAddsNothingLeavesTheStreamsExactlyAsTheyWere(): void
    {
        // A file that says the same thing must not rewrite the streams into
        // another shape, that would put a version in the history for nothing.
        $merged = $this->merge(
            existing: $this->streams([
                StreamType::TIME->value => [0, 10],
                StreamType::WATTS->value => [200, 210],
            ]),
            incoming: $this->streams([
                StreamType::TIME->value => [0, 10],
                StreamType::WATTS->value => [200, 210],
            ]),
        );

        $this->assertSame([0, 10], $this->dataFor($merged, StreamType::TIME));
        $this->assertSame([200, 210], $this->dataFor($merged, StreamType::WATTS));
    }

    public function testItDropsTheMovingStreamSoItGetsRebuilt(): void
    {
        // MOVING is derived from speed, so the version that is true is the one
        // CalculateMovingStream reads off the merged timeline.
        $merged = $this->merge(
            existing: $this->streams([
                StreamType::TIME->value => [0, 1],
                StreamType::MOVING->value => [true, true],
            ]),
            incoming: $this->streams([
                StreamType::TIME->value => [0, 1],
                StreamType::MOVING->value => [true, false],
            ]),
        );

        $this->assertNull($this->dataFor($merged, StreamType::MOVING));
        $this->assertSame([0, 1], $this->dataFor($merged, StreamType::TIME));
    }

    public function testItAlignsHeadToHeadWhenTheStartsDisagreeByMoreThanTheMatchWindow(): void
    {
        // Matching already decided these are the same ride, so a start two
        // minutes apart is a clock that was set wrong, not a two minute gap.
        $merged = $this->merge(
            existing: $this->streams([
                StreamType::TIME->value => [0, 1],
                StreamType::WATTS->value => [10, 20],
            ]),
            incoming: $this->streams([
                StreamType::TIME->value => [0, 1],
                StreamType::WATTS->value => [30, 40],
            ]),
            incomingStart: SerializableDateTime::fromString('2023-10-10 12:02:00'),
        );

        $this->assertSame([0, 1], $this->dataFor($merged, StreamType::TIME));
        $this->assertSame([30, 40], $this->dataFor($merged, StreamType::WATTS));
    }

    public function testItDoesNotStoreAStreamWithNothingToShow(): void
    {
        $merged = $this->merge(
            existing: $this->streams([
                StreamType::TIME->value => [0, 1],
                StreamType::WATTS->value => [null, null],
            ]),
            incoming: $this->streams([
                StreamType::TIME->value => [0, 1],
            ]),
        );

        $this->assertNull($this->dataFor($merged, StreamType::WATTS));
        $this->assertSame([0, 1], $this->dataFor($merged, StreamType::TIME));
    }

    public function testItRekeysEveryStreamToTheActivityBeingMergedInto(): void
    {
        $merged = $this->merge(
            existing: $this->streams([
                StreamType::TIME->value => [0, 1],
            ]),
            incoming: $this->streams([
                StreamType::TIME->value => [0, 1],
                StreamType::WATTS->value => [10, 20],
            ]),
        );

        foreach ($merged as $stream) {
            // The parser made these with the id of an activity that never gets
            // stored, so they have to come out owned by the existing activity.
            $this->assertEquals(ActivityId::fromUnprefixed('merge-target'), $stream->getActivityId());
        }
    }

    public function testItKeepsWhenTheStreamWasFirstStored(): void
    {
        $merged = $this->merge(
            existing: $this->streams([
                StreamType::TIME->value => [0, 1],
                StreamType::WATTS->value => [10, 20],
            ], createdOn: '2023-10-10 00:00:00'),
            incoming: $this->streams([
                StreamType::TIME->value => [0, 1],
                StreamType::WATTS->value => [30, 40],
                StreamType::CADENCE->value => [80, 81],
            ], createdOn: '2023-10-11 00:00:00'),
        );

        $this->assertEquals(
            SerializableDateTime::fromString('2023-10-10 00:00:00'),
            $merged->filterOnType(StreamType::WATTS)?->getCreatedOn(),
        );
        // A stream the activity did not have yet is as old as the file that
        // finally brought it, not as old as the activity.
        $this->assertEquals(
            SerializableDateTime::fromString('2023-10-11 00:00:00'),
            $merged->filterOnType(StreamType::CADENCE)?->getCreatedOn(),
        );
    }

    private function merge(
        ActivityStreams $existing,
        ActivityStreams $incoming,
        ?SerializableDateTime $incomingStart = null,
    ): ActivityStreams {
        return new ActivityStreamMerger()->merge(
            activityId: ActivityId::fromUnprefixed('merge-target'),
            existingStreams: $existing,
            existingStart: SerializableDateTime::fromString('2023-10-10 12:00:00'),
            incomingStreams: $incoming,
            incomingStart: $incomingStart ?? SerializableDateTime::fromString('2023-10-10 12:00:00'),
        );
    }

    /**
     * @param array<string, array<int, mixed>> $valuesByStreamType
     */
    private function streams(array $valuesByStreamType, string $createdOn = '2023-10-10 00:00:00'): ActivityStreams
    {
        $streams = ActivityStreams::empty();
        foreach ($valuesByStreamType as $streamType => $data) {
            $streams->add(ActivityStream::fromState(
                activityId: ActivityId::fromUnprefixed('parsed-file'),
                streamType: StreamType::from($streamType),
                streamData: $data,
                createdOn: SerializableDateTime::fromString($createdOn),
            ));
        }

        return $streams;
    }

    /**
     * @return array<int, mixed>|null
     */
    private function dataFor(ActivityStreams $streams, StreamType $streamType): ?array
    {
        return $streams->filterOnType($streamType)?->getData();
    }
}
