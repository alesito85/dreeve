<?php

declare(strict_types=1);

namespace App\Tests\Domain\Import\FileParser;

use App\Domain\Activity\SportType\SportType;
use App\Domain\Activity\Stream\StreamType;
use App\Domain\Import\FileParser\ActivityLapsMapper;
use App\Domain\Import\FileParser\ActivityStreamsMapper;
use App\Domain\Import\FileParser\CouldNotParseActivityFile;
use App\Domain\Import\FileParser\FitFileParser;
use App\Domain\Import\FileParser\RawActivityFile;
use App\Domain\Import\SupportedFileExtension;
use App\Infrastructure\Process\ProcessFactory;
use App\Infrastructure\Process\SymfonyProcessFactory;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\ValueObject\String\Path;
use App\Infrastructure\ValueObject\Time\SerializableTimezone;
use App\Tests\Domain\Activity\IncrementingActivityIdFactory;
use App\Tests\Domain\Activity\Lap\IncrementingActivityLapIdFactory;
use App\Tests\Infrastructure\Time\Clock\PausedClock;
use PHPUnit\Framework\MockObject\Stub;
use Symfony\Component\Process\Process;

class FitFileParserTest extends ActivityFileParserTestCase
{
    private const int START_FIT_SECONDS = 1000000000;

    private FitFileParser $parser;
    private Stub $processFactory;

    public function testSupportedExtensions(): void
    {
        $this->assertSame(SupportedFileExtension::FIT, $this->parser->supportedExtension());
    }

    public function testParse(): void
    {
        $this->givenFitToolReturns((string) file_get_contents(__DIR__.'/fixtures/fit-document.json'));

        $this->assertParsedFileMatchesSnapshot(
            $this->parser->parse(RawActivityFile::from(Path::fromString('/tmp/activity.fit'), ''))
        );
    }

    public function testParseWithGearChanges(): void
    {
        $this->givenFitToolReturns((string) file_get_contents(__DIR__.'/fixtures/fit-document-with-gear-changes.json'));

        $this->assertParsedFileMatchesSnapshot(
            $this->parser->parse(RawActivityFile::from(Path::fromString('/tmp/activity.fit'), '')),
            hasDrivetrainUsage: true,
        );
    }

    public function testParseDerivesSummaryMetricsFromStreamsWhenMissingFromSession(): void
    {
        $this->givenFitToolReturns((string) file_get_contents(__DIR__.'/fixtures/fit-document-without-session-summary-metrics.json'));

        $this->assertParsedFileMatchesSnapshot(
            $this->parser->parse(RawActivityFile::from(Path::fromString('/tmp/activity.fit'), ''))
        );
    }

    public function testParseDerivesElevationFromStreamWhenTotalAscentIsZero(): void
    {
        $this->givenFitToolReturns((string) file_get_contents(__DIR__.'/fixtures/fit-document-with-zero-total-ascent.json'));

        $this->assertParsedFileMatchesSnapshot(
            $this->parser->parse(RawActivityFile::from(Path::fromString('/tmp/activity.fit'), ''))
        );
    }

    public function testParseIgnoresZeroSessionHeartRate(): void
    {
        $this->givenFitToolReturns((string) file_get_contents(__DIR__.'/fixtures/fit-document-with-zero-heart-rate.json'));

        $this->assertParsedFileMatchesSnapshot(
            $this->parser->parse(RawActivityFile::from(Path::fromString('/tmp/activity.fit'), ''))
        );
    }

    public function testParseSessionWithoutRecords(): void
    {
        $this->givenFitToolReturns((string) file_get_contents(__DIR__.'/fixtures/fit-document-session-only.json'));

        $this->assertParsedFileMatchesSnapshot(
            $this->parser->parse(RawActivityFile::from(Path::fromString('/tmp/activity.fit'), ''))
        );
    }

    public function testParseWithoutRecordsAndWithoutSessionThrows(): void
    {
        $this->givenFitToolReturns(Json::encode([
            'files' => [[
                'profileVersion' => 2132,
                'messages' => [
                    ['name' => 'file_id', 'fields' => []],
                    ['name' => 'monitoring', 'fields' => [
                        ['name' => 'timestamp', 'value' => self::START_FIT_SECONDS],
                    ]],
                ],
            ]],
        ]));

        $rawActivityFile = RawActivityFile::from(Path::fromString('/tmp/activity.fit'), '');

        $this->expectExceptionObject(new CouldNotParseActivityFile('No FIT "record" messages found in "activity.fit"', $rawActivityFile));
        $this->parser->parse($rawActivityFile);
    }

    public function testParsePrefersTotalMovingTimeOverTimerTime(): void
    {
        $this->givenFitToolReturns((string) file_get_contents(__DIR__.'/fixtures/fit-document-with-total-moving-time.json'));

        $this->assertParsedFileMatchesSnapshot(
            $this->parser->parse(RawActivityFile::from(Path::fromString('/tmp/activity.fit'), ''))
        );
    }

    public function testParseUsesWorkoutNameAndDescription(): void
    {
        $this->givenFitToolReturns((string) file_get_contents(__DIR__.'/fixtures/fit-document-with-workout.json'));

        $this->assertParsedFileMatchesSnapshot(
            $this->parser->parse(RawActivityFile::from(Path::fromString('/tmp/activity.fit'), ''))
        );
    }

    public function testParseUsesStreamAveragePowerWhenSessionValueDeviatesTooMuch(): void
    {
        $this->givenFitToolReturns((string) file_get_contents(__DIR__.'/fixtures/fit-document-with-deviating-session-avg-power.json'));

        $this->assertSame(113, $this->parser->parse(RawActivityFile::from(Path::fromString('/tmp/activity.fit'), ''))->getActivity()->getAveragePower());
    }

    public function testParseMergesRecordsSplitAcrossSameTimestamp(): void
    {
        $this->givenFitToolReturns((string) file_get_contents(__DIR__.'/fixtures/fit-document-with-split-records.json'));

        $this->assertParsedFileMatchesSnapshot(
            $this->parser->parse(RawActivityFile::from(Path::fromString('/tmp/activity.fit'), ''))
        );
    }

    public function testParseRealFitFileThroughBinary(): void
    {
        $parser = new FitFileParser(
            new IncrementingActivityIdFactory(),
            new ActivityLapsMapper(new IncrementingActivityLapIdFactory()),
            new SymfonyProcessFactory(),
            new ActivityStreamsMapper(PausedClock::fromString('2023-10-17 16:15:04')),
            SerializableTimezone::UTC(),
        );

        $this->assertParsedFileMatchesSnapshot(
            $parser->parse($this->rawFileFromFixture('activity.fit'))
        );
    }

    public function testParseRealPoolSwimWithStrapHeartRateThroughBinary(): void
    {
        $parser = new FitFileParser(
            new IncrementingActivityIdFactory(),
            new ActivityLapsMapper(new IncrementingActivityLapIdFactory()),
            new SymfonyProcessFactory(),
            new ActivityStreamsMapper(PausedClock::fromString('2023-10-17 16:15:04')),
            SerializableTimezone::UTC(),
        );

        $this->assertParsedFileMatchesSnapshot(
            $parser->parse($this->rawFileFromFixture('activity-pool-swim-with-hr-mesgs.fit'))
        );
    }

    public function testParseIgnoresADistanceStreamThatOnlyContainsZeroes(): void
    {
        $this->givenFitToolReturns((string) file_get_contents(__DIR__.'/fixtures/fit-document-with-zero-distance.json'));

        $streams = $this->parser->parse(RawActivityFile::from(Path::fromString('/tmp/activity.fit'), ''))->getStreams();

        $this->assertNull($streams->filterOnType(StreamType::DISTANCE));
        $this->assertNotNull($streams->filterOnType(StreamType::HEART_RATE));
    }

    public function testParseMergesStrapHeartRateFromHrMessages(): void
    {
        // The records carry wrist readings of 120 and 130 bpm; the strap
        // samples in the chained hr messages should replace them with 96 and
        // 98 bpm.
        $this->givenFitToolReturns((string) file_get_contents(__DIR__.'/fixtures/fit-document-with-hr-mesgs.json'));

        $this->assertParsedFileMatchesSnapshot(
            $this->parser->parse(RawActivityFile::from(Path::fromString('/tmp/activity.fit'), ''))
        );
    }

    public function testParseIgnoresStrapHeartRateWithoutAnchor(): void
    {
        $document = Json::decode((string) file_get_contents(__DIR__.'/fixtures/fit-document-with-hr-mesgs.json'));
        // Strip the anchor hr message; without it the event_timestamp clock
        // cannot be mapped to wall clock time and the wrist readings are kept.
        array_shift($document['files'][1]['messages']);

        $this->givenFitToolReturns(Json::encode($document));

        $this->assertParsedFileMatchesSnapshot(
            $this->parser->parse(RawActivityFile::from(Path::fromString('/tmp/activity.fit'), ''))
        );
    }

    public function testParseWithPedalingDynamics(): void
    {
        // The record balances are bit fields: the first sample is referenced to
        // the left side, the second one carries the right flag and holds the
        // right side's share.
        $this->givenFitToolReturns(Json::encode([
            'files' => [[
                'profileVersion' => 2132,
                'messages' => [
                    ['name' => 'file_id', 'fields' => []],
                    ['name' => 'session', 'fields' => [
                        ['name' => 'sport', 'value' => 2], // cycling
                        ['name' => 'start_time', 'value' => self::START_FIT_SECONDS],
                        ['name' => 'left_right_balance', 'value' => 4600],
                        ['name' => 'total_training_effect', 'value' => 2.6],
                        ['name' => 'total_anaerobic_training_effect', 'value' => 0.1],
                        ['name' => 'jump_count', 'value' => 12],
                        ['name' => 'avg_left_torque_effectiveness', 'value' => 95],
                        ['name' => 'avg_right_torque_effectiveness', 'value' => 93],
                        ['name' => 'avg_left_pedal_smoothness', 'value' => 74],
                        ['name' => 'avg_right_pedal_smoothness', 'value' => 72],
                        ['name' => 'avg_combined_pedal_smoothness', 'value' => 73],
                        ['name' => 'avg_left_power_phase', 'value' => [28.4]],
                        ['name' => 'avg_left_power_phase_peak', 'value' => [96.5]],
                        ['name' => 'avg_right_power_phase', 'value' => [30.2]],
                        ['name' => 'avg_right_power_phase_peak', 'value' => [98.1]],
                    ]],
                    ['name' => 'record', 'fields' => [
                        ['name' => 'timestamp', 'value' => self::START_FIT_SECONDS],
                        ['name' => 'left_right_balance', 'value' => 52],
                        ['name' => 'left_torque_effectiveness', 'value' => 95],
                        ['name' => 'right_torque_effectiveness', 'value' => 93],
                        ['name' => 'left_pedal_smoothness', 'value' => 74],
                        ['name' => 'right_pedal_smoothness', 'value' => 72],
                    ]],
                    ['name' => 'record', 'fields' => [
                        ['name' => 'timestamp', 'value' => self::START_FIT_SECONDS + 1],
                        ['name' => 'left_right_balance', 'value' => 0x80 | 46],
                        ['name' => 'left_torque_effectiveness', 'value' => 96],
                        ['name' => 'right_torque_effectiveness', 'value' => 94],
                        ['name' => 'combined_pedal_smoothness', 'value' => 90],
                    ]],
                ],
            ]],
        ]));

        $parsed = $this->parser->parse(RawActivityFile::from(Path::fromString('/tmp/activity.fit'), ''));
        $dynamics = $parsed->getActivity()->getDynamics();

        $this->assertNotNull($dynamics);
        $this->assertSame(46.0, $dynamics->getLeftRightBalanceInPercent());
        $this->assertSame(54.0, $dynamics->getRightSideBalanceInPercent());
        $this->assertSame(2.6, $dynamics->getAerobicTrainingEffect());
        $this->assertSame(0.1, $dynamics->getAnaerobicTrainingEffect());
        $this->assertSame(12, $dynamics->getJumpCount());
        $this->assertTrue($dynamics->hasPedalingDynamics());
        $this->assertSame(96.5, $dynamics->getLeftPowerPhasePeakInDegrees());

        $streams = $parsed->getStreams();
        $this->assertEquals([52.0, 54.0], array_values(array_filter(
            $streams->filterOnType(StreamType::LEFT_RIGHT_BALANCE)?->getData() ?? [],
        )));
        $this->assertEquals([94.0, 95.0], array_values(array_filter(
            $streams->filterOnType(StreamType::TORQUE_EFFECTIVENESS)?->getData() ?? [],
        )));
        $this->assertEquals([73.0, 90.0], array_values(array_filter(
            $streams->filterOnType(StreamType::PEDAL_SMOOTHNESS)?->getData() ?? [],
        )));
    }

    public function testParseWithoutPedalingDynamics(): void
    {
        $this->givenFitToolReturns((string) file_get_contents(__DIR__.'/fixtures/fit-document.json'));

        $parsed = $this->parser->parse(RawActivityFile::from(Path::fromString('/tmp/activity.fit'), ''));

        $this->assertNull($parsed->getActivity()->getDynamics());
        $this->assertNull($parsed->getStreams()->filterOnType(StreamType::LEFT_RIGHT_BALANCE));
        $this->assertNull($parsed->getStreams()->filterOnType(StreamType::TORQUE_EFFECTIVENESS));
        $this->assertNull($parsed->getStreams()->filterOnType(StreamType::PEDAL_SMOOTHNESS));
    }

    public function testParsePrefersProductNameWhenManufacturerHasNoProductEnum(): void
    {
        $document = $this->minimalFitDocument(fileIdFields: [
            ['name' => 'manufacturer', 'value' => 23],
            ['name' => 'product', 'value' => 999],
            ['name' => 'product_name', 'value' => 'Suunto Vertical'],
        ]);
        $this->givenFitToolReturns(Json::encode($document));

        $this->assertSame('Suunto Vertical', $this->parser->parse(RawActivityFile::from(Path::fromString('/tmp/activity.fit'), ''))->getActivity()->getDeviceName());
    }

    public function testParseFallsBackToManufacturerWhenProductNameMissing(): void
    {
        $document = $this->minimalFitDocument(fileIdFields: [
            ['name' => 'manufacturer', 'value' => 123],
            ['name' => 'product', 'value' => 99],
        ]);
        $this->givenFitToolReturns(Json::encode($document));

        $this->assertSame('Polar Electro', $this->parser->parse(RawActivityFile::from(Path::fromString('/tmp/activity.fit'), ''))->getActivity()->getDeviceName());
    }

    public function testParseTrailRunSubSport(): void
    {
        $document = $this->minimalFitDocument(sessionFields: [
            ['name' => 'sport', 'value' => 1], // running
            ['name' => 'sub_sport', 'value' => 3], // trail
            ['name' => 'start_time', 'value' => self::START_FIT_SECONDS],
        ]);
        $this->givenFitToolReturns(Json::encode($document));

        $this->assertSame(SportType::TRAIL_RUN, $this->parser->parse(RawActivityFile::from(Path::fromString('/tmp/activity.fit'), ''))->getActivity()->getSportType());
    }

    public function testParseGenericSportWithCustomProfileName(): void
    {
        $this->givenFitToolReturns((string) file_get_contents(__DIR__.'/fixtures/fit-document-generic-sport-profile.json'));

        $this->assertSame(SportType::WORKOUT, $this->parser->parse(RawActivityFile::from(Path::fromString('/tmp/activity.fit'), ''))->getActivity()->getSportType());
    }

    public function testParseResolvesGenericSportFromSportProfileName(): void
    {
        $document = $this->minimalFitDocument(sessionFields: [
            ['name' => 'sport', 'value' => 0], // generic
            ['name' => 'sub_sport', 'value' => 0], // generic
            ['name' => 'sport_profile_name', 'value' => 'Indoor Rowing'],
            ['name' => 'start_time', 'value' => self::START_FIT_SECONDS],
        ]);
        $this->givenFitToolReturns(Json::encode($document));

        $this->assertSame(SportType::VIRTUAL_ROW, $this->parser->parse(RawActivityFile::from(Path::fromString('/tmp/activity.fit'), ''))->getActivity()->getSportType());
    }

    public function testParseUnsuccessfulProcessThrows(): void
    {
        $process = $this->createStub(Process::class);
        $process->method('isSuccessful')->willReturn(false);
        $process->method('getErrorOutput')->willReturn('boom');
        $this->processFactory->method('create')->willReturn($process);

        $rawActivityFile = RawActivityFile::from(Path::fromString('/tmp/activity.fit'), '');

        $this->expectExceptionObject(new CouldNotParseActivityFile('fit-tool could not decode "activity.fit": boom', $rawActivityFile));
        $this->parser->parse($rawActivityFile);
    }

    public function testParseMultiSportFileThrows(): void
    {
        $this->givenFitToolReturns((string) file_get_contents(__DIR__.'/fixtures/fit-document-multisport.json'));

        $rawActivityFile = RawActivityFile::from(Path::fromString('/tmp/activity.fit'), '');

        $this->expectExceptionObject(new CouldNotParseActivityFile('Multi-sport FIT file "activity.fit" import not supported, each leg needs to be imported as a separate file', $rawActivityFile));
        $this->parser->parse($rawActivityFile);
    }

    public function testParseMultiSportSessionThrows(): void
    {
        $document = $this->minimalFitDocument(sessionFields: [
            ['name' => 'sport', 'value' => 18], // multisport
            ['name' => 'start_time', 'value' => self::START_FIT_SECONDS],
        ]);
        $this->givenFitToolReturns(Json::encode($document));

        $rawActivityFile = RawActivityFile::from(Path::fromString('/tmp/activity.fit'), '');

        $this->expectExceptionObject(new CouldNotParseActivityFile('Multi-sport FIT file "activity.fit" import not supported, each leg needs to be imported as a separate file', $rawActivityFile));
        $this->parser->parse($rawActivityFile);
    }

    public function testParseAllowsMultipleSessionsOfTheSameSport(): void
    {
        $this->givenFitToolReturns((string) file_get_contents(__DIR__.'/fixtures/fit-document-with-multiple-sessions-of-the-same-sport.json'));

        $this->assertSame(SportType::RIDE, $this->parser->parse(RawActivityFile::from(Path::fromString('/tmp/activity.fit'), ''))->getActivity()->getSportType());
    }

    public function testParseUnsupportedSportThrows(): void
    {
        $document = $this->minimalFitDocument(sessionFields: [
            ['name' => 'sport', 'value' => 24], // driving (unsupported)
            ['name' => 'start_time', 'value' => self::START_FIT_SECONDS],
        ]);
        $this->givenFitToolReturns(Json::encode($document));

        $rawActivityFile = RawActivityFile::from(Path::fromString('/tmp/activity.fit'), '');

        $this->expectExceptionObject(new CouldNotParseActivityFile('Unsupported FIT sport 24 (sub sport null)', $rawActivityFile));
        $this->parser->parse($rawActivityFile);
    }

    private function minimalFitDocument(array $fileIdFields = [], array $sessionFields = []): array
    {
        return [
            'files' => [[
                'profileVersion' => 2132,
                'messages' => [
                    ['name' => 'file_id', 'fields' => $fileIdFields],
                    ['name' => 'session', 'fields' => [] !== $sessionFields ? $sessionFields : [
                        ['name' => 'sport', 'value' => 2], // cycling
                        ['name' => 'start_time', 'value' => self::START_FIT_SECONDS],
                    ]],
                    ['name' => 'record', 'fields' => [
                        ['name' => 'timestamp', 'value' => self::START_FIT_SECONDS],
                    ]],
                ],
            ]],
        ];
    }

    private function givenFitToolReturns(string $output): void
    {
        $process = $this->createStub(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $process->method('getOutput')->willReturn($output);

        $this->processFactory
            ->method('create')
            ->willReturn($process);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new FitFileParser(
            new IncrementingActivityIdFactory(),
            new ActivityLapsMapper(new IncrementingActivityLapIdFactory()),
            $this->processFactory = $this->createStub(ProcessFactory::class),
            new ActivityStreamsMapper(PausedClock::fromString('2023-10-17 16:15:04')),
            SerializableTimezone::UTC(),
        );
    }
}
