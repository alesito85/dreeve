<?php

declare(strict_types=1);

namespace App\Tests\Domain\Activity\Revision;

use App\Domain\Activity\ActivityId;
use App\Domain\Activity\Revision\ActivityRevisionSummary;
use App\Domain\Activity\Stream\ActivityStream;
use App\Domain\Activity\Stream\ActivityStreams;
use App\Domain\Activity\Stream\StreamType;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\Domain\Activity\ActivityBuilder;
use PHPUnit\Framework\TestCase;

class ActivityRevisionSummaryTest extends TestCase
{
    public function testFromActivityLeavesOutTheStreamThatIsRebuiltAfterEveryMerge(): void
    {
        $summary = ActivityRevisionSummary::fromActivity(
            ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed('the-activity'))
                ->build(),
            $this->streams([
                StreamType::TIME->value => [0, 1, 2],
                StreamType::WATTS->value => [200, 210, 220],
                StreamType::MOVING->value => [true, true, false],
            ]),
        );

        // MOVING is derived from the timeline, and a merge result never carries it,
        // so a version that included it would never compare equal to the state it
        // replaced and every re-import would add a version that changed nothing.
        $this->assertSame([
            StreamType::TIME->value => 3,
            StreamType::WATTS->value => 3,
        ], $summary->getStreamSamples());
    }

    public function testAVersionWrittenWhileTheMovingStreamWasStillStoredIsTheSameVersion(): void
    {
        $old = self::summaryWith([
            StreamType::TIME->value => 3,
            StreamType::MOVING->value => 3,
        ]);
        $new = self::summaryWith([StreamType::TIME->value => 3]);

        // History is already written on disk with MOVING in it, and it has to keep
        // comparing equal now that MOVING is gone.
        $this->assertTrue($old->equals($new));
        $this->assertSame([StreamType::TIME->value => 3], $new->getStreamSamples());
    }

    public function testItSaysWhatAVersionGainedComparedToTheOneBeforeIt(): void
    {
        $earlier = self::summaryWith([StreamType::TIME->value => 3]);
        $later = self::summaryWith([
            StreamType::TIME->value => 3,
            StreamType::WATTS->value => 3,
        ]);

        $this->assertSame([StreamType::WATTS], $later->streamTypesGainedComparedTo($earlier));
        $this->assertSame([], $earlier->streamTypesGainedComparedTo($later));
        // A series that is listed but holds nothing is nothing to show off.
        $this->assertSame([], $later->streamTypesGainedComparedTo(self::summaryWith([
            StreamType::TIME->value => 3,
            StreamType::WATTS->value => 0,
        ])));
    }

    /**
     * @param array<string, int> $streamSamples
     */
    private static function summaryWith(array $streamSamples): ActivityRevisionSummary
    {
        return ActivityRevisionSummary::fromArray([
            'startedOn' => '2023-10-10 12:00:00',
            'distanceInMetres' => 1200,
            'elapsedTimeInSeconds' => 300,
            'movingTimeInSeconds' => 290,
            'averagePower' => 187,
            'streamSamples' => $streamSamples,
        ]);
    }

    /**
     * @param array<string, array<int, mixed>> $valuesByStreamType
     */
    private function streams(array $valuesByStreamType): ActivityStreams
    {
        $streams = ActivityStreams::empty();
        foreach ($valuesByStreamType as $streamType => $data) {
            $streams->add(ActivityStream::fromState(
                activityId: ActivityId::fromUnprefixed('the-activity'),
                streamType: StreamType::from((string) $streamType),
                streamData: $data,
                createdOn: SerializableDateTime::fromString('2023-10-10 00:00:00'),
            ));
        }

        return $streams;
    }
}
