<?php

declare(strict_types=1);

namespace App\Tests\Application\Import\FileImport\ImportActivityFiles;

use App\Application\Import\FileImport\ImportActivityFiles\ImportActivityFiles;
use App\Application\Import\FileImport\ImportActivityFiles\ImportActivityFilesCommandHandler;
use App\Application\Import\ImportedActivities;
use App\Domain\Activity\Activity;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\BestEffort\ActivityBestEffort;
use App\Domain\Activity\BestEffort\ActivityBestEffortRepository;
use App\Domain\Activity\ImportSource;
use App\Domain\Activity\Lap\ActivityLapRepository;
use App\Domain\Activity\Revision\ActivityRevisionRepository;
use App\Domain\Activity\Scan\ActivityScan;
use App\Domain\Activity\Scan\ActivityScanRepository;
use App\Domain\Activity\Scan\ActivityScanType;
use App\Domain\Activity\Split\ActivitySplit;
use App\Domain\Activity\Split\ActivitySplitRepository;
use App\Domain\Activity\SportType\SportType;
use App\Domain\Activity\Stream\ActivityStream;
use App\Domain\Activity\Stream\ActivityStreamRepository;
use App\Domain\Activity\Stream\CombinedStream\CombinedActivityStream;
use App\Domain\Activity\Stream\CombinedStream\CombinedActivityStreamRepository;
use App\Domain\Activity\Stream\CombinedStream\CombinedStreamType;
use App\Domain\Activity\Stream\CombinedStream\CombinedStreamTypes;
use App\Domain\Activity\Stream\Metric\ActivityStreamMetric;
use App\Domain\Activity\Stream\Metric\ActivityStreamMetricRepository;
use App\Domain\Activity\Stream\Metric\ActivityStreamMetricType;
use App\Domain\Activity\Stream\StreamType;
use App\Domain\Import\FileImportStatus;
use App\Domain\Segment\SegmentEffort\SegmentEffort;
use App\Domain\Segment\SegmentEffort\SegmentEffortId;
use App\Domain\Segment\SegmentEffort\SegmentEffortRepository;
use App\Domain\Segment\SegmentRepository;
use App\Infrastructure\Cache\Cacheability;
use App\Infrastructure\Cache\CacheableRenderer;
use App\Infrastructure\Cache\Render\RenderCache;
use App\Infrastructure\Cache\Tag\CacheTags;
use App\Infrastructure\Cache\Tag\RootCacheTag;
use App\Infrastructure\Measurement\Length\Kilometer;
use App\Infrastructure\Measurement\Length\Meter;
use App\Infrastructure\Measurement\UnitSystem;
use App\Infrastructure\Measurement\Velocity\MetersPerSecond;
use App\Infrastructure\ValueObject\String\CompressedString;
use App\Infrastructure\ValueObject\String\KernelProjectDir;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use App\Tests\Domain\Segment\SegmentBuilder;
use App\Tests\Infrastructure\Cache\RenderStub;
use App\Tests\SpyOutput;
use League\Flysystem\FilesystemOperator;

class ImportActivityFilesCommandHandlerTest extends ContainerTestCase
{
    private ImportActivityFilesCommandHandler $handler;
    private FilesystemOperator $watchStorage;
    private CacheableRenderer $cacheableRenderer;

    public function testHandleImportsActivityFile(): void
    {
        $this->watchStorage->write('watch/ride.tcx', $this->fixture('activity.tcx'));

        $output = new SpyOutput();
        $importedActivities = ImportedActivities::empty();
        $this->handler->handle(new ImportActivityFiles($output, $importedActivities));

        $fileImports = $this->getConnection()
            ->executeQuery('SELECT * FROM FileImport ORDER BY importedOn ASC')
            ->fetchAllAssociative();
        $this->assertCount(1, $fileImports);
        $fileImport = $fileImports[0];
        $this->assertSame(FileImportStatus::SUCCESS->value, $fileImport['status']);
        $this->assertSame('ride.tcx', $fileImport['originalFilename']);
        $this->assertSame($this->fixture('activity.tcx'), CompressedString::fromCompressed($fileImport['fileContents'])->uncompress());

        $this->assertNotNull($fileImport['activityId']);
        $activityId = ActivityId::fromString($fileImport['activityId']);
        $activity = $this->getContainer()->get(ActivityRepository::class)->find($activityId);

        $this->assertSame(ImportSource::TCX_FILE, $activity->getImportSource());
        $this->assertSame('Night Ride', $activity->getName());
        $this->assertEquals([$activityId], array_map(static fn (Activity $importedActivity): ActivityId => $importedActivity->getId(), $importedActivities->toArray()));
        $this->assertSame('Garmin Edge 530', $activity->getDeviceName());
        $this->assertNotNull($activity->getStartingCoordinate());
        $this->assertNotNull($activity->getEncodedPolyline());

        $this->assertCount(1, $this->getContainer()->get(ActivityLapRepository::class)->findBy($activityId));

        $streams = $this->getContainer()->get(ActivityStreamRepository::class)->findByActivityId($activityId);
        $this->assertNotNull($streams->filterOnType(StreamType::HEART_RATE));
        $this->assertNotNull($streams->filterOnType(StreamType::LAT_LNG));
        $this->assertTrue($this->getContainer()->get(ActivityScanRepository::class)->isScanned(
            activityId: $activityId,
            type: ActivityScanType::STREAMS,
        ));

        $this->assertSame(
            implode("\n", [
                'Importing activity files...',
                '  => [1/1] Imported "ride.tcx" as activity "Night Ride - 08-09-2021"',
                '  => Imported 1, skipped 0, failed 0 activity file(s)',
            ]),
            (string) $output,
        );
    }

    public function testHandleImportsFileWithUppercaseExtension(): void
    {
        $this->watchStorage->write('watch/Ride_2026-07-02.TCX', $this->fixture('activity.tcx'));

        $output = new SpyOutput();
        $this->handler->handle(new ImportActivityFiles($output, ImportedActivities::empty()));

        $fileImports = $this->getConnection()
            ->executeQuery('SELECT * FROM FileImport ORDER BY importedOn ASC')
            ->fetchAllAssociative();
        $this->assertCount(1, $fileImports);
        $this->assertSame(FileImportStatus::SUCCESS->value, $fileImports[0]['status']);
        $this->assertFalse(
            $this->watchStorage->fileExists('watch/Ride_2026-07-02.TCX'),
            'File with uppercase extension should be deleted after import',
        );
    }

    public function testHandleSkipsAlreadyImportedFile(): void
    {
        $bytes = $this->fixture('activity.tcx');

        $this->watchStorage->write('watch/ride1.tcx', $bytes);
        $this->watchStorage->write('watch/ride2.tcx', $bytes);
        $this->watchStorage->write('watch/ride3.tcx', $bytes);

        $output = new SpyOutput();
        $this->handler->handle(new ImportActivityFiles($output, ImportedActivities::empty()));

        $this->assertCount(3, $this->getConnection()
            ->executeQuery('SELECT * FROM FileImport ORDER BY importedOn ASC')
            ->fetchAllAssociative());
        $this->assertEquals(
            [FileImportStatus::SUCCESS, FileImportStatus::SKIPPED,  FileImportStatus::SKIPPED],
            array_map(
                static fn (array $file): FileImportStatus => FileImportStatus::from($file['status']),
                array_values($this->getConnection()
                    ->executeQuery('SELECT * FROM FileImport ORDER BY importedOn ASC')
                    ->fetchAllAssociative())
            )
        );
        $this->assertSame(
            implode("\n", [
                'Importing activity files...',
                '  => [1/3] Imported "ride1.tcx" as activity "Night Ride - 08-09-2021"',
                '  => [2/3] Skipping "ride2.tcx", activity was already imported',
                '  => [3/3] Skipping "ride3.tcx", activity was already imported',
                '  => Imported 1, skipped 2, failed 0 activity file(s)',
            ]),
            (string) $output,
        );
    }

    public function testHandleImportsGpxFileAndSkipsDuplicate(): void
    {
        $bytes = $this->fixture('activity.gpx');

        $this->watchStorage->write('watch/run1.gpx', $bytes);
        $this->watchStorage->write('watch/run2.gpx', $bytes);

        $output = new SpyOutput();
        $this->handler->handle(new ImportActivityFiles($output, ImportedActivities::empty()));

        $fileImports = array_values($this->getConnection()
            ->executeQuery('SELECT * FROM FileImport ORDER BY importedOn ASC')
            ->fetchAllAssociative());
        $this->assertCount(2, $fileImports);
        $this->assertSame(FileImportStatus::SUCCESS->value, $fileImports[0]['status']);
        $this->assertSame(ImportSource::GPX_FILE->value, $fileImports[0]['source']);
        $this->assertSame(FileImportStatus::SKIPPED->value, $fileImports[1]['status']);
        $this->assertSame(ImportSource::GPX_FILE->value, $fileImports[1]['source']);
    }

    public function testHandleSkipsAFileThatFitsTwoActivitiesItCannotTellApart(): void
    {
        $bytes = $this->fixture('activity.tcx');
        $this->watchStorage->write('watch/ride1.tcx', $bytes);

        $this->handler->handle(new ImportActivityFiles(new SpyOutput(), ImportedActivities::empty()));

        // A second activity that fits the file just as well, with no fingerprint
        // left on either of them. This is the state where Dreeve cannot say which
        // activity the file belongs to, and picking one would duplicate the other.
        $row = $this->getConnection()->fetchAssociative('SELECT * FROM Activity');
        $this->assertIsArray($row);
        $this->getConnection()->executeStatement('UPDATE Activity SET fileChecksum = NULL');
        $this->getConnection()->insert('Activity', [
            ...$row,
            'activityId' => 'activity-20000000001',
            'name' => 'The same ride under another name',
            'fileChecksum' => null,
        ]);

        $this->watchStorage->write('watch/ride2.tcx', $bytes);
        $output = new SpyOutput();
        $this->handler->handle(new ImportActivityFiles($output, ImportedActivities::empty()));

        $this->assertSame('2', (string) $this->getConnection()->executeQuery('SELECT COUNT(*) FROM Activity')->fetchOne());
        $skipped = $this->getConnection()
            ->executeQuery('SELECT * FROM FileImport WHERE status = :status', ['status' => FileImportStatus::SKIPPED->value])
            ->fetchAllAssociative();
        $this->assertCount(1, $skipped);
        $this->assertNull($skipped[0]['activityId'], 'An ambiguous file must not be pinned to one activity');
        $this->assertSame(
            $bytes,
            CompressedString::fromCompressed($skipped[0]['fileContents'])->uncompress(),
            'The bytes have to survive in the import log, they are all that is left of the file'
        );
        $this->assertStringContainsString('needs a manual decision', $skipped[0]['errorMessage']);
        $this->assertFalse(
            $this->watchStorage->fileExists('watch/ride2.tcx'),
            'The file cannot be left in watch to be retried on every run'
        );
        $this->assertStringContainsString(
            '  => [1/1] Skipping "ride2.tcx", Could not match this file to a single activity, 2 activities fit it as well as each other',
            (string) $output,
        );
    }

    public function testHandleMergesAFileThatBelongsToAnActivityItAlreadyHas(): void
    {
        $this->watchStorage->write('watch/ride.tcx', $this->fixture('activity.tcx'));
        $this->handler->handle(new ImportActivityFiles(new SpyOutput(), ImportedActivities::empty()));

        $activityId = ActivityId::fromString((string) $this->getConnection()->fetchOne('SELECT activityId FROM Activity'));
        $this->addRowsDerivedFromTheStreams($activityId);

        $extended = $this->extendedFixture();
        $this->watchStorage->write('watch/ride-full-sync.tcx', $extended);

        $output = new SpyOutput();
        $importedActivities = ImportedActivities::empty();
        $this->handler->handle(new ImportActivityFiles($output, $importedActivities));

        // The whole point: a file that belongs to an activity already in the
        // database adds to it, it never becomes a second activity.
        $this->assertSame('1', (string) $this->getConnection()->executeQuery('SELECT COUNT(*) FROM Activity')->fetchOne());
        $this->assertCount(0, $importedActivities->toArray(), 'A merge is not a new activity, the notification must not count it');

        $activity = $this->getContainer()->get(ActivityRepository::class)->find($activityId);
        $this->assertEquals(Kilometer::from(0.09), $activity->getDistance());
        $this->assertSame(20, $activity->getElapsedTimeInSeconds());
        $this->assertSame(ImportSource::TCX_FILE, $activity->getImportSource());

        $streams = $this->getContainer()->get(ActivityStreamRepository::class)->findByActivityId($activityId);
        $this->assertSame([0, 10, 20], $streams->filterOnType(StreamType::TIME)?->getData());
        $this->assertNull(
            $streams->filterOnType(StreamType::MOVING),
            'The derived stream has to go, it is rebuilt from the merged timeline'
        );

        // Second 10 takes the newer sample, second 20 is the tail the first file
        // never recorded, and second 0 is where both files agree.
        $this->assertSame([120, 135, 140], $streams->filterOnType(StreamType::HEART_RATE)?->getData());
        $this->assertSame([200, 210, 220], $streams->filterOnType(StreamType::WATTS)?->getData());
        $this->assertSame([80, 82, 84], $streams->filterOnType(StreamType::CADENCE)?->getData());

        // Everything built off the old streams is dropped, the metrics steps pick
        // the activity up again on the next run.
        foreach (['ActivitySplit', 'CombinedActivityStream', 'ActivityStreamMetric', 'ActivityBestEffort', 'SegmentEffort'] as $table) {
            $this->assertSame(0, $this->countRows($table, $activityId), sprintf('"%s" should have been invalidated', $table));
        }
        $this->assertSame(
            [ActivityScanType::STREAMS->value],
            array_map(
                static fn (array $row): string => $row['type'],
                $this->getConnection()->fetchAllAssociative('SELECT type FROM ActivityScan WHERE activityId = :activityId', ['activityId' => (string) $activityId]),
            ),
            'The stream marker keeps the file activity from being fetched from Strava, the custom segment marker has to go'
        );

        // The state the merge replaced stays reachable.
        $revisions = $this->getContainer()->get(ActivityRevisionRepository::class)->findForActivity($activityId);
        $this->assertCount(1, $revisions);
        $revision = $revisions->getFirst();
        $this->assertNotNull($revision);
        $this->assertSame(1, $revision->getRevisionNumber());
        $this->assertSame(50, $revision->getSummary()->getDistanceInMetres());
        $this->assertSame(10, $revision->getSummary()->getElapsedTimeInSeconds());
        $this->assertNotNull(
            $revision->getGeneratedByFileImportId(),
            'A version can only be restored while the bytes behind it are in the import log'
        );

        $fileImport = $this->getConnection()->fetchAssociative('SELECT * FROM FileImport WHERE originalFilename = :filename', ['filename' => 'ride-full-sync.tcx']);
        $this->assertIsArray($fileImport);
        $this->assertSame(FileImportStatus::SUCCESS->value, $fileImport['status']);
        $this->assertSame((string) $activityId, $fileImport['activityId'], 'The log row points at the activity it merged into');
        $this->assertSame($extended, CompressedString::fromCompressed($fileImport['fileContents'])->uncompress());
        $this->assertFalse($this->watchStorage->fileExists('watch/ride-full-sync.tcx'));

        $this->assertStringContainsString(
            '  => [1/1] Merged "ride-full-sync.tcx" into activity "Night Ride - 08-09-2021"',
            (string) $output,
        );
    }

    public function testHandleMergingAFileThatAddsNothingRecordsNoVersion(): void
    {
        $this->watchStorage->write('watch/ride.tcx', $this->fixture('activity.tcx'));
        $this->handler->handle(new ImportActivityFiles(new SpyOutput(), ImportedActivities::empty()));

        $activityId = ActivityId::fromString((string) $this->getConnection()->fetchOne('SELECT activityId FROM Activity'));

        // Same ride, different bytes: only the author line differs, so nothing
        // about the activity changes.
        $this->watchStorage->write('watch/ride-renamed.tcx', str_replace(
            '<Name>Garmin Edge 530</Name>',
            '<Name>Garmin Edge 530 ANT+</Name>',
            $this->fixture('activity.tcx'),
        ));
        $this->handler->handle(new ImportActivityFiles(new SpyOutput(), ImportedActivities::empty()));

        $this->assertSame('1', (string) $this->getConnection()->executeQuery('SELECT COUNT(*) FROM Activity')->fetchOne());
        $this->assertSame(
            0,
            (int) $this->getConnection()->executeQuery('SELECT COUNT(*) FROM ActivityRevision')->fetchOne(),
            'A history the user has to read for no reason is worse than no history'
        );
        $this->assertSame(
            [120, 130],
            $this->getContainer()->get(ActivityStreamRepository::class)->findByActivityId($activityId)->filterOnType(StreamType::HEART_RATE)?->getData(),
            'The same samples in, the same stream out',
        );
    }

    public function testHandleIgnoresFilesThatAreNotActivityFiles(): void
    {
        $this->watchStorage->write('watch/notes.txt', 'just some text');
        $this->watchStorage->write('watch/notes.gz', 'not a gzipped activity');
        $this->watchStorage->write('watch/ride.tcx', $this->fixture('activity.tcx'));

        $output = new SpyOutput();
        $this->handler->handle(new ImportActivityFiles($output, ImportedActivities::empty()));

        $fileImports = $this->getConnection()
            ->executeQuery('SELECT * FROM FileImport ORDER BY importedOn ASC')
            ->fetchAllAssociative();
        $this->assertCount(1, $fileImports);
        $this->assertSame(FileImportStatus::SUCCESS->value, $fileImports[0]['status']);
        $this->assertTrue($this->watchStorage->fileExists('watch/notes.txt'), 'A file that is not an activity file should be left alone');
        $this->assertTrue($this->watchStorage->fileExists('watch/notes.gz'), 'A file that is not an activity file should be left alone');
        $this->assertFalse($this->watchStorage->fileExists('watch/ride.tcx'));
        $this->assertSame(
            implode("\n", [
                'Importing activity files...',
                '  => Ignored 2 file(s) in "watch" that are not activity files: notes.gz, notes.txt',
                '  => [1/1] Imported "ride.tcx" as activity "Night Ride - 08-09-2021"',
                '  => Imported 1, skipped 0, failed 0 activity file(s)',
            ]),
            (string) $output,
        );
    }

    public function testHandleRecordsFailureForCorruptFile(): void
    {
        $this->watchStorage->write('watch/broken.tcx', 'this is not valid xml');

        $output = new SpyOutput();
        $this->handler->handle(new ImportActivityFiles($output, ImportedActivities::empty()));

        $fileImports = $this->getConnection()
            ->executeQuery('SELECT * FROM FileImport ORDER BY importedOn ASC')
            ->fetchAllAssociative();
        $this->assertCount(1, $fileImports);
        $this->assertSame(FileImportStatus::FAILED->value, $fileImports[0]['status']);
        $this->assertNull($fileImports[0]['activityId']);
        $this->assertSame('this is not valid xml', CompressedString::fromCompressed($fileImports[0]['fileContents'])->uncompress());

        $this->assertSame(
            implode("\n", [
                'Importing activity files...',
                '  => <error>[1/1] Could not import "broken.tcx": "broken.tcx" is not valid TCX XML</error>',
                '  => Imported 0, skipped 0, failed 1 activity file(s)',
            ]),
            (string) $output,
        );
    }

    public function testHandleWithoutImportDirectoryIsNoOp(): void
    {
        $output = new SpyOutput();
        $this->handler->handle(new ImportActivityFiles($output, ImportedActivities::empty()));

        $this->assertCount(0, $this->getConnection()
            ->executeQuery('SELECT * FROM FileImport ORDER BY importedOn ASC')
            ->fetchAllAssociative());
        $this->assertSame(
            implode("\n", [
                'Importing activity files...',
                '  => No "watch" directory found, nothing to import',
            ]),
            (string) $output,
        );
    }

    public function testHandleKeepsRenderedPagesBecauseImportedFilesCarryNoImages(): void
    {
        $cacheability = Cacheability::for('stub', CacheTags::of(RootCacheTag::ACTIVITY_IMAGES));
        $render = new RenderStub();
        $this->cacheableRenderer->render($cacheability, $render(...));

        $this->watchStorage->write('watch/ride.tcx', $this->fixture('activity.tcx'));
        $this->handler->handle(new ImportActivityFiles(new SpyOutput(), ImportedActivities::empty()));

        $this->cacheableRenderer->render($cacheability, $render(...));
        $this->assertEquals(1, $render->renderCount);
    }

    /**
     * The rows that only exist because the stored streams did, plus the markers
     * and the derived stream a merge has to invalidate, so the sweep has proof
     * of having run.
     */
    private function addRowsDerivedFromTheStreams(ActivityId $activityId): void
    {
        $this->getContainer()->get(ActivitySplitRepository::class)->add(ActivitySplit::create(
            activityId: $activityId,
            unitSystem: UnitSystem::METRIC,
            splitNumber: 1,
            distance: Meter::from(50),
            elapsedTimeInSeconds: 10,
            movingTimeInSeconds: 10,
            elevationDifference: Meter::from(10),
            averageSpeed: MetersPerSecond::from(5),
            minAverageSpeed: MetersPerSecond::from(5),
            maxAverageSpeed: MetersPerSecond::from(6),
            paceZone: 1,
        ));

        $this->getContainer()->get(CombinedActivityStreamRepository::class)->add(CombinedActivityStream::create(
            activityId: $activityId,
            unitSystem: UnitSystem::METRIC,
            streamTypes: CombinedStreamTypes::fromArray([CombinedStreamType::WATTS]),
            data: [[0, 200], [10, 210]],
            maxYAxisValue: 210,
        ));

        $this->getContainer()->get(ActivityStreamMetricRepository::class)->add(ActivityStreamMetric::create(
            activityId: $activityId,
            streamType: StreamType::WATTS,
            metricType: ActivityStreamMetricType::NORMALIZED_POWER,
            data: ['normalizedPower' => 205],
        ));

        $this->getContainer()->get(ActivityBestEffortRepository::class)->add(ActivityBestEffort::create(
            activityId: $activityId,
            distanceInMeter: Meter::from(50),
            sportType: SportType::RIDE,
            timeInSeconds: 10,
        ));

        $segment = SegmentBuilder::fromDefaults()->build();
        $this->getContainer()->get(SegmentRepository::class)->add($segment);
        $this->getContainer()->get(SegmentEffortRepository::class)->add(SegmentEffort::create(
            segmentEffortId: SegmentEffortId::fromUnprefixed('1'),
            segment: $segment,
            activityId: $activityId,
            startDateTime: SerializableDateTime::fromString('2021-09-08 00:00:00'),
            elapsedTimeInSeconds: 10,
            distance: Kilometer::from(0.05),
            averageWatts: 205,
            averageHeartRate: 125,
            maxHeartRate: 130,
        ));

        // The stream marker is already there from the import. This is the other
        // one, and unlike the stream marker it has to go.
        $this->getContainer()->get(ActivityScanRepository::class)->add(ActivityScan::create(
            activityId: $activityId,
            type: ActivityScanType::CUSTOM_SEGMENT,
            subjectId: 'custom-segment-1',
        ));

        // A MOVING stream built off the speed samples as they were before the
        // merge, which is exactly the kind of row that cannot be trusted after one.
        $this->getContainer()->get(ActivityStreamRepository::class)->add(ActivityStream::create(
            activityId: $activityId,
            streamType: StreamType::MOVING,
            streamData: [true, true],
            createdOn: SerializableDateTime::fromString('2023-10-17 16:15:04'),
        ));
    }

    private function countRows(string $table, ActivityId $activityId): int
    {
        return (int) $this->getConnection()->executeQuery(
            sprintf('SELECT COUNT(*) FROM %s WHERE activityId = :activityId', $table),
            ['activityId' => (string) $activityId],
        )->fetchOne();
    }

    /**
     * The same ride from a sync that finished later: ten more seconds of
     * timeline, one more trackpoint, and a corrected heart rate sample. The
     * distance stays inside the floor the identity matcher allows, so the file
     * has to merge into the activity the first file already created.
     */
    private function extendedFixture(): string
    {
        $extraTrackpoint = implode("\n", [
            '          <Trackpoint>',
            '            <Time>2021-09-08T00:00:20Z</Time>',
            '            <Position>',
            '              <LatitudeDegrees>45.2</LatitudeDegrees>',
            '              <LongitudeDegrees>22.7</LongitudeDegrees>',
            '            </Position>',
            '            <AltitudeMeters>120</AltitudeMeters>',
            '            <DistanceMeters>90</DistanceMeters>',
            '            <HeartRateBpm><Value>140</Value></HeartRateBpm>',
            '            <Cadence>84</Cadence>',
            '            <Extensions>',
            '              <ns3:TPX>',
            '                <ns3:Speed>6.5</ns3:Speed>',
            '                <ns3:Watts>220</ns3:Watts>',
            '              </ns3:TPX>',
            '            </Extensions>',
            '          </Trackpoint>',
        ]);

        $extended = str_replace(
            [
                "<TotalTimeSeconds>10</TotalTimeSeconds>\n        <DistanceMeters>50</DistanceMeters>",
                '<HeartRateBpm><Value>130</Value></HeartRateBpm>',
                "          </Trackpoint>\n        </Track>",
            ],
            [
                "<TotalTimeSeconds>20</TotalTimeSeconds>\n        <DistanceMeters>90</DistanceMeters>",
                '<HeartRateBpm><Value>135</Value></HeartRateBpm>',
                '          </Trackpoint>'."\n".$extraTrackpoint."\n        </Track>",
            ],
            $this->fixture('activity.tcx'),
        );
        $this->assertNotSame($this->fixture('activity.tcx'), $extended, 'The extended fixture has to be a different file');

        return $extended;
    }

    private function fixture(string $name): string
    {
        $projectDir = $this->getContainer()->get(KernelProjectDir::class);
        $contents = file_get_contents($projectDir.'/tests/Domain/Import/FileParser/fixtures/'.$name);
        if (false === $contents) {
            self::fail(sprintf('Could not read fixture "%s"', $name));
        }

        return $contents;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = $this->getContainer()->get(ImportActivityFilesCommandHandler::class);
        $this->watchStorage = $this->getContainer()->get('default.storage');
        $this->cacheableRenderer = $this->getContainer()->get(CacheableRenderer::class);
        $this->getContainer()->get(RenderCache::class)->clear();
        $this->getConnection()->executeStatement(
            'INSERT INTO KeyValue (`key`, `value`) VALUES (:key, :value)',
            ['key' => 'lock.importData', 'value' => '{"lockAcquiredBy": "test"}']
        );
    }

    protected function tearDown(): void
    {
        $this->watchStorage->deleteDirectory('watch');

        parent::tearDown();
    }
}
