<?php

declare(strict_types=1);

namespace App\Tests\Domain\Import\FileParser;

use App\Domain\Activity\SportType\SportType;
use App\Domain\Activity\Stream\StreamType;
use App\Domain\Import\FileParser\ActivityLapsMapper;
use App\Domain\Import\FileParser\ActivityStreamsMapper;
use App\Domain\Import\FileParser\CouldNotParseActivityFile;
use App\Domain\Import\FileParser\RawActivityFile;
use App\Domain\Import\FileParser\TcxFileParser;
use App\Domain\Import\SupportedFileExtension;
use App\Infrastructure\ValueObject\String\Path;
use App\Infrastructure\ValueObject\Time\SerializableTimezone;
use App\Tests\Domain\Activity\IncrementingActivityIdFactory;
use App\Tests\Domain\Activity\Lap\IncrementingActivityLapIdFactory;
use App\Tests\Infrastructure\Time\Clock\PausedClock;

class TcxFileParserTest extends ActivityFileParserTestCase
{
    private TcxFileParser $parser;

    public function testSupportedExtensions(): void
    {
        $this->assertSame(SupportedFileExtension::TCX, $this->parser->supportedExtension());
    }

    public function testParse(): void
    {
        $this->assertParsedFileMatchesSnapshot(
            $this->parser->parse($this->rawFileFromFixture('activity.tcx'))
        );
    }

    public function testParseFillsGapsInSparseTrackpoints(): void
    {
        $this->assertParsedFileMatchesSnapshot(
            $this->parser->parse($this->rawFileFromFixture('activity-sparse.tcx'))
        );
    }

    public function testParseMergedFileCorrectsMovingTimeAndDistance(): void
    {
        $this->assertParsedFileMatchesSnapshot(
            $this->parser->parse($this->rawFileFromFixture('activity-merged.tcx'))
        );
    }

    public function testParsePolarExportDerivesSpeedFromDistanceAndTime(): void
    {
        $this->assertParsedFileMatchesSnapshot(
            $this->parser->parse($this->rawFileFromFixture('activity-polar.tcx'))
        );
    }

    public function testParseHuaweiExportDerivesSpeedAndDistanceFromCoordinates(): void
    {
        $this->assertParsedFileMatchesSnapshot(
            $this->parser->parse($this->rawFileFromFixture('activity-huawei.tcx'))
        );
    }

    public function testParseEmptyContentsThrows(): void
    {
        $rawActivityFile = RawActivityFile::from(Path::fromString('does-not-exist.tcx'), '');

        $this->expectExceptionObject(new CouldNotParseActivityFile('Could not read "does-not-exist.tcx"', $rawActivityFile));
        $this->parser->parse($rawActivityFile);
    }

    public function testParseUnknownSportDefaultsToWorkout(): void
    {
        $parsed = $this->parser->parse($this->rawFileFromFixture('activity-other-sport.tcx'));

        $this->assertSame(SportType::WORKOUT, $parsed->getActivity()->getSportType());
    }

    public function testParseMultipleLapsAndTracks(): void
    {
        $parsed = $this->parser->parse($this->rawFileFromFixture('activity-multi-lap.tcx'));

        $laps = $parsed->getLaps()->toArray();
        $this->assertCount(2, $laps);
        $this->assertSame(1, $laps[0]->getLapNumber());
        $this->assertSame('Lap 1', $laps[0]->getName());
        $this->assertSame(2, $laps[1]->getLapNumber());
        $this->assertSame('Lap 2', $laps[1]->getName());
        // Trackpoints of the second <Track> in lap 1 must not be dropped.
        $this->assertSame(60.0, $laps[0]->getDistance()->toFloat());
        $this->assertSame(
            [0, 10, 20, 30, 40],
            $parsed->getStreams()->filterOnType(StreamType::TIME)->getData()
        );
        // The Suunto zero-altitude filter leaves these zeros alone because the file has
        // no real altitude data, after which the mapper drops the stream: an altitude
        // series of nothing but zeroes conveys no elevation.
        $this->assertNull($parsed->getStreams()->filterOnType(StreamType::ALTITUDE));
    }

    public function testParseFileWithBomAndLeadingWhitespace(): void
    {
        $parsed = $this->parser->parse($this->rawFileFromFixture('activity-leading-whitespace.tcx'));
        $activity = $parsed->getActivity();

        $this->assertSame(SportType::RIDE, $activity->getSportType());
        $this->assertSame('Garmin Edge 530', $activity->getDeviceName());
        $this->assertSame(0.05, $activity->getDistance()->toFloat());
        $this->assertSame(42, $activity->getCalories());
    }

    public function testParseSummaryOnlyFileFallsBackToTheSummaries(): void
    {
        $parsed = $this->parser->parse($this->rawFileFromFixture('activity-mi-fitness.tcx'));
        $activity = $parsed->getActivity();

        // An empty Sport attribute resolves to the generic workout type.
        $this->assertSame(SportType::WORKOUT, $activity->getSportType());
        // <Activity><Id> is the only instant this file has.
        $this->assertSame('2021-09-08 17:12:44', $activity->getStartDate()->toUtc()->format('Y-m-d H:i:s'));
        // Without a single timed trackpoint the lap total is the only moving-time estimate.
        $this->assertSame(1802, $activity->getElapsedTimeInSeconds());
        $this->assertSame(1802, $activity->getMovingTimeInSeconds());
        $this->assertSame(0.0, $activity->getDistance()->toFloat());
        $this->assertSame(400, $activity->getCalories());
        $this->assertSame(112, $activity->getAverageHeartRate());
        $this->assertNull($parsed->getStreams()->filterOnType(StreamType::HEART_RATE));
        $this->assertSame(112, $parsed->getLaps()->toArray()[0]->getAverageHeartRate());
    }

    public function testParseSummaryOnlyFilePrefersTheLapStartTimeOverAnIdThatIsNotADateTime(): void
    {
        $parsed = $this->parser->parse($this->rawFileFromContents('12784354', ' StartTime="2021-09-08T10:00:00Z"'));

        $this->assertSame('2021-09-08 10:00:00', $parsed->getActivity()->getStartDate()->toUtc()->format('Y-m-d H:i:s'));
    }

    public function testParseSummaryOnlyFileWithoutAnyStartInstantThrows(): void
    {
        $rawActivityFile = $this->rawFileFromContents('not-a-date-time');

        $this->expectException(CouldNotParseActivityFile::class);
        $this->parser->parse($rawActivityFile);
    }

    private function rawFileFromContents(string $activityId, string $lapAttributes = ''): RawActivityFile
    {
        $contents = sprintf(
            '<TrainingCenterDatabase xmlns="http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2"><Activities><Activity Sport=""><Id>%s</Id><Lap%s><TotalTimeSeconds>10</TotalTimeSeconds></Lap></Activity></Activities></TrainingCenterDatabase>',
            $activityId,
            $lapAttributes,
        );

        return RawActivityFile::from(Path::fromString('summary-only.tcx'), $contents);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new TcxFileParser(
            new IncrementingActivityIdFactory(),
            new ActivityLapsMapper(new IncrementingActivityLapIdFactory()),
            new ActivityStreamsMapper(PausedClock::fromString('2023-10-17 16:15:04')),
            SerializableTimezone::UTC(),
        );
    }
}
