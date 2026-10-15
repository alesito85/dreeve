<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Domain\Activity\Activity;
use App\Domain\Activity\ActivityDeviceReference;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ImportSource;
use App\Domain\Activity\SportType\SportType;
use App\Infrastructure\Measurement\Length\Kilometer;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Decides whether the activity a file just described is one Dreeve already has.
 *
 * A second row for an activity that is already in the database is the failure
 * this whole feature exists to prevent, so the answer is never "probably not".
 * Three layers, cheapest and most certain first:
 *
 *  1. the checksum of the bytes, which is the same file under another name;
 *  2. the device that recorded the file plus the start instant, which survives
 *     a rename, a re-export and a sync that stopped halfway through;
 *  3. sport type, start instant and distance, the fallback for files that carry
 *     no device identity, and the only layer that can match a Strava activity.
 *
 * Filenames are not part of any layer. A filename is whatever the user called a
 * download, and the two sample files that started this are byte identical under
 * different names.
 */
final readonly class ActivityIdentityMatcher
{
    private const int TOLERANCE_IN_SECONDS = 30;
    private const float TOLERANCE_IN_DISTANCE_PERCENT = 5;

    // Two distances that are both effectively nothing need no room of their own,
    // and a floor keeps float noise out of short indoor activities.
    private const int TOLERANCE_FLOOR_IN_METRES = 50;

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function match(Activity $activity): ActivityIdentityMatch
    {
        if (($identicalFileActivityId = $this->findForIdenticalFile($activity->getFileChecksum())) instanceof ActivityId) {
            return ActivityIdentityMatch::forIdenticalFile($identicalFileActivityId);
        }

        if (($deviceReference = $activity->getDeviceReferenceId()) instanceof ActivityDeviceReference) {
            $candidates = $this->findCandidates(
                deviceReference: $deviceReference,
                sportType: null,
                startDateTime: $activity->getStartDate(),
                distanceInMetres: null,
            );
            if ([] !== $candidates) {
                // The same device cannot record two activities at one instant, so
                // more than one row here means an activity Dreeve already
                // duplicated, not a guess about which row the file belongs to. The
                // oldest is also the one every later run picks.
                return ActivityIdentityMatch::forExistingActivity($candidates[0]);
            }
        }

        $candidates = $this->findCandidates(
            deviceReference: null,
            sportType: $activity->getSportType(),
            startDateTime: $activity->getStartDate(),
            distanceInMetres: $activity->getDistance()->toMeter()->toInt(),
        );

        return match (count($candidates)) {
            0 => ActivityIdentityMatch::forNewActivity(),
            1 => ActivityIdentityMatch::forExistingActivity($candidates[0]),
            default => throw new AmbiguousActivityMatch(candidateIds: $candidates),
        };
    }

    /**
     * Finds the file imported activity a Strava payload describes, if there is one.
     *
     * A ride that came in as a file and also shows up in Strava is one activity,
     * not two. The file owns the row, because it is the one whose bytes can be
     * replayed later, so the API version of the same ride has nothing to add.
     * Only file imports are considered here: activities that both came from the
     * API are told apart by their Strava id, and guessing from content alone would
     * drop real activities that happen to look alike.
     *
     * @throws AmbiguousActivityMatch when more than one file import fits equally well
     */
    public function matchFileImportedTwin(
        SportType $sportType,
        SerializableDateTime $startDateTime,
        Kilometer $distance,
    ): ?ActivityId {
        $candidates = $this->findCandidates(
            deviceReference: null,
            sportType: $sportType,
            startDateTime: $startDateTime,
            distanceInMetres: $distance->toMeter()->toInt(),
            consideredSources: ImportSource::fileBasedSources(),
        );

        return match (count($candidates)) {
            0 => null,
            1 => $candidates[0],
            default => throw new AmbiguousActivityMatch(candidateIds: $candidates),
        };
    }

    private function findForIdenticalFile(?string $fileChecksum): ?ActivityId
    {
        if (null === $fileChecksum) {
            return null;
        }

        $activityId = $this->connection->createQueryBuilder()
            ->select('activityId')
            ->from('Activity')
            ->where('fileChecksum = :fileChecksum')
            ->setParameter('fileChecksum', $fileChecksum)
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return false === $activityId ? null : ActivityId::fromString($activityId);
    }

    /**
     * @param ImportSource[]|null $consideredSources null means every import source
     *
     * @return list<ActivityId>
     */
    private function findCandidates(
        ?ActivityDeviceReference $deviceReference,
        ?SportType $sportType,
        SerializableDateTime $startDateTime,
        ?int $distanceInMetres,
        ?array $consideredSources = null,
    ): array {
        $query = $this->connection->createQueryBuilder()
            ->select('activityId')
            ->from('Activity')
            ->andWhere('startDateTime BETWEEN :from AND :to')
            ->setParameter('from', $startDateTime->modify(sprintf('-%d seconds', self::TOLERANCE_IN_SECONDS))->iso())
            ->setParameter('to', $startDateTime->modify(sprintf('+%d seconds', self::TOLERANCE_IN_SECONDS))->iso())
            ->orderBy('startDateTime ASC')
            ->addOrderBy('activityId ASC')
            ->setMaxResults(2);

        if ($deviceReference instanceof ActivityDeviceReference) {
            $query->andWhere('deviceReferenceId = :deviceReferenceId')
                ->setParameter('deviceReferenceId', $deviceReference);
        }

        if (null !== $consideredSources) {
            $query->andWhere($query->expr()->in('importSource', ':importSources'))
                ->setParameter('importSources', array_map(
                    static fn (ImportSource $source): string => $source->value,
                    $consideredSources,
                ), ArrayParameterType::STRING);
        }

        if ($sportType instanceof SportType) {
            $query->andWhere('sportType = :sportType')
                ->setParameter('sportType', $sportType->value);
        }

        if (null !== $distanceInMetres) {
            $query->andWhere('ABS(distance - :distance) <= :toleranceInMetres')
                ->setParameter('distance', $distanceInMetres, ParameterType::INTEGER)
                ->setParameter(
                    'toleranceInMetres',
                    max(
                        self::TOLERANCE_FLOOR_IN_METRES,
                        (int) round(abs($distanceInMetres) * self::TOLERANCE_IN_DISTANCE_PERCENT / 100),
                    ),
                    ParameterType::INTEGER,
                );
        }

        return array_map(
            static fn (array $row): ActivityId => ActivityId::fromString($row['activityId']),
            $query->executeQuery()->fetchAllAssociative(),
        );
    }
}
