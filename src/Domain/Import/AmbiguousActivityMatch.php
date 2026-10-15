<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Activity\ActivityId;

/**
 * More than one activity fits a file that carries no device identity, so there
 * is no defensible answer to which one it belongs to. Importing it would add a
 * second row for a ride that is already in the database, which is the one thing
 * an import may never do, so the file is left for a human to decide on.
 */
final class AmbiguousActivityMatch extends \RuntimeException
{
    /**
     * @param list<ActivityId> $candidateIds
     */
    public function __construct(
        private readonly array $candidateIds,
    ) {
        parent::__construct(sprintf(
            'Could not match this file to a single activity, %d activities fit it as well as each other',
            count($candidateIds),
        ));
    }

    /**
     * @return list<ActivityId>
     */
    public function getCandidateIds(): array
    {
        return $this->candidateIds;
    }
}
