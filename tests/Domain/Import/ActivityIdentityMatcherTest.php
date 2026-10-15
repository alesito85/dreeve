<?php

declare(strict_types=1);

namespace App\Tests\Domain\Import;

use App\Domain\Activity\ActivityDeviceReference;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Activity\DbalActivityRepository;
use App\Domain\Activity\ImportSource;
use App\Domain\Activity\SportType\SportType;
use App\Domain\Import\ActivityIdentityMatcher;
use App\Domain\Import\AmbiguousActivityMatch;
use App\Infrastructure\Measurement\Length\Kilometer;
use App\Infrastructure\ValueObject\String\ExternalReferenceId;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\ContainerTestCase;
use App\Tests\Domain\Activity\ActivityBuilder;
use App\Tests\Infrastructure\Eventing\SpyEventBus;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The matcher never sees a filename, which is the point: the two files that
 * started this feature are byte identical apart from what the user called them.
 */
class ActivityIdentityMatcherTest extends ContainerTestCase
{
    private ActivityIdentityMatcher $activityIdentityMatcher;
    private ActivityRepository $activityRepository;

    public function testTheSameBytesUnderAnotherNameAreTheSameActivity(): void
    {
        $this->givenAnActivity(ActivityBuilder::fromDefaults()
            ->withActivityId(ActivityId::fromUnprefixed('1'))
            ->withFileChecksum(hash('sha256', 'the bytes of the ride')));

        $match = $this->activityIdentityMatcher->match(ActivityBuilder::fromDefaults()
            ->withFileChecksum(hash('sha256', 'the bytes of the ride'))
            ->build());

        $this->assertFalse($match->isNewActivity());
        $this->assertTrue($match->isIdenticalFile());
        $this->assertEquals(ActivityId::fromUnprefixed('1'), $match->getActivityId());
    }

    public function testAnIdenticalFileWinsOverEveryOtherCandidate(): void
    {
        $this->givenAnActivity(ActivityBuilder::fromDefaults()
            ->withActivityId(ActivityId::fromUnprefixed('1'))
            ->withStartDateTime(SerializableDateTime::fromString('2026-10-14 12:31:02'))
            ->withFileChecksum(hash('sha256', 'the bytes of the ride')));
        $this->givenAnActivity(ActivityBuilder::fromDefaults()
            ->withActivityId(ActivityId::fromUnprefixed('2'))
            ->withStartDateTime(SerializableDateTime::fromString('2026-10-14 12:31:02')));

        $match = $this->activityIdentityMatcher->match(ActivityBuilder::fromDefaults()
            ->withStartDateTime(SerializableDateTime::fromString('2026-10-14 12:31:03'))
            ->withFileChecksum(hash('sha256', 'the bytes of the ride'))
            ->build());

        $this->assertTrue($match->isIdenticalFile());
        $this->assertEquals(ActivityId::fromUnprefixed('1'), $match->getActivityId());
    }

    public function testAFileWithoutAChecksumIsNeverAnIdenticalFile(): void
    {
        $this->givenAnActivity(ActivityBuilder::fromDefaults()
            ->withActivityId(ActivityId::fromUnprefixed('1')));

        $match = $this->activityIdentityMatcher->match(ActivityBuilder::fromDefaults()->build());

        $this->assertFalse($match->isIdenticalFile());
        // It does match, but on content, which means it has data worth merging.
        $this->assertFalse($match->isNewActivity());
        $this->assertEquals(ActivityId::fromUnprefixed('1'), $match->getActivityId());
    }

    #[DataProvider('provideStartOffsetsForTheDeviceLayer')]
    public function testTheDeviceThatRecordedTheFileMatchesRegardlessOfTheFilenameOrTheDrift(
        int $offsetInSeconds,
        bool $expectedToMatch,
    ): void {
        $this->givenAnActivity(ActivityBuilder::fromDefaults()
            ->withActivityId(ActivityId::fromUnprefixed('1'))
            ->withDistance(Kilometer::from(5))
            ->withStartDateTime(SerializableDateTime::fromString('2026-10-14 12:31:02'))
            ->withDeviceReferenceId(ActivityDeviceReference::fromString('289-2-22701926')));

        $match = $this->activityIdentityMatcher->match(ActivityBuilder::fromDefaults()
            ->withDistance(Kilometer::from(45.2))
            ->withStartDateTime(SerializableDateTime::fromString('2026-10-14 12:31:02')->modify(sprintf('%+d seconds', $offsetInSeconds)))
            ->withDeviceReferenceId(ActivityDeviceReference::fromString('289-2-22701926'))
            ->build());

        $this->assertSame($expectedToMatch, !$match->isNewActivity());
        if ($expectedToMatch) {
            $this->assertEquals(ActivityId::fromUnprefixed('1'), $match->getActivityId());
            $this->assertFalse($match->isIdenticalFile());
        }
    }

    /**
     * The stored activity is five kilometres and the file says 45,2, so nothing
     * but the device reference can match them. A partial first sync that stopped
     * after a few minutes is exactly this shape.
     *
     * @return iterable<string, array{int, bool}>
     */
    public static function provideStartOffsetsForTheDeviceLayer(): iterable
    {
        yield 'a second apart' => [1, true];
        yield 'half a minute apart' => [30, true];
        yield 'just past the window' => [31, false];
        yield 'a second earlier' => [-1, true];
    }

    public function testAnotherDeviceAtTheSameInstantIsNotAHardwareMatch(): void
    {
        $this->givenAnActivity(ActivityBuilder::fromDefaults()
            ->withActivityId(ActivityId::fromUnprefixed('1'))
            ->withDistance(Kilometer::from(5))
            ->withStartDateTime(SerializableDateTime::fromString('2026-10-14 12:31:02'))
            ->withDeviceReferenceId(ActivityDeviceReference::fromString('289-2-111')));

        $match = $this->activityIdentityMatcher->match(ActivityBuilder::fromDefaults()
            ->withDistance(Kilometer::from(45.2))
            ->withStartDateTime(SerializableDateTime::fromString('2026-10-14 12:31:03'))
            ->withDeviceReferenceId(ActivityDeviceReference::fromString('289-2-22701926'))
            ->build());

        $this->assertTrue($match->isNewActivity());
    }

    public function testTheContentGateStillDecidesForAnotherDeviceAtTheSameInstant(): void
    {
        // Two devices can record one ride, an Edge and a watch, so a different
        // serial does not make a file about a different activity.
        $this->givenAnActivity(ActivityBuilder::fromDefaults()
            ->withActivityId(ActivityId::fromUnprefixed('1'))
            ->withDistance(Kilometer::from(45.2))
            ->withStartDateTime(SerializableDateTime::fromString('2026-10-14 12:31:02'))
            ->withDeviceReferenceId(ActivityDeviceReference::fromString('289-2-111')));

        $match = $this->activityIdentityMatcher->match(ActivityBuilder::fromDefaults()
            ->withDistance(Kilometer::from(45.2))
            ->withStartDateTime(SerializableDateTime::fromString('2026-10-14 12:31:03'))
            ->withDeviceReferenceId(ActivityDeviceReference::fromString('289-2-22701926'))
            ->build());

        $this->assertFalse($match->isNewActivity());
        $this->assertEquals(ActivityId::fromUnprefixed('1'), $match->getActivityId());
    }

    public function testTheOldestActivityWinsWhenOneDeviceAppearsTwiceAtOneInstant(): void
    {
        // Dreeve can already have duplicated an activity, and one device cannot
        // record two activities at one instant, so this is a match and not a
        // refusal. The same row has to win on every run, hence the oldest.
        foreach ([2, 1] as $activityId) {
            $this->givenAnActivity(ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed((string) $activityId))
                ->withDistance(Kilometer::from(5))
                ->withStartDateTime(SerializableDateTime::fromString('2026-10-14 12:31:0'.$activityId))
                ->withDeviceReferenceId(ActivityDeviceReference::fromString('289-2-22701926')));
        }

        $match = $this->activityIdentityMatcher->match(ActivityBuilder::fromDefaults()
            ->withDistance(Kilometer::from(45.2))
            ->withStartDateTime(SerializableDateTime::fromString('2026-10-14 12:31:03'))
            ->withDeviceReferenceId(ActivityDeviceReference::fromString('289-2-22701926'))
            ->build());

        $this->assertFalse($match->isIdenticalFile());
        $this->assertEquals(ActivityId::fromUnprefixed('1'), $match->getActivityId());
    }

    #[DataProvider('provideContentGateScenarios')]
    public function testTheContentGateDecidesForFilesWithoutADeviceIdentity(
        SportType $storedSportType,
        Kilometer $storedDistance,
        SportType $incomingSportType,
        Kilometer $incomingDistance,
        int $offsetInSeconds,
        bool $expectedToMatch,
    ): void {
        $this->givenAnActivity(ActivityBuilder::fromDefaults()
            ->withActivityId(ActivityId::fromUnprefixed('1'))
            ->withImportSource(ImportSource::STRAVA_API)
            ->withSportType($storedSportType)
            ->withDistance($storedDistance)
            ->withStartDateTime(SerializableDateTime::fromString('2026-10-14 12:31:02')));

        $match = $this->activityIdentityMatcher->match(ActivityBuilder::fromDefaults()
            ->withImportSource(ImportSource::FIT_FILE)
            ->withSportType($incomingSportType)
            ->withDistance($incomingDistance)
            ->withStartDateTime(SerializableDateTime::fromString('2026-10-14 12:31:02')->modify(sprintf('%+d seconds', $offsetInSeconds)))
            ->build());

        $this->assertSame($expectedToMatch, !$match->isNewActivity());
        if ($expectedToMatch) {
            $this->assertEquals(ActivityId::fromUnprefixed('1'), $match->getActivityId());
            $this->assertFalse($match->isIdenticalFile());
        }
    }

    /**
     * @return iterable<string, array{SportType, Kilometer, SportType, Kilometer, int, bool}>
     */
    public static function provideContentGateScenarios(): iterable
    {
        // The Strava twin of a file import carries no fingerprint on either
        // side, so this gate is the only thing standing between them and two rows.
        yield 'the strava twin of the same ride' => [
            SportType::RIDE, Kilometer::from(45.2), SportType::RIDE, Kilometer::from(45.2), 0, true,
        ];
        yield 'a second later' => [
            SportType::RIDE, Kilometer::from(45.2), SportType::RIDE, Kilometer::from(45.2), 1, true,
        ];
        yield 'half a minute later' => [
            SportType::RIDE, Kilometer::from(45.2), SportType::RIDE, Kilometer::from(45.2), 30, true,
        ];
        yield 'just past half a minute' => [
            SportType::RIDE, Kilometer::from(45.2), SportType::RIDE, Kilometer::from(45.2), 31, false,
        ];
        yield 'a distance within five percent' => [
            SportType::RIDE, Kilometer::from(45.2), SportType::RIDE, Kilometer::from(47), 0, true,
        ];
        yield 'a distance outside five percent' => [
            SportType::RIDE, Kilometer::from(45.2), SportType::RIDE, Kilometer::from(50), 0, false,
        ];
        yield 'another sport type' => [
            SportType::RIDE, Kilometer::from(45.2), SportType::RUN, Kilometer::from(45.2), 0, false,
        ];
        yield 'two sessions with no distance at all' => [
            SportType::VIRTUAL_RIDE, Kilometer::from(0), SportType::VIRTUAL_RIDE, Kilometer::from(0), 0, true,
        ];
        yield 'a session with no distance next to one that has' => [
            SportType::RIDE, Kilometer::from(0), SportType::RIDE, Kilometer::from(10), 0, false,
        ];
    }

    public function testAFilenameIsNotIdentity(): void
    {
        // The rule this replaces matched strava activities on the name of the
        // file, which is whatever the user called a download.
        $this->givenAnActivity(ActivityBuilder::fromDefaults()
            ->withActivityId(ActivityId::fromUnprefixed('1'))
            ->withImportSource(ImportSource::STRAVA_API)
            ->withExternalReferenceId(ExternalReferenceId::fromString('ride.tcx'))
            ->withDistance(Kilometer::from(45.2))
            ->withStartDateTime(SerializableDateTime::fromString('2026-10-14 12:31:02')));

        $match = $this->activityIdentityMatcher->match(ActivityBuilder::fromDefaults()
            ->withImportSource(ImportSource::FIT_FILE)
            ->withExternalReferenceId(ExternalReferenceId::fromString('ride.tcx'))
            ->withDistance(Kilometer::from(45.2))
            ->withStartDateTime(SerializableDateTime::fromString('2026-10-14 18:31:03'))
            ->build());

        $this->assertTrue($match->isNewActivity());
    }

    public function testItRefusesToPickWhenMoreThanOneActivityFits(): void
    {
        foreach ([1, 2] as $activityId) {
            $this->givenAnActivity(ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed((string) $activityId))
                ->withStartDateTime(SerializableDateTime::fromString('2026-10-14 12:31:02')));
        }

        try {
            $this->activityIdentityMatcher->match(ActivityBuilder::fromDefaults()
                ->withStartDateTime(SerializableDateTime::fromString('2026-10-14 12:31:03'))
                ->build());
            $this->fail('Expected an AmbiguousActivityMatch');
        } catch (AmbiguousActivityMatch $e) {
            $this->assertEquals([
                ActivityId::fromUnprefixed('1'),
                ActivityId::fromUnprefixed('2'),
            ], $e->getCandidateIds());
        }
    }

    public function testNothingMatchesAnActivityThatWasNotImportedYet(): void
    {
        $match = $this->activityIdentityMatcher->match(ActivityBuilder::fromDefaults()->build());

        $this->assertTrue($match->isNewActivity());
        $this->assertNull($match->getActivityId());
    }

    public function testAStravaPayloadMatchesTheFileImportThatDescribesTheSameRide(): void
    {
        $this->givenAnActivity(ActivityBuilder::fromDefaults()
            ->withActivityId(ActivityId::fromUnprefixed('1'))
            ->withImportSource(ImportSource::FIT_FILE)
            ->withStartDateTime(SerializableDateTime::fromString('2026-10-14 12:31:02')));

        $this->assertEquals(
            ActivityId::fromUnprefixed('1'),
            $this->activityIdentityMatcher->matchFileImportedTwin(
                sportType: SportType::RIDE,
                startDateTime: SerializableDateTime::fromString('2026-10-14 12:31:03'),
                distance: Kilometer::from(10),
            ),
        );
    }

    public function testAStravaPayloadIsNotJudgedAgainstActivitiesThatCameFromTheApi(): void
    {
        // Activities that both came from the API are told apart by their Strava id,
        // so content alone must not decide that one of them is a duplicate.
        $this->givenAnActivity(ActivityBuilder::fromDefaults()
            ->withActivityId(ActivityId::fromUnprefixed('1'))
            ->withImportSource(ImportSource::STRAVA_API)
            ->withStartDateTime(SerializableDateTime::fromString('2026-10-14 12:31:02')));

        $this->assertNull($this->activityIdentityMatcher->matchFileImportedTwin(
            sportType: SportType::RIDE,
            startDateTime: SerializableDateTime::fromString('2026-10-14 12:31:03'),
            distance: Kilometer::from(10),
        ));
    }

    public function testAStravaPayloadRefusesToPickBetweenTwoFileImports(): void
    {
        foreach ([1, 2] as $activityId) {
            $this->givenAnActivity(ActivityBuilder::fromDefaults()
                ->withActivityId(ActivityId::fromUnprefixed((string) $activityId))
                ->withImportSource(ImportSource::FIT_FILE)
                ->withStartDateTime(SerializableDateTime::fromString('2026-10-14 12:31:02')));
        }

        try {
            $this->activityIdentityMatcher->matchFileImportedTwin(
                sportType: SportType::RIDE,
                startDateTime: SerializableDateTime::fromString('2026-10-14 12:31:03'),
                distance: Kilometer::from(10),
            );
            $this->fail('Expected an AmbiguousActivityMatch');
        } catch (AmbiguousActivityMatch $e) {
            $this->assertEquals([
                ActivityId::fromUnprefixed('1'),
                ActivityId::fromUnprefixed('2'),
            ], $e->getCandidateIds());
        }
    }

    private function givenAnActivity(ActivityBuilder $builder): void
    {
        $this->activityRepository->add(ActivityWithRawData::fromState($builder->build(), []));
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->activityRepository = new DbalActivityRepository(
            $this->getConnection(),
            new SpyEventBus(),
        );
        $this->activityIdentityMatcher = new ActivityIdentityMatcher(
            $this->getConnection(),
        );
    }
}
