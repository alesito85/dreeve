<?php

declare(strict_types=1);

namespace App\Tests\Application\Activity\Revision\SwitchActivityRevision;

use App\Application\Activity\Revision\SwitchActivityRevision\SwitchActivityRevision;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Activity\ImportSource;
use App\Domain\Activity\Revision\ActivityRevision;
use App\Domain\Activity\Revision\ActivityRevisionRepository;
use App\Domain\Activity\Revision\ActivityRevisionSummary;
use App\Domain\Activity\Stream\ActivityStreamRepository;
use App\Domain\Activity\Stream\StreamType;
use App\Domain\Import\FileImportId;
use App\Domain\Import\FileImportRepository;
use App\Domain\Import\FileImportStatus;
use App\Infrastructure\CQRS\Command\Bus\CommandBus;
use App\Infrastructure\CQRS\Command\CouldNotProcessCommand;
use App\Infrastructure\Measurement\Length\Kilometer;
use App\Infrastructure\ValueObject\String\KernelProjectDir;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use App\Tests\Domain\Activity\ActivityBuilder;
use App\Tests\Domain\Activity\Stream\ActivityStreamBuilder;
use App\Tests\Domain\Import\FileImportBuilder;

class SwitchActivityRevisionCommandHandlerTest extends ContainerTestCase
{
    private CommandBus $commandBus;
    private ActivityRepository $activityRepository;
    private ActivityStreamRepository $activityStreamRepository;
    private ActivityRevisionRepository $activityRevisionRepository;
    private FileImportRepository $fileImportRepository;

    public function testHandleReplaysTheFileTheVersionWasMadeOf(): void
    {
        $activityId = ActivityId::fromUnprefixed('the-activity');
        $this->seedTheActivityAsItLooksNow($activityId);

        $this->fileImportRepository->add(
            FileImportBuilder::fromDefaults()
                ->withFileImportId(FileImportId::fromUnprefixed('the-file-that-made-it'))
                ->withOriginalFilename('ride.tcx')
                ->withFileContents($this->fixture('activity.tcx'))
                ->withSource(ImportSource::TCX_FILE)
                ->withStatus(FileImportStatus::SUCCESS)
                ->withActivityId($activityId)
                ->withImportedOn(SerializableDateTime::fromString('2023-10-17 14:00:00'))
                ->build()
        );
        // The import that brought the activity to where it is now. It is the most
        // recent successful one, so it is what the state being retired is tagged
        // with when no version has that summary yet.
        $this->fileImportRepository->add(
            FileImportBuilder::fromDefaults()
                ->withFileImportId(FileImportId::fromUnprefixed('the-later-import'))
                ->withOriginalFilename('ride-full-sync.tcx')
                ->withFileContents('newer bytes')
                ->withSource(ImportSource::TCX_FILE)
                ->withStatus(FileImportStatus::SUCCESS)
                ->withActivityId($activityId)
                ->withImportedOn(SerializableDateTime::fromString('2023-10-17 15:00:00'))
                ->build()
        );

        $this->activityRevisionRepository->add(ActivityRevision::create(
            activityId: $activityId,
            revisionNumber: 1,
            summary: ActivityRevisionSummary::fromArray([
                'startedOn' => '2021-09-08 00:00:00',
                'distanceInMetres' => 50,
                'elapsedTimeInSeconds' => 10,
                'movingTimeInSeconds' => 10,
                'streamSamples' => [StreamType::TIME->value => 2, StreamType::HEART_RATE->value => 2],
            ]),
            generatedByFileImportId: FileImportId::fromUnprefixed('the-file-that-made-it'),
            createdOn: SerializableDateTime::fromString('2023-10-17 14:00:00'),
        ));

        $this->commandBus->dispatch(SwitchActivityRevision::fromPayload([
            'activityId' => (string) $activityId,
            'revisionNumber' => '1',
        ]));

        $this->assertSame('1', (string) $this->getConnection()->executeQuery('SELECT COUNT(*) FROM Activity')->fetchOne());

        // The replayed file is the whole truth about the version, so its numbers
        // are the activity's numbers, not a merge of the two.
        $activity = $this->activityRepository->find($activityId);
        $this->assertEquals(Kilometer::from(0.05), $activity->getDistance());
        $this->assertSame(10, $activity->getElapsedTimeInSeconds());

        $streams = $this->activityStreamRepository->findByActivityId($activityId);
        $this->assertSame([0, 10], $streams->filterOnType(StreamType::TIME)?->getData());
        $this->assertSame([120, 130], $streams->filterOnType(StreamType::HEART_RATE)?->getData());
        $this->assertSame([200, 210], $streams->filterOnType(StreamType::WATTS)?->getData());
        $this->assertNull(
            $streams->filterOnType(StreamType::MOVING),
            'The derived stream has to go, it is rebuilt from the timeline that is stored now'
        );

        // History stays append only: what the switch replaced becomes the next
        // version, so switching back and forth never loses a state.
        $revisions = $this->activityRevisionRepository->findForActivity($activityId);
        $this->assertCount(2, $revisions);
        $retired = $revisions->getLast();
        $this->assertNotNull($retired);
        $this->assertSame(2, $retired->getRevisionNumber());
        $this->assertSame(90, $retired->getSummary()->getDistanceInMetres());
        $this->assertSame(20, $retired->getSummary()->getElapsedTimeInSeconds());
        $this->assertEquals(
            FileImportId::fromUnprefixed('the-later-import'),
            $retired->getGeneratedByFileImportId(),
        );

        $this->assertSame(
            2,
            (int) $this->getConnection()->executeQuery('SELECT COUNT(*) FROM FileImport')->fetchOne(),
            'Switching to a version replays bytes that are already logged, it is not a new import',
        );
    }

    public function testHandleCouldNotSwitchToAVersionThatDoesNotExist(): void
    {
        $activityId = ActivityId::fromUnprefixed('the-activity');
        $this->seedTheActivityAsItLooksNow($activityId);

        $this->expectException(CouldNotProcessCommand::class);
        $this->expectExceptionMessageIsOrContains('has no version 3.');

        $this->commandBus->dispatch(SwitchActivityRevision::fromPayload([
            'activityId' => (string) $activityId,
            'revisionNumber' => '3',
        ]));
    }

    public function testHandleCouldNotSwitchToAVersionWithoutAFileBehindIt(): void
    {
        $activityId = ActivityId::fromUnprefixed('the-activity');
        $this->seedTheActivityAsItLooksNow($activityId);

        $this->activityRevisionRepository->add(ActivityRevision::create(
            activityId: $activityId,
            revisionNumber: 1,
            summary: ActivityRevisionSummary::fromArray(['startedOn' => '2021-09-08 00:00:00']),
            generatedByFileImportId: null,
            createdOn: SerializableDateTime::fromString('2023-10-17 14:00:00'),
        ));

        $this->expectException(CouldNotProcessCommand::class);
        $this->expectExceptionMessageIsOrContains('This version was not made by importing a file, so there are no file bytes to replay.');

        $this->commandBus->dispatch(SwitchActivityRevision::fromPayload([
            'activityId' => (string) $activityId,
            'revisionNumber' => '1',
        ]));
    }

    public function testHandleCouldNotSwitchToAVersionWhoseFileIsNoLongerStored(): void
    {
        $activityId = ActivityId::fromUnprefixed('the-activity');
        $this->seedTheActivityAsItLooksNow($activityId);

        // A version recorded before file contents were archived.
        $this->fileImportRepository->add(
            FileImportBuilder::fromDefaults()
                ->withFileImportId(FileImportId::fromUnprefixed('1'))
                ->withOriginalFilename('ride.tcx')
                ->withStatus(FileImportStatus::SUCCESS)
                ->withActivityId($activityId)
                ->build()
        );
        $this->activityRevisionRepository->add(ActivityRevision::create(
            activityId: $activityId,
            revisionNumber: 1,
            summary: ActivityRevisionSummary::fromArray(['startedOn' => '2021-09-08 00:00:00']),
            generatedByFileImportId: FileImportId::fromUnprefixed('1'),
            createdOn: SerializableDateTime::fromString('2023-10-17 14:00:00'),
        ));

        $this->expectException(CouldNotProcessCommand::class);
        $this->expectExceptionMessageIsOrContains('The file that produced this version is no longer stored, so there is nothing to replay.');

        $this->commandBus->dispatch(SwitchActivityRevision::fromPayload([
            'activityId' => (string) $activityId,
            'revisionNumber' => '1',
        ]));
    }

    /**
     * The activity as it looks after a fuller file merged into it: twenty seconds
     * of timeline where the version only has ten.
     */
    private function seedTheActivityAsItLooksNow(ActivityId $activityId): void
    {
        $this->activityRepository->add(ActivityWithRawData::fromState(
            ActivityBuilder::fromDefaults()
                ->withActivityId($activityId)
                ->withDistance(Kilometer::from(0.09))
                ->withElapsedTimeInSeconds(20)
                ->withMovingTimeInSeconds(20)
                ->withImportSource(ImportSource::TCX_FILE)
                ->build(),
            ['raw' => 'data'],
        ));

        foreach ([
            [StreamType::TIME, [0, 10, 20]],
            [StreamType::HEART_RATE, [120, 135, 140]],
            [StreamType::WATTS, [200, 210, 220]],
            [StreamType::MOVING, [true, true, true]],
        ] as [$streamType, $data]) {
            $this->activityStreamRepository->add(
                ActivityStreamBuilder::fromDefaults()
                    ->withActivityId($activityId)
                    ->withStreamType($streamType)
                    ->withData($data)
                    ->build()
            );
        }
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

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->commandBus = $this->getContainer()->get(CommandBus::class);
        $this->activityRepository = $this->getContainer()->get(ActivityRepository::class);
        $this->activityStreamRepository = $this->getContainer()->get(ActivityStreamRepository::class);
        $this->activityRevisionRepository = $this->getContainer()->get(ActivityRevisionRepository::class);
        $this->fileImportRepository = $this->getContainer()->get(FileImportRepository::class);
    }
}
