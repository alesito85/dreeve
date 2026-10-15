<?php

declare(strict_types=1);

namespace App\Application\Import\FileImport\ImportActivityFiles\Pipeline;

use App\Domain\Activity\ActivityRepository;
use App\Domain\Import\ActivityIdentityMatcher;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Works out whether the parsed file belongs to an activity that is already in
 * the database, before any of the steps that touch an activity run.
 */
#[AsTaggedItem(priority: 97)]
final readonly class ResolveActivityIdentity implements ImportActivityFileStep
{
    public function __construct(
        private ActivityIdentityMatcher $activityIdentityMatcher,
        private ActivityRepository $activityRepository,
    ) {
    }

    public function process(ActivityImportContext $context): ActivityImportContext
    {
        $activity = $context->getActivity() ?? throw new \RuntimeException('Activity not set on $context');

        $match = $this->activityIdentityMatcher->match($activity);
        if ($match->isNewActivity()) {
            return $context;
        }

        if ($match->isIdenticalFile()) {
            // The bytes are already in this activity, so a merge would add
            // nothing and the file has no reason to be kept.
            throw new SkipDuplicateActivity();
        }

        // A match that is not the identical file is a file that has something to
        // add. MergeActivityFile does that once the attribute steps have run.
        $existingActivityId = $match->getActivityId() ?? throw new \LogicException('A match that is not a new activity always names one');

        return $context->withMergeTarget($this->activityRepository->findWithRawData($existingActivityId));
    }
}
