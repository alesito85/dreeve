<?php

declare(strict_types=1);

namespace App\Application\Import\FileImport\ImportActivityFiles\Pipeline;

use App\Domain\Activity\Activity;
use App\Domain\Activity\ActivityId;
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
use App\Domain\Activity\Stream\ActivityStream;
use App\Domain\Activity\Stream\ActivityStreamMerger;
use App\Domain\Activity\Stream\ActivityStreamRepository;
use App\Domain\Activity\Stream\ActivityStreams;
use App\Domain\Activity\Stream\CombinedStream\CombinedActivityStreamRepository;
use App\Domain\Activity\Stream\Metric\ActivityStreamMetricRepository;
use App\Domain\Activity\Stream\StreamType;
use App\Domain\Import\FileImportId;
use App\Domain\Import\FileImportRepository;
use App\Domain\Segment\SegmentEffort\SegmentEffortRepository;
use App\Infrastructure\Time\Clock\Clock;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Writes a file's data onto the activity it belongs to.
 *
 * Two ways to do that. A re-import adds what it has to what is stored, the newer
 * file winning wherever both have a value. A restore replaces instead, because
 * the file it replays is the whole truth about the version being switched to.
 * Both retire the state they replace into the revision history first.
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

        if ($context->isReplace()) {
            // A restore. The replayed file is the whole truth about the version
            // being switched to, so its streams are stored as they are instead of
            // being folded into what a newer file brought. A stream the replayed
            // file never had leaves the live activity, which is what going back
            // to a version means, and it is there again as soon as the version
            // that had it is restored.
            $result = $existing->replacedWith($incoming);
            $resultStreams = $this->rekey($existing, $context->getStreams());
        } else {
            $result = $existing->mergeWith($incoming);
            $resultStreams = $this->activityStreamMerger->merge(
                activityId: $existing->getId(),
                existingStreams: $existingStreams,
                existingStart: $existing->getStartDate(),
                incomingStreams: $context->getStreams(),
                incomingStart: $incoming->getStartDate(),
            );
        }

        // The state that is about to be replaced goes into the history first, so
        // a merge is never the end of it. A merge that changes nothing records
        // nothing, otherwise re-importing the same file fills the history with
        // versions that are all the same ride.
        $this->recordRetiredState($existing, $existingStreams, $result, $resultStreams);

        $this->activityRepository->update(ActivityWithRawData::fromState(
            activity: $result,
            rawData: $mergeTarget->getRawData(),
        ));

        // add() is an insert on a primary key of activity and stream type, so the
        // new streams go in after the stored ones are gone.
        $this->activityStreamRepository->deleteForActivity($existing->getId());
        foreach ($resultStreams as $stream) {
            $this->activityStreamRepository->add($stream);
        }

        $this->replaceLaps($existing, $context);
        $this->replaceDrivetrainUsages($existing, $context);
        $this->invalidateDerivedData($existing);

        return $context->withActivity($result);
    }

    /**
     * The streams as the replayed file produced them, owned by the activity they
     * now describe. MOVING is left out because it is derived from speed and
     * CalculateMovingStream rebuilds it from the timeline that is stored now.
     */
    private function rekey(Activity $existing, ActivityStreams $streams): ActivityStreams
    {
        $rekeyed = ActivityStreams::empty();
        foreach ($streams as $stream) {
            if (StreamType::MOVING === $stream->getStreamType()) {
                continue;
            }

            $rekeyed->add(ActivityStream::fromState(
                activityId: $existing->getId(),
                streamType: $stream->getStreamType(),
                streamData: $stream->getData(),
                createdOn: $stream->getCreatedOn(),
            ));
        }

        return $rekeyed;
    }

    private function recordRetiredState(
        Activity $existing,
        ActivityStreams $existingStreams,
        Activity $result,
        ActivityStreams $resultStreams,
    ): void {
        $retired = ActivityRevisionSummary::fromActivity($existing, $existingStreams);
        if ($retired->equals(ActivityRevisionSummary::fromActivity($result, $resultStreams))) {
            return;
        }

        $this->activityRevisionRepository->add(ActivityRevision::create(
            activityId: $existing->getId(),
            revisionNumber: $this->activityRevisionRepository->findNextRevisionNumberForActivity($existing->getId()),
            summary: $retired,
            generatedByFileImportId: $this->findImportThatProduced($existing->getId(), $retired),
            createdOn: $this->clock->getCurrentDateTimeImmutable(),
        ));
    }

    /**
     * Which file's bytes the live numbers came from, which is what makes a
     * version restorable.
     *
     * The last successful import is the obvious answer and the right one until a
     * restore has put the activity back into an older state, after which the
     * newest import no longer describes it. So a version with the same summary
     * gets to answer first, it already knows its file.
     */
    private function findImportThatProduced(ActivityId $activityId, ActivityRevisionSummary $retired): ?FileImportId
    {
        $match = $this->activityRevisionRepository->findForActivity($activityId)
            ->reverse()
            ->find(fn (ActivityRevision $revision): bool => $revision->getSummary()->equals($retired));

        if ($match instanceof ActivityRevision) {
            return $match->getGeneratedByFileImportId();
        }

        return $this->fileImportRepository->findMostRecentSuccessfulForActivity($activityId)?->getId();
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
