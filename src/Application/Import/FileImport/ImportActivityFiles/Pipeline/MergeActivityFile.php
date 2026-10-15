<?php

declare(strict_types=1);

namespace App\Application\Import\FileImport\ImportActivityFiles\Pipeline;

use App\Domain\Activity\Activity;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Activity\BestEffort\ActivityBestEffortRepository;
use App\Domain\Activity\Lap\ActivityLapRepository;
use App\Domain\Activity\Revision\ActivityRevision;
use App\Domain\Activity\Revision\ActivityRevisionRepository;
use App\Domain\Activity\Revision\ActivityRevisionSummary;
use App\Domain\Activity\Scan\ActivityScanRepository;
use App\Domain\Activity\Scan\ActivityScanType;
use App\Domain\Activity\Shifting\ActivityDrivetrainUsageRepository;
use App\Domain\Activity\Split\ActivitySplitRepository;
use App\Domain\Activity\Stream\ActivityStreamRepository;
use App\Domain\Activity\Stream\ActivityStreamMerger;
use App\Domain\Activity\Stream\ActivityStreams;
use App\Domain\Activity\Stream\CombinedStream\CombinedActivityStreamRepository;
use App\Domain\Activity\Stream\Metric\ActivityStreamMetricRepository;
use App\Domain\Import\FileImportRepository;
use App\Domain\Segment\SegmentEffort\SegmentEffortRepository;
use App\Infrastructure\Time\Clock\Clock;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Adds what a re-imported file has to the activity it belongs to.
 *
 * The activity stays one row. Everything the file makes redundant is rebuilt
 * afterwards by the metrics steps, which select their own pending work, so this
 * step only has to delete what the new streams invalidate. It runs last, once
 * the attribute steps have had their say about the parsed activity.
 */
#[AsTaggedItem(priority: 40)]
final readonly class MergeActivityFile implements ImportActivityFileStep
{
    public function __construct(
        private ActivityRepository $activityRepository,
        private ActivityStreamRepository $activityStreamRepository,
        private ActivityStreamMerger $activityStreamMerger,
        private ActivityLapRepository $activityLapRepository,
        private ActivityDrivetrainUsageRepository $activityDrivetrainUsageRepository,
        private ActivityRevisionRepository $activityRevisionRepository,
        private FileImportRepository $fileImportRepository,
        private CombinedActivityStreamRepository $combinedActivityStreamRepository,
        private ActivityStreamMetricRepository $activityStreamMetricRepository,
        private ActivitySplitRepository $activitySplitRepository,
        private ActivityBestEffortRepository $activityBestEffortRepository,
        private SegmentEffortRepository $segmentEffortRepository,
        private ActivityScanRepository $activityScanRepository,
        private Clock $clock,
    ) {
    }

    public function process(ActivityImportContext $context): ActivityImportContext
    {
        $mergeTarget = $context->getMergeTarget();
        if (!$mergeTarget instanceof ActivityWithRawData) {
            return $context;
        }

        $existing = $mergeTarget->getActivity();
        $incoming = $context->getActivity() ?? throw new \RuntimeException('Activity not set on $context');
        $existingStreams = $this->activityStreamRepository->findByActivityId($existing->getId());

        $merged = $existing->mergeWith($incoming);
        $mergedStreams = $this->activityStreamMerger->merge(
            activityId: $existing->getId(),
            existingStreams: $existingStreams,
            existingStart: $existing->getStartDate(),
            incomingStreams: $context->getStreams(),
            incomingStart: $incoming->getStartDate(),
        );

        // The state that is about to be replaced goes into the history first, so
        // a merge is never the end of it. A merge that changes nothing records
        // nothing, otherwise re-importing the same file fills the history with
        // versions that are all the same ride.
        $this->recordRetiredState($existing, $existingStreams, $merged, $mergedStreams);

        $this->activityRepository->update(ActivityWithRawData::fromState(
            activity: $merged,
            rawData: $mergeTarget->getRawData(),
        ));

        // add() is an insert on a primary key of activity and stream type, so the
        // merged streams go in after the stored ones are gone.
        $this->activityStreamRepository->deleteForActivity($existing->getId());
        foreach ($mergedStreams as $stream) {
            $this->activityStreamRepository->add($stream);
        }

        $this->replaceLaps($existing, $context);
        $this->replaceDrivetrainUsages($existing, $context);
        $this->invalidateDerivedData($existing);

        return $context->withActivity($merged);
    }

    private function recordRetiredState(
        Activity $existing,
        ActivityStreams $existingStreams,
        Activity $merged,
        ActivityStreams $mergedStreams,
    ): void {
        $retired = ActivityRevisionSummary::fromActivity($existing, $existingStreams);
        if ($retired->equals(ActivityRevisionSummary::fromActivity($merged, $mergedStreams))) {
            return;
        }

        $this->activityRevisionRepository->add(ActivityRevision::create(
            activityId: $existing->getId(),
            revisionNumber: $this->activityRevisionRepository->findNextRevisionNumberForActivity($existing->getId()),
            summary: $retired,
            generatedByFileImportId: $this->fileImportRepository->findMostRecentSuccessfulForActivity($existing->getId())?->getId(),
            createdOn: $this->clock->getCurrentDateTimeImmutable(),
        ));
    }

    private function replaceLaps(Activity $existing, ActivityImportContext $context): void
    {
        if ($context->getLaps()->isEmpty()) {
            // A file that says nothing about laps must not delete the ones that
            // are stored.
            return;
        }

        $this->activityLapRepository->deleteForActivity($existing->getId());
        foreach ($context->getLaps() as $lap) {
            $this->activityLapRepository->add($lap->withActivityId($existing->getId()));
        }
    }

    private function replaceDrivetrainUsages(Activity $existing, ActivityImportContext $context): void
    {
        if ($context->getDrivetrainUsages()->isEmpty()) {
            return;
        }

        $this->activityDrivetrainUsageRepository->deleteForActivity($existing->getId());
        foreach ($context->getDrivetrainUsages() as $drivetrainUsage) {
            $this->activityDrivetrainUsageRepository->add($drivetrainUsage->withActivityId($existing->getId()));
        }
    }

    /**
     * Everything derived from the streams is dropped and rebuilt by the steps
     * that already pick their own pending work. Milestones and gear stats read
     * the activity columns live, so they follow the new totals on their own.
     */
    private function invalidateDerivedData(Activity $existing): void
    {
        $this->combinedActivityStreamRepository->deleteForActivity($existing->getId());
        $this->activityStreamMetricRepository->deleteForActivity($existing->getId());
        $this->activitySplitRepository->deleteForActivity($existing->getId());
        $this->activityBestEffortRepository->deleteForActivity($existing->getId());
        $this->segmentEffortRepository->deleteForActivity($existing->getId());
        // Only the custom segment markers, the stream markers are what keeps a
        // file activity from being fetched from Strava again.
        $this->activityScanRepository->deleteForActivityAndType($existing->getId(), ActivityScanType::CUSTOM_SEGMENT);
    }
}
