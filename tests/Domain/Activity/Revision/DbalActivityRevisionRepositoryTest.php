<?php

declare(strict_types=1);

namespace App\Tests\Domain\Activity\Revision;

use App\Domain\Activity\Activity;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\Revision\ActivityRevision;
use App\Domain\Activity\Revision\ActivityRevisionId;
use App\Domain\Activity\Revision\ActivityRevisionSummary;
use App\Domain\Activity\Revision\DbalActivityRevisionRepository;
use App\Domain\Activity\Stream\ActivityStreams;
use App\Domain\Activity\Stream\StreamType;
use App\Domain\Import\FileImportId;
use App\Infrastructure\Measurement\Length\Kilometer;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use App\Tests\Domain\Activity\ActivityBuilder;
use App\Tests\Domain\Activity\Stream\ActivityStreamBuilder;

class DbalActivityRevisionRepositoryTest extends ContainerTestCase
{
    private DbalActivityRevisionRepository $activityRevisionRepository;

    public function testAddAndFindForActivity(): void
    {
        $activity = ActivityBuilder::fromDefaults()
            ->withDistance(Kilometer::from(42))
            ->withAveragePower(150)
            ->build();
        $summary = ActivityRevisionSummary::fromActivity($activity, $this->streams($activity, [
            StreamType::TIME->value => [0, 1, 2],
            StreamType::WATTS->value => [100, 200, 300],
        ]));

        $revision = ActivityRevision::create(
            activityId: $activity->getId(),
            revisionNumber: 1,
            summary: $summary,
            generatedByFileImportId: FileImportId::fromUnprefixed('the-file-that-made-it'),
            createdOn: SerializableDateTime::fromString('2023-10-17 16:15:04'),
        );
        $this->activityRevisionRepository->add($revision);

        $found = $this->activityRevisionRepository->findForActivity($activity->getId())->getFirst();
        $this->assertNotNull($found);
        $this->assertEquals($revision->getId(), $found->getId());
        $this->assertEquals($activity->getId(), $found->getActivityId());
        $this->assertSame(1, $found->getRevisionNumber());
        $this->assertEquals(FileImportId::fromUnprefixed('the-file-that-made-it'), $found->getGeneratedByFileImportId());
        $this->assertEquals(SerializableDateTime::fromString('2023-10-17 16:15:04'), $found->getCreatedOn());

        $stored = $found->getSummary();
        $this->assertTrue($summary->equals($stored));
        $this->assertSame(42000, $stored->getDistanceInMetres());
        $this->assertSame(150, $stored->getAveragePower());
        $this->assertNull($stored->getKilojoules());
        $this->assertSame(['time' => 3, 'watts' => 3], $stored->getStreamSamples());

        // The chips the version list shows: what a newer version has that the
        // one before it did not.
        $this->assertEquals(
            [StreamType::LEFT_PEDAL_SMOOTHNESS],
            ActivityRevisionSummary::fromActivity($activity, $this->streams($activity, [
                StreamType::TIME->value => [0, 1, 2],
                StreamType::WATTS->value => [100, 200, 300],
                StreamType::LEFT_PEDAL_SMOOTHNESS->value => [30, 31, 32],
            ]))->streamTypesGainedComparedTo($stored),
        );
    }

    public function testARevisionWithoutAFileBehindItStaysImpossibleToReplay(): void
    {
        $activity = ActivityBuilder::fromDefaults()->build();
        $this->activityRevisionRepository->add(ActivityRevision::create(
            activityId: $activity->getId(),
            revisionNumber: 1,
            summary: ActivityRevisionSummary::fromActivity($activity, ActivityStreams::empty()),
            generatedByFileImportId: null,
            createdOn: SerializableDateTime::fromString('2023-10-17 16:15:04'),
        ));

        $this->assertNull(
            $this->activityRevisionRepository->findForActivity($activity->getId())->getFirst()?->getGeneratedByFileImportId()
        );
    }

    public function testFindForActivityReturnsTheHistoryOldestFirst(): void
    {
        $activity = ActivityBuilder::fromDefaults()->build();
        $summary = ActivityRevisionSummary::fromActivity($activity, ActivityStreams::empty());
        // History is written as it happens, but reading it has to show the story
        // in the order it happened.
        foreach ([3, 1, 2] as $revisionNumber) {
            $this->activityRevisionRepository->add(ActivityRevision::fromState(
                revisionId: ActivityRevisionId::fromUnprefixed((string) $revisionNumber),
                activityId: $activity->getId(),
                revisionNumber: $revisionNumber,
                summary: $summary,
                generatedByFileImportId: null,
                createdOn: SerializableDateTime::fromString('2023-10-17 16:15:04'),
            ));
        }

        $this->assertSame(
            [1, 2, 3],
            array_map(
                fn (ActivityRevision $revision): int => $revision->getRevisionNumber(),
                $this->activityRevisionRepository->findForActivity($activity->getId())->toArray(),
            ),
        );
    }

    public function testFindForActivityOnlySeesTheHistoryOfThatActivity(): void
    {
        $summary = ActivityRevisionSummary::fromActivity(ActivityBuilder::fromDefaults()->build(), ActivityStreams::empty());
        $this->activityRevisionRepository->add(ActivityRevision::create(
            activityId: ActivityId::fromUnprefixed('other'),
            revisionNumber: 1,
            summary: $summary,
            generatedByFileImportId: null,
            createdOn: SerializableDateTime::fromString('2023-10-17 16:15:04'),
        ));

        $this->assertTrue($this->activityRevisionRepository->findForActivity(ActivityId::fromUnprefixed('this-one'))->isEmpty());
        $this->assertSame(1, $this->activityRevisionRepository->findNextRevisionNumberForActivity(ActivityId::fromUnprefixed('this-one')));
    }

    public function testFindNextRevisionNumberForActivity(): void
    {
        $activity = ActivityBuilder::fromDefaults()->build();
        $summary = ActivityRevisionSummary::fromActivity($activity, ActivityStreams::empty());
        $this->assertSame(1, $this->activityRevisionRepository->findNextRevisionNumberForActivity($activity->getId()));

        foreach ([1, 2] as $revisionNumber) {
            $this->activityRevisionRepository->add(ActivityRevision::fromState(
                revisionId: ActivityRevisionId::fromUnprefixed((string) $revisionNumber),
                activityId: $activity->getId(),
                revisionNumber: $revisionNumber,
                summary: $summary,
                generatedByFileImportId: null,
                createdOn: SerializableDateTime::fromString('2023-10-17 16:15:04'),
            ));
        }

        $this->assertSame(3, $this->activityRevisionRepository->findNextRevisionNumberForActivity($activity->getId()));
    }

    /**
     * @param array<string, array<int, mixed>> $dataByStreamType
     */
    private function streams(Activity $activity, array $dataByStreamType): ActivityStreams
    {
        $streams = ActivityStreams::empty();
        foreach ($dataByStreamType as $streamType => $data) {
            $streams->add(ActivityStreamBuilder::fromDefaults()
                ->withActivityId($activity->getId())
                ->withStreamType(StreamType::from($streamType))
                ->withData($data)
                ->build());
        }

        return $streams;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->activityRevisionRepository = new DbalActivityRevisionRepository($this->getConnection());
    }
}
