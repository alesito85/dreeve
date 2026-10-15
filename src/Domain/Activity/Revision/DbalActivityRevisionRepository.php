<?php

declare(strict_types=1);

namespace App\Domain\Activity\Revision;

use App\Domain\Activity\ActivityId;
use App\Domain\Import\FileImportId;
use App\Infrastructure\Repository\DbalRepository;
use App\Infrastructure\Serialization\Json;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;

final readonly class DbalActivityRevisionRepository extends DbalRepository implements ActivityRevisionRepository
{
    public function add(ActivityRevision $activityRevision): void
    {
        $sql = 'INSERT INTO ActivityRevision (revisionId, activityId, revisionNumber, summary, generatedByFileImportId, createdOn)
                VALUES (:revisionId, :activityId, :revisionNumber, :summary, :generatedByFileImportId, :createdOn)';

        $this->connection->executeStatement($sql, [
            'revisionId' => (string) $activityRevision->getId(),
            'activityId' => (string) $activityRevision->getActivityId(),
            'revisionNumber' => $activityRevision->getRevisionNumber(),
            'summary' => Json::encode($activityRevision->getSummary()),
            'generatedByFileImportId' => $activityRevision->getGeneratedByFileImportId() instanceof FileImportId ? (string) $activityRevision->getGeneratedByFileImportId() : null,
            'createdOn' => $activityRevision->getCreatedOn(),
        ]);
    }

    public function findForActivity(ActivityId $activityId): ActivityRevisions
    {
        $results = $this->connection->createQueryBuilder()
            ->select('*')
            ->from('ActivityRevision')
            ->andWhere('activityId = :activityId')
            ->setParameter('activityId', (string) $activityId)
            ->orderBy('revisionNumber', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return ActivityRevisions::fromArray(array_map(
            $this->hydrate(...),
            $results,
        ));
    }

    public function findNextRevisionNumberForActivity(ActivityId $activityId): int
    {
        $highest = $this->connection->createQueryBuilder()
            ->select('MAX(revisionNumber)')
            ->from('ActivityRevision')
            ->andWhere('activityId = :activityId')
            ->setParameter('activityId', (string) $activityId)
            ->executeQuery()
            ->fetchOne();

        return (false === $highest ? 0 : (int) $highest) + 1;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function hydrate(array $result): ActivityRevision
    {
        $summary = ActivityRevisionSummary::fromJson($result['summary']);
        if (!$summary instanceof ActivityRevisionSummary) {
            throw new \RuntimeException(sprintf('ActivityRevision "%s" has a summary that cannot be read', $result['revisionId']));
        }

        return ActivityRevision::fromState(
            revisionId: ActivityRevisionId::fromString($result['revisionId']),
            activityId: ActivityId::fromString($result['activityId']),
            revisionNumber: (int) $result['revisionNumber'],
            summary: $summary,
            generatedByFileImportId: FileImportId::fromOptionalString($result['generatedByFileImportId']),
            createdOn: SerializableDateTime::fromString($result['createdOn']),
        );
    }
}
