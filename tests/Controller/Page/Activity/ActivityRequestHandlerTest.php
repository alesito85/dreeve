<?php

namespace App\Tests\Controller\Page\Activity;

use App\Application\Activity\Revision\SwitchActivityRevision\SwitchActivityRevision;
use App\Controller\Admin\DispatchCommandRequestHandler;
use App\Domain\Activity\ActivityId;
use App\Domain\Activity\ActivityRepository;
use App\Domain\Activity\ActivityWithRawData;
use App\Domain\Activity\ImportSource;
use App\Domain\Activity\Lap\ActivityLapId;
use App\Domain\Activity\Lap\ActivityLapRepository;
use App\Domain\Activity\Revision\ActivityRevision;
use App\Domain\Activity\Revision\ActivityRevisionSummary;
use App\Domain\Activity\Revision\DbalActivityRevisionRepository;
use App\Domain\Activity\Split\ActivitySplitRepository;
use App\Domain\Activity\SportType\SportType;
use App\Domain\Activity\Stream\Metric\ActivityStreamMetric;
use App\Domain\Activity\Stream\Metric\ActivityStreamMetricRepository;
use App\Domain\Activity\Stream\Metric\ActivityStreamMetricType;
use App\Domain\Activity\Stream\StreamType;
use App\Domain\Import\DbalFileImportRepository;
use App\Domain\Import\FileImportId;
use App\Domain\Import\FileImportStatus;
use App\Infrastructure\Config\DemoMode;
use App\Infrastructure\Measurement\Length\Kilometer;
use App\Infrastructure\Measurement\Velocity\SecPerKm;
use App\Infrastructure\ValueObject\Time\SerializableDateTime;
use App\Tests\Controller\Admin\AdminWebTestCase;
use App\Tests\Domain\Activity\ActivityBuilder;
use App\Tests\Domain\Activity\Lap\ActivityLapBuilder;
use App\Tests\Domain\Activity\Split\ActivitySplitBuilder;
use App\Tests\Domain\Import\FileImportBuilder;
use App\Tests\ProvideTestData;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Snapshots\MatchesSnapshots;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class ActivityRequestHandlerTest extends AdminWebTestCase
{
    use MatchesSnapshots;
    use ProvideTestData;

    public function testRender(): void
    {
        $this->provideFullTestSet();

        $this->client->request('GET', '/activities/activity-9756441741');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');
        $this->assertStringEndsWith(
            'activities.9756441741.auth=anon.trust=trusted',
            (string) $this->client->getResponse()->headers->get('X-Dreeve-Cache-Key'),
        );
        $this->assertResponseHeaderSame(
            'X-Dreeve-Cache-Tags',
            'settings.appearance, settings.general, activities.9756441741, gear',
        );
        $this->assertMatchesHtmlSnapshot((string) $this->client->getResponse()->getContent());
    }

    public function testRenderForAVirtualRide(): void
    {
        $this->provideFullTestSet();

        $this->client->request('GET', '/activities/activity-9542782314');

        $this->assertResponseIsSuccessful();
        $this->assertMatchesHtmlSnapshot((string) $this->client->getResponse()->getContent());
    }

    #[DataProvider('provideSportTypesWithTheirOwnTemplate')]
    public function testItRendersTheTemplateOfTheSportType(SportType $sportType): void
    {
        $this->provideFullTestSet();

        $activityId = ActivityId::fromUnprefixed('123456789');
        $this->getContainer()->get(ActivityRepository::class)->add(ActivityWithRawData::fromState(
            ActivityBuilder::fromDefaults()
                ->withActivityId($activityId)
                ->withSportType($sportType)
                ->withIsCommute(true)
                ->withIsGroupActivity(true)
                ->build(),
            [],
        ));
        $this->getContainer()->get(ActivityLapRepository::class)->add(
            ActivityLapBuilder::fromDefaults()
                ->withLapId(ActivityLapId::fromUnprefixed('123456789-1'))
                ->withActivityId($activityId)
                ->build()
        );
        $this->getContainer()->get(ActivitySplitRepository::class)->add(
            ActivitySplitBuilder::fromDefaults()
                ->withActivityId($activityId)
                ->withGapPace(SecPerKm::from(310))
                ->build()
        );

        $this->client->request('GET', '/activities/'.$activityId);

        $this->assertResponseIsSuccessful();
        $this->assertMatchesHtmlSnapshot((string) $this->client->getResponse()->getContent());
    }

    public static function provideSportTypesWithTheirOwnTemplate(): \Generator
    {
        yield 'run' => [SportType::RUN];
        yield 'swim' => [SportType::POOL_SWIM];
    }

    public function testItRendersTheHeartRateWithoutTimeInZoneWhenThereAreNoHeartRateStreams(): void
    {
        $this->provideFullTestSet();

        $activityId = ActivityId::fromUnprefixed('123456789');
        $this->getContainer()->get(ActivityRepository::class)->add(ActivityWithRawData::fromState(
            ActivityBuilder::fromDefaults()
                ->withActivityId($activityId)
                ->withImportSource(ImportSource::MANUAL)
                ->withSportType(SportType::WEIGHT_TRAINING)
                ->withAverageHeartRate(141)
                ->withMaxHeartRate(173)
                ->build(),
            [],
        ));

        $this->client->request('GET', '/activities/'.$activityId);

        $this->assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('>141</div>', $content);
        $this->assertStringContainsString('173', $content);
        $this->assertStringNotContainsString('Time in zone', $content);
    }

    public function testItRendersAWorkoutThatHasNeitherStreamsNorDistance(): void
    {
        $this->provideFullTestSet();

        // A summary-only TCX (e.g. a Mi Fitness workout) stores no streams at all.
        $activityId = ActivityId::fromUnprefixed('123456789');
        $this->getContainer()->get(ActivityRepository::class)->add(ActivityWithRawData::fromState(
            ActivityBuilder::fromDefaults()
                ->withActivityId($activityId)
                ->withImportSource(ImportSource::TCX_FILE)
                ->withSportType(SportType::WORKOUT)
                ->withDistance(Kilometer::from(0))
                ->withAverageHeartRate(85)
                ->build(),
            [],
        ));

        $this->client->request('GET', '/activities/'.$activityId);

        $this->assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('>85</div>', $content);
        $this->assertStringNotContainsString('Time in zone', $content);
    }

    public function testItListsTheVersionsOfAnActivityThatWasImportedMoreThanOnce(): void
    {
        $this->provideFullTestSet();

        $activityId = ActivityId::fromUnprefixed('9756441741');
        $this->getContainer()->get(DbalFileImportRepository::class)->add(
            FileImportBuilder::fromDefaults()
                ->withFileImportId(FileImportId::fromUnprefixed('the-first-import'))
                ->withOriginalFilename('Afternoon_Ride.fit')
                ->withFileContents('the bytes that made it')
                ->withStatus(FileImportStatus::SUCCESS)
                ->withActivityId($activityId)
                ->build()
        );
        $this->getContainer()->get(DbalActivityRevisionRepository::class)->add(ActivityRevision::create(
            activityId: $activityId,
            revisionNumber: 1,
            summary: ActivityRevisionSummary::fromArray([
                'startedOn' => '2023-08-31 16:35:00',
                'distanceInMetres' => 1200,
                'elapsedTimeInSeconds' => 300,
                'movingTimeInSeconds' => 290,
                'averagePower' => 187,
                'streamSamples' => [StreamType::TIME->value => 300, StreamType::HEART_RATE->value => 300],
            ]),
            generatedByFileImportId: FileImportId::fromUnprefixed('the-first-import'),
            createdOn: SerializableDateTime::fromString('2023-10-17 14:00:00'),
        ));

        $crawler = $this->client->request('GET', '/activities/activity-9756441741');

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('#activity-versions'));
        $this->assertCount(1, $crawler->filter('#activity-versions .pill'));
        $this->assertStringContainsString('Afternoon_Ride.fit', $crawler->filter('#activity-versions')->text());
        // An anonymous visitor can read the history but has nothing to switch with.
        $this->assertCount(0, $crawler->filter('#activity-versions form'));

        $this->client->loginUser($this->adminUser());
        $crawler = $this->client->request('GET', '/activities/activity-9756441741');

        $this->assertResponseIsSuccessful();
        $form = $crawler->filter('#activity-versions form');
        $this->assertCount(1, $form);
        $this->assertSame(
            SwitchActivityRevision::getCommandName(),
            $form->attr('data-dispatch-command'),
        );
        $this->assertStringEndsWith(
            'auth=auth.trust=trusted',
            (string) $this->client->getResponse()->headers->get('X-Dreeve-Cache-Key'),
        );
        $this->assertSame('activity-9756441741', $form->filter('input[name="activityId"]')->attr('value'));
        $this->assertSame('1', $form->filter('input[name="revisionNumber"]')->attr('value'));
        // A page outside /admin has no command metas in its head, so the form carries
        // the endpoint and the token the script needs.
        $this->assertStringEndsWith(
            '/admin/dispatch-command',
            (string) $form->attr('data-dispatch-url'),
        );

        // The token is the stateless one the command endpoint validates against, which
        // is the same value for every visitor. A per-session token would put session
        // data into a page that is cached for everyone.
        $this->assertSame(
            $this->getContainer()->get(CsrfTokenManagerInterface::class)
                ->getToken(DispatchCommandRequestHandler::CSRF_TOKEN_ID)
                ->getValue(),
            (string) $form->attr('data-csrf-token'),
        );
    }

    public function testItDoesNotListVersionsForAnActivityThatWasImportedOnce(): void
    {
        $this->provideFullTestSet();

        $this->client->loginUser($this->adminUser());
        $crawler = $this->client->request('GET', '/activities/activity-9756441741');

        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('#activity-versions'));
    }

    public function testItLabelsTheVelocityDistributionOfARunAsPace(): void
    {
        $this->provideFullTestSet();

        $this->client->request('GET', '/activities/activity-45326441741');

        $this->assertResponseIsSuccessful();
        $this->assertDistributionChartIsTitled('Pace');
    }

    public function testItLabelsTheVelocityDistributionOfARideAsSpeed(): void
    {
        $this->provideFullTestSet();

        $this->getContainer()->get(ActivityStreamMetricRepository::class)->add(ActivityStreamMetric::create(
            activityId: ActivityId::fromUnprefixed('9756441741'),
            streamType: StreamType::VELOCITY,
            metricType: ActivityStreamMetricType::VALUE_DISTRIBUTION,
            data: [10 => 5, 20 => 9, 30 => 4],
        ));

        $this->client->request('GET', '/activities/activity-9756441741');

        $this->assertResponseIsSuccessful();
        $this->assertDistributionChartIsTitled('Speed');
    }

    private function assertDistributionChartIsTitled(string $title): void
    {
        $this->assertStringContainsString(
            sprintf('<div class="text-sm font-semibold mb-2 text-center">%s</div>', $title),
            (string) $this->client->getResponse()->getContent(),
        );
    }

    #[DataProvider('provideStartTimeVisibility')]
    public function testItOnlyShowsTheStartTimeToTrustedVisitors(bool $demoModeIsEnabled, bool $loggedIn, bool $expectStartTime, string $expectedCacheKeySuffix): void
    {
        $this->provideFullTestSet();
        $this->getContainer()->set(DemoMode::class, DemoMode::fromString($demoModeIsEnabled ? '1' : '0'));
        if ($loggedIn) {
            $this->client->loginUser($this->adminUser());
        }

        $this->client->request('GET', '/activities/activity-9756441741');

        $this->assertResponseIsSuccessful();
        $this->assertStringEndsWith(
            $expectedCacheKeySuffix,
            (string) $this->client->getResponse()->headers->get('X-Dreeve-Cache-Key'),
        );
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('August 31, 2023', $content);
        $this->assertSame($expectStartTime, str_contains($content, '16:35'));
    }

    public static function provideStartTimeVisibility(): \Generator
    {
        yield 'demo mode disabled' => [false, false, true, 'auth=anon.trust=trusted'];
        yield 'demo mode, anonymous visitor' => [true, false, false, 'auth=anon.trust=anonymized'];
        yield 'demo mode, logged in visitor' => [true, true, true, 'auth=auth.trust=trusted'];
    }

    #[DataProvider('provideUrlsThatAreNotFound')]
    public function testItIsNotFound(string $url): void
    {
        $this->provideFullTestSet();

        $this->client->request('GET', $url);

        $this->assertResponseStatusCodeSame(404);
    }

    public static function provideUrlsThatAreNotFound(): \Generator
    {
        yield 'an activity that does not exist' => ['/activities/activity-1'];
        yield 'the unprefixed id is not a valid activity id' => ['/activities/9756441741'];
    }

    #[\Override]
    protected function shouldSeedActivity(): bool
    {
        return false;
    }
}
