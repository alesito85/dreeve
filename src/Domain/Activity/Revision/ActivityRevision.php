<?php

declare(strict_types=1);

namespace App\Domain\Activity\Revision;

use App\Domain\Activity\ActivityId;
use App\Domain\Import\FileImportId;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Doctrine\ORM\Mapping as ORM;

/**
 * The state an activity had before something replaced it.
 *
 * History rows next to one live activity, never a second activity: every read
 * path in the app queries Activity, and a duplicate row there is what this whole
 * feature exists to prevent. A revision is append only, so going back to an old
 * version adds a revision rather than deleting one.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ActivityRevision')]
#[ORM\Index(name: 'ActivityRevision_activity', columns: ['activityId', 'revisionNumber'])]
final readonly class ActivityRevision
{
    private function __construct(
        #[ORM\Id, ORM\Column(type: 'string', unique: true)]
        private ActivityRevisionId $revisionId,
        #[ORM\Column(type: 'string')]
        private ActivityId $activityId,
        #[ORM\Column(type: 'integer')]
        private int $revisionNumber,
        #[ORM\Column(type: 'json')]
        private ActivityRevisionSummary $summary,
        #[ORM\Column(type: 'string', nullable: true)]
        private ?FileImportId $generatedByFileImportId,
        #[ORM\Column(type: 'datetime_immutable')]
        private SerializableDateTime $createdOn,
    ) {
    }

    public static function create(
        ActivityId $activityId,
        int $revisionNumber,
        ActivityRevisionSummary $summary,
        ?FileImportId $generatedByFileImportId,
        SerializableDateTime $createdOn,
    ): self {
        return new self(
            revisionId: ActivityRevisionId::random(),
            activityId: $activityId,
            revisionNumber: $revisionNumber,
            summary: $summary,
            generatedByFileImportId: $generatedByFileImportId,
            createdOn: $createdOn,
        );
    }

    public static function fromState(
        ActivityRevisionId $revisionId,
        ActivityId $activityId,
        int $revisionNumber,
        ActivityRevisionSummary $summary,
        ?FileImportId $generatedByFileImportId,
        SerializableDateTime $createdOn,
    ): self {
        return new self(
            revisionId: $revisionId,
            activityId: $activityId,
            revisionNumber: $revisionNumber,
            summary: $summary,
            generatedByFileImportId: $generatedByFileImportId,
            createdOn: $createdOn,
        );
    }

    public function getId(): ActivityRevisionId
    {
        return $this->revisionId;
    }

    public function getActivityId(): ActivityId
    {
        return $this->activityId;
    }

    public function getRevisionNumber(): int
    {
        return $this->revisionNumber;
    }

    public function getSummary(): ActivityRevisionSummary
    {
        return $this->summary;
    }

    /**
     * The import whose bytes produced this state. Null for a version that no
     * file produced, which is the one that cannot be replayed.
     */
    public function getGeneratedByFileImportId(): ?FileImportId
    {
        return $this->generatedByFileImportId;
    }

    public function getCreatedOn(): SerializableDateTime
    {
        return $this->createdOn;
    }
}
