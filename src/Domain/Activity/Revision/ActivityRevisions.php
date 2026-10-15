<?php

declare(strict_types=1);

namespace App\Domain\Activity\Revision;

use App\Infrastructure\ValueObject\Collection;

/**
 * @extends Collection<ActivityRevision>
 */
class ActivityRevisions extends Collection
{
    public function getItemClassName(): string
    {
        return ActivityRevision::class;
    }
}
