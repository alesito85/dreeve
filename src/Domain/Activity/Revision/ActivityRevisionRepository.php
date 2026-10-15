<?php

declare(strict_types=1);

namespace App\Domain\Activity\Revision;

use App\Domain\Activity\ActivityId;

interface ActivityRevisionRepository
{
    public function add(ActivityRevision $activityRevision): void;

    public function findForActivity(ActivityId $activityId): ActivityRevisions;

    /**
     * Revisions are numbered per activity and never reused, so the next number
     * is one past the highest stored one.
     */
    public function findNextRevisionNumberForActivity(ActivityId $activityId): int;
}
