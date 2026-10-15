<?php

declare(strict_types=1);

namespace App\Application\Import\FileImport\ImportActivityFiles\Pipeline;

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
    ) {
    }

    public function process(ActivityImportContext $context): ActivityImportContext
    {
        $activity = $context->getActivity() ?? throw new \RuntimeException('Activity not set on $context');

        $match = $this->activityIdentityMatcher->match($activity);
        if ($match->isNewActivity()) {
            return $context;
        }

        // Nothing may put a second row in the database for an activity Dreeve
        // already has, and a file that belongs to one usually has data to add to
        // it. Until that merge exists, leaving the activity alone is the only
        // outcome that is safe for both an identical file and a matching one.
        throw new SkipDuplicateActivity();
    }
}
