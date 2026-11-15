<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media;

use App\Identity\Domain\User;
use App\Media\UI\Controller\UploadStatusController;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Integration\Media\Persistence\MediaRows;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * GET /media/{id}/status through the real firewall, voters, session and database: Mia uploaded a pending, a ready and a
 * rejected picture of Acme Foods, Omar one there, and Ben of Beta Bakery one at his organization. The clock is fixed
 * on 6 Nov 2026, before today: a clock ahead of the login would end the session as idle. The poll limiter counts on the
 * state Valkey, keyed by user id; every test has new users, so their counts start empty.
 */
final class UploadStatusTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string NOW = '2026-11-06 12:00:00 UTC';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private OrganizationId $betaBakery;

    /** @var array<string, string> media id by name */
    private array $media;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(TenantContext::class)->reset();
        self::getContainer()->get('doctrine.orm.entity_manager')->clear();
        new MediaRows($this->migrator(), self::getContainer()->get(TenantContext::class))->removeAllOf(...$this->createdOrganizationIds);
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function miasUploads(): iterable
    {
        yield 'a pending one' => ['miaPending', 'pending'];
        yield 'a ready one' => ['miaReady', 'ready'];
        yield 'a rejected one' => ['miaRejected', 'rejected'];
    }

    #[Test]
    #[DataProvider('miasUploads')]
    public function miaReadsTheStateOfEachOfHerUploadsAndTheAnswerIsNeverCached(string $upload, string $expectedStatus): void
    {
        $this->seed();
        $this->logIn($this->client, $this->people['Mia']);

        $this->client->request('GET', $this->pathOf($upload));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['status' => $expectedStatus], $this->answer());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    #[Test]
    public function omarCannotReadTheStateOfMiasUploadAndTheAnswerIsTheSameAsForAnUnknownId(): void
    {
        $this->seed();
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('GET', $this->pathOf('miaReady'));
        $miasUpload = $this->client->getResponse()->getContent();
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/media/'.Uuid::v7()->toRfc4122().'/status');
        self::assertResponseStatusCodeSame(404);

        self::assertSame(['message' => UploadStatusController::NOTHING_UPLOADED], $this->answer());
        self::assertSame($this->client->getResponse()->getContent(), $miasUpload);
        self::assertStringNotContainsString('ready', (string) $miasUpload);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    #[Test]
    public function benOfAnotherOrganizationCannotReadTheStateOfMiasUpload(): void
    {
        $this->seed();
        $this->logIn($this->client, $this->people['Ben']);

        $this->client->request('GET', $this->pathOf('miaReady'));

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['message' => UploadStatusController::NOTHING_UPLOADED], $this->answer());
        $this->client->request('GET', $this->pathOf('benReady'));
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['status' => 'ready'], $this->answer());
    }

    #[Test]
    public function aMalformedIdIsNotFound(): void
    {
        $this->seed();
        $this->logIn($this->client, $this->people['Mia']);

        $this->client->request('GET', '/media/not-an-id/status');

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['message' => UploadStatusController::NOTHING_UPLOADED], $this->answer());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    #[Test]
    public function aPersonWithNoOrganizationIsSentToChooseOneAndLearnsNothingOfMiasUpload(): void
    {
        $this->seed();
        $this->logIn($this->client, $this->createUserWithoutOrganization('Drifter'));

        $this->client->request('GET', $this->pathOf('miaReady'));

        self::assertResponseStatusCodeSame(302);
        self::assertSame('/org/choose', parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_PATH));
        self::assertStringNotContainsString('ready', strip_tags((string) $this->client->getResponse()->getContent()));
    }

    #[Test]
    public function anAnonymousVisitorIsSentToLogIn(): void
    {
        $this->seed();

        $this->client->request('GET', $this->pathOf('miaReady'));

        self::assertResponseStatusCodeSame(302);
        self::assertSame('/login', parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_PATH));
    }

    #[Test]
    public function miasHundredAndTwentyFirstPollWithinAMinuteIsRefusedWithAWait(): void
    {
        $this->seed();
        $this->logIn($this->client, $this->people['Mia']);
        $this->spendPollsOf('Mia', 119);
        $this->client->request('GET', $this->pathOf('miaReady'));
        self::assertResponseStatusCodeSame(200);

        $this->client->request('GET', $this->pathOf('miaReady'));

        self::assertResponseStatusCodeSame(429);
        self::assertSame(['message' => UploadStatusController::POLLING_TOO_FAST], $this->answer());
        $retryAfter = $this->client->getResponse()->headers->get('Retry-After');
        self::assertIsString($retryAfter);
        self::assertMatchesRegularExpression('/\A[0-9]+\z/', $retryAfter);
        self::assertGreaterThanOrEqual(1, (int) $retryAfter);
        self::assertLessThanOrEqual(60, (int) $retryAfter);
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
    }

    #[Test]
    public function miasSpentPollsDoNotHoldBackOmar(): void
    {
        $this->seed();
        $this->spendPollsOf('Mia', 120);
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('GET', $this->pathOf('omarPending'));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['status' => 'pending'], $this->answer());
    }

    private function seed(): void
    {
        $this->people = $this->createAcmeFoodsPeople();
        $this->betaBakery = $this->createOrganization('Beta Bakery');
        $this->people['Ben'] = $this->createUserWithoutOrganization('Ben');
        $this->addMembership($this->people['Ben'], $this->betaBakery);
        $this->setOrgRoles($this->people['Ben'], $this->betaBakery, '{}');

        $this->media = [
            'miaPending' => $this->addMedia($this->acmeFoods, 'Mia', 'pending'),
            'miaReady' => $this->addMedia($this->acmeFoods, 'Mia', 'ready'),
            'miaRejected' => $this->addMedia($this->acmeFoods, 'Mia', 'rejected'),
            'omarPending' => $this->addMedia($this->acmeFoods, 'Omar', 'pending'),
            'benReady' => $this->addMedia($this->betaBakery, 'Ben', 'ready'),
        ];
    }

    /**
     * @param 'pending'|'ready'|'rejected' $status
     *
     * @return string the id of the picture $uploader uploaded
     */
    private function addMedia(OrganizationId $organizationId, string $uploader, string $status): string
    {
        $mediaId = Uuid::v7()->toRfc4122();
        $ready = 'ready' === $status;
        $parameters = [
            $organizationId->toString(), $mediaId, $this->people[$uploader]->id()->toString(), $status,
            'rejected' === $status ? 'Unreadable' : null,
            $ready ? hash('sha256', $mediaId) : null,
            $ready ? 1280 : null,
            $ready ? 800 : null,
            $ready ? '{"view": "media/x/view.webp"}' : null,
        ];
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO med_object (organization_id, id, storage_key, mime, bytes, uploaded_by, created_at, status, reject_reason, sha256, width, height, variants)
             VALUES (?, ?, 'uploads/seeded', 'image/png', 2048, ?, TIMESTAMPTZ '2026-11-05 10:00:00+00', ?, ?, ?, ?, ?, CAST(? AS JSONB))",
            $parameters,
        ));

        return $mediaId;
    }

    private function pathOf(string $upload): string
    {
        return '/media/'.$this->media[$upload].'/status';
    }

    private function spendPollsOf(string $person, int $polls): void
    {
        $pollsPerUser = self::getContainer()->get('limiter.media_upload_status_per_user');
        self::assertTrue($pollsPerUser->create($this->people[$person]->id()->toString())->consume($polls)->isAccepted());
    }

    /**
     * @return array<string, mixed> the JSON answer
     */
    private function answer(): array
    {
        $response = $this->client->getResponse();
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        $answer = json_decode((string) $response->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);
        $fields = [];
        foreach ($answer as $name => $value) {
            self::assertIsString($name);
            $fields[$name] = $value;
        }

        return $fields;
    }
}
