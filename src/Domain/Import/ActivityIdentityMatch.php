<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Activity\ActivityId;

/**
 * What the identity layers made of a file: a new activity, the identical file
 * of one already imported, or a file that belongs to an existing activity and
 * has data to add to it.
 */
final readonly class ActivityIdentityMatch
{
    private function __construct(
        private ?ActivityId $activityId,
        private bool $fileIsIdentical,
    ) {
    }

    public static function forNewActivity(): self
    {
        return new self(null, false);
    }

    public static function forIdenticalFile(ActivityId $activityId): self
    {
        return new self($activityId, true);
    }

    public static function forExistingActivity(ActivityId $activityId): self
    {
        return new self($activityId, false);
    }

    public function isNewActivity(): bool
    {
        return !$this->activityId instanceof ActivityId;
    }

    /**
     * The same bytes under another name. There is nothing to add to the activity
     * it came from, so this is the one case that is a plain skip.
     */
    public function isIdenticalFile(): bool
    {
        return $this->fileIsIdentical;
    }

    public function getActivityId(): ?ActivityId
    {
        return $this->activityId;
    }
}
