<?php

declare(strict_types=1);

namespace App\Tests\Functional\Media;

use App\Identity\Domain\User;
use App\Media\Application\DirectUploads;
use App\Media\Application\ProcessUpload;
use App\Media\UI\Controller\UploadIntentController;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use App\Tests\Integration\Media\Persistence\MediaRows;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The two JSON calls of a direct upload, POST /media/intents and POST /media/intents/{id}/complete, for Acme Foods'
 * SHOP-1 through the real firewall, voters, session, database and outbox. The clock is fixed on 6 Nov 2026, before
 * today: a clock ahead of the login would end the session as idle. Every refusal also proves that no intent, media
 * object or outbox row was written, or that the intent stayed unused.
 */
final class UploadIntentsTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesCsrfTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string BEGIN_PATH = '/media/intents';
    private const int SCREENSHOT_BYTES = 245_760;
    private const string NOBODY_INTENT_ID = '0197a3c0-0000-7000-8000-00000000dead';
    private const array JSON_HEADERS = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    private KernelBrowser $client;

    private MockClock $clock;

    /** @var array<string, User> */
    private array $people;

    private ?OrganizationId $betaBakery = null;

    private string $shopTicket;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        $this->clock = new MockClock(new \DateTimeImmutable(self::NOW));
        self::getContainer()->set('clock', $this->clock);
    }

    protected function tearDown(): void
    {
        // MediaRows switches the tenant, which the switch guard refuses while the last request's tenant and entities linger.
        self::getContainer()->get(TenantContext::class)->reset();
        self::getContainer()->get('doctrine.orm.entity_manager')->clear();
        new MediaRows($this->migrator(), self::getContainer()->get(TenantContext::class))->removeAllOf(...$this->createdOrganizationIds);
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (['tkt_ticket', 'wf_step'] as $table) {
                    $migrator->executeStatement(\sprintf('DELETE FROM %s WHERE organization_id = ?', $table), [$organizationId]);
                }
                $migrator->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox' AND position(? in body) > 0", [$organizationId]);

                return 0;
            });
        }
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function omarBeginsAnUploadForShop1AndGetsWhereToPutTheFile(): void
    {
        $this->seedShop();
        $this->logIn($this->client, $this->people['Omar']);

        $this->postJson(self::BEGIN_PATH, $this->beginBody(), $this->uploadToken());

        self::assertResponseStatusCodeSame(201);
        $slot = $this->answer();
        self::assertSame(['intentId', 'url', 'method', 'headers', 'expiresAt'], array_keys($slot));
        $intentId = $slot['intentId'];
        self::assertIsString($intentId);
        self::assertTrue(Uuid::isValid($intentId));
        self::assertIsString($slot['url']);
        self::assertStringStartsWith(\sprintf('http://localhost:9000/pinround/uploads/%s/%s?', $this->acmeFoods->toString(), $intentId), $slot['url']);
        self::assertSame('PUT', $slot['method']);
        self::assertIsArray($slot['headers']);
        self::assertSame('image/png', $slot['headers']['Content-Type'] ?? null);
        self::assertSame('2026-11-06T12:05:00+00:00', $slot['expiresAt']);
        self::assertSame([[
            'id' => $intentId,
            'user_id' => $this->idOf('Omar'),
            'target_kind' => 'ticket',
            'target_id' => $this->shopTicket,
            'max_bytes' => self::SCREENSHOT_BYTES,
            'mime_allowed' => '{image/png}',
            'expires_at' => '2026-11-07 12:00:00',
            'used_at' => null,
        ]], $this->storedIntents());
        self::assertSame([], $this->storedMediaObjects());
        self::assertSame([], $this->queuedMessages());
    }

    #[Test]
    public function aBeginRequestOfExactly4KbIsAccepted(): void
    {
        $this->seedShop();
        $this->logIn($this->client, $this->people['Omar']);
        $body = $this->beginBody();

        $this->postJson(self::BEGIN_PATH, $body.str_repeat(' ', UploadIntentController::MAX_BODY_BYTES - \strlen($body)), $this->uploadToken());

        self::assertResponseStatusCodeSame(201);
        self::assertCount(1, $this->storedIntents());
    }

    /**
     * Who begins, Mia's team role, and the answer.
     *
     * @return iterable<string, array{string, 'member'|'viewer'|null}>
     */
    public static function peopleWhoMayNotAddToShop1(): iterable
    {
        yield 'Mia, a viewer of Shop' => ['Mia', 'viewer'];
        yield 'Mia, with no team role' => ['Mia', null];
        yield 'Raj, a manager with no team role' => ['Raj', 'viewer'];
        yield 'Sara, an admin with no team role' => ['Sara', 'viewer'];
        yield 'Ben, of another organization' => ['Ben', 'viewer'];
    }

    /**
     * @param 'member'|'viewer'|null $miaTeamRole
     */
    #[Test]
    #[DataProvider('peopleWhoMayNotAddToShop1')]
    public function someoneWhoMayNotEditShop1IsForbiddenAndNothingIsStored(string $person, ?string $miaTeamRole): void
    {
        $this->seedShop($miaTeamRole);
        $this->addBenOfBetaBakery();
        $this->logIn($this->client, $this->people[$person]);

        $this->postJson(self::BEGIN_PATH, $this->beginBody(), $this->uploadToken());

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['message' => UploadIntentController::NOT_ALLOWED], $this->answer());
        $this->assertNothingStored();
    }

    #[Test]
    public function miaMayBeginOnceShopMakesHerAMember(): void
    {
        $this->seedShop('member');
        $this->logIn($this->client, $this->people['Mia']);

        $this->postJson(self::BEGIN_PATH, $this->beginBody(), $this->uploadToken());

        self::assertResponseStatusCodeSame(201);
        self::assertSame([$this->idOf('Mia')], array_column($this->storedIntents(), 'user_id'));
    }

    #[Test]
    public function anAnonymousVisitorIsSentToLogInAndNothingIsStored(): void
    {
        $this->seedShop();

        $this->postJson(self::BEGIN_PATH, $this->beginBody(), 'not-a-token');

        self::assertResponseStatusCodeSame(302);
        self::assertSame('/login', parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_PATH));
        $this->assertNothingStored();
    }

    /**
     * The fields that differ from a PNG screenshot of 240 KB, and Uploads' plain message.
     *
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function filesRefusedBeforeAnyUrl(): iterable
    {
        yield 'a file of 16 MB' => [['bytes' => 16 * 1024 * 1024], 'This file is empty or bigger than 15 MB. Pick a screenshot of up to 15 MB.'];
        yield 'a file one byte over 15 MB' => [['bytes' => 15 * 1024 * 1024 + 1], 'This file is empty or bigger than 15 MB. Pick a screenshot of up to 15 MB.'];
        yield 'an empty file' => [['bytes' => 0], 'This file is empty or bigger than 15 MB. Pick a screenshot of up to 15 MB.'];
        yield 'a GIF' => [['mime' => 'image/gif'], 'This file is not a PNG, JPEG or WebP screenshot.'];
        yield 'a PNG type in capitals' => [['mime' => 'IMAGE/PNG'], 'This file is not a PNG, JPEG or WebP screenshot.'];
        yield 'another purpose' => [['purpose' => 'avatar'], 'Only screenshots can be uploaded here.'];
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[Test]
    #[DataProvider('filesRefusedBeforeAnyUrl')]
    public function aFileOfTheWrongTypeOrSizeIsRefusedBeforeAnyUrlWithThePlainMessage(array $fields, string $expectedMessage): void
    {
        $this->seedShop();
        $this->logIn($this->client, $this->people['Omar']);

        $this->postJson(self::BEGIN_PATH, $this->beginBody($fields), $this->uploadToken());

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['message' => $expectedMessage], $this->answer());
        $this->assertNothingStored();
    }

    /**
     * What replaces the token (null: no header), what replaces the body, the status and the message.
     *
     * @return iterable<string, array{?string, ?string, int, string}>
     */
    public static function beginRequestsRefused(): iterable
    {
        yield 'no form token' => [null, null, 422, UploadIntentController::FORM_EXPIRED];
        yield 'a made-up form token' => ['not-a-token', null, 422, UploadIntentController::FORM_EXPIRED];
        yield 'a body one byte over 4 KB' => ['{token}', '{body-of-4097-bytes}', 413, UploadIntentController::BODY_TOO_LARGE];
        yield 'a body that is no JSON' => ['{token}', 'purpose=screenshot&mime=image/png', 400, UploadIntentController::NOT_A_BEGIN_REQUEST];
        yield 'an empty body' => ['{token}', '', 400, UploadIntentController::NOT_A_BEGIN_REQUEST];
        yield 'a JSON string' => ['{token}', '"screenshot"', 400, UploadIntentController::NOT_A_BEGIN_REQUEST];
        yield 'JSON nested too deep' => ['{token}', '[[[[[[[[[[]]]]]]]]]]', 400, UploadIntentController::NOT_A_BEGIN_REQUEST];
        yield 'no target' => ['{token}', '{"purpose":"screenshot","mime":"image/png","bytes":245760}', 400, UploadIntentController::NOT_A_BEGIN_REQUEST];
        yield 'a size as text' => ['{token}', '{"purpose":"screenshot","mime":"image/png","bytes":"245760","target":{"kind":"ticket","id":"{ticket}"}}', 400, UploadIntentController::NOT_A_BEGIN_REQUEST];
        yield 'a target id as a number' => ['{token}', '{"purpose":"screenshot","mime":"image/png","bytes":245760,"target":{"kind":"ticket","id":7}}', 400, UploadIntentController::NOT_A_BEGIN_REQUEST];
        yield 'a kind no module decides' => ['{token}', '{"purpose":"screenshot","mime":"image/png","bytes":245760,"target":{"kind":"round","id":"{ticket}"}}', 403, UploadIntentController::NOT_ALLOWED];
        yield 'a ticket id that is no uuid' => ['{token}', '{"purpose":"screenshot","mime":"image/png","bytes":245760,"target":{"kind":"ticket","id":"SHOP-1"}}', 403, UploadIntentController::NOT_ALLOWED];
        yield 'a ticket id with a trailing newline' => ['{token}', '{"purpose":"screenshot","mime":"image/png","bytes":245760,"target":{"kind":"ticket","id":"{ticket}\n"}}', 403, UploadIntentController::NOT_ALLOWED];
        yield 'a ticket Acme Foods has not' => ['{token}', '{"purpose":"screenshot","mime":"image/png","bytes":245760,"target":{"kind":"ticket","id":"'.self::NOBODY_INTENT_ID.'"}}', 403, UploadIntentController::NOT_ALLOWED];
    }

    #[Test]
    #[DataProvider('beginRequestsRefused')]
    public function aBeginRequestThatIsRefusedSaysWhyAndStoresNothing(?string $token, ?string $body, int $expectedStatus, string $expectedMessage): void
    {
        $this->seedShop();
        $this->logIn($this->client, $this->people['Omar']);
        $validBody = $this->beginBody();
        $body = match ($body) {
            null => $validBody,
            '{body-of-4097-bytes}' => $validBody.str_repeat(' ', UploadIntentController::MAX_BODY_BYTES + 1 - \strlen($validBody)),
            default => str_replace('{ticket}', $this->shopTicket, $body),
        };

        $this->postJson(self::BEGIN_PATH, $body, '{token}' === $token ? $this->uploadToken() : $token);

        self::assertResponseStatusCodeSame($expectedStatus);
        self::assertSame(['message' => $expectedMessage], $this->answer());
        $this->assertNothingStored();
    }

    #[Test]
    public function omarCompletesHisUploadAndOneProcessUploadIsQueued(): void
    {
        $this->seedShop();
        $this->logIn($this->client, $this->people['Omar']);
        $intentId = $this->beginAsTheLoggedInPerson();
        $this->clock->modify('+2 minutes');

        $this->postJson($this->completePathOf($intentId), '', $this->uploadToken());

        self::assertResponseStatusCodeSame(202);
        $answer = $this->answer();
        self::assertSame(['mediaId', 'status'], array_keys($answer));
        self::assertSame('pending', $answer['status']);
        $mediaId = $answer['mediaId'];
        self::assertIsString($mediaId);
        self::assertSame([[
            'id' => $mediaId,
            'storage_key' => \sprintf('uploads/%s/%s', $this->acmeFoods->toString(), $intentId),
            'mime' => 'image/png',
            'bytes' => self::SCREENSHOT_BYTES,
            'uploaded_by' => $this->idOf('Omar'),
            'status' => 'pending',
            'created_at' => '2026-11-06 12:02:00',
        ]], $this->storedMediaObjects());
        self::assertSame(['2026-11-06 12:02:00'], array_column($this->storedIntents(), 'used_at'));
        self::assertEquals([new ProcessUpload($this->acmeFoods->toString(), $mediaId)], $this->queuedMessages());
    }

    /**
     * Who completes Omar's intent, and the path's intent id ({intent}: Omar's).
     *
     * @return iterable<string, array{string, string}>
     */
    public static function completionsOfNoIntentOfTheirs(): iterable
    {
        yield 'Lena, of the same organization' => ['Lena', '{intent}'];
        yield 'Ben, of another organization' => ['Ben', '{intent}'];
        yield 'Omar, with an id that is no uuid' => ['Omar', 'not-an-id'];
        yield 'Omar, with his id and a trailing newline' => ['Omar', '{intent}%0A'];
        yield 'Omar, with an id of no intent' => ['Omar', self::NOBODY_INTENT_ID];
    }

    #[Test]
    #[DataProvider('completionsOfNoIntentOfTheirs')]
    public function completingAnIntentThatIsNotTheirsIsNotFoundAndTheIntentStaysUnused(string $person, string $pathIntentId): void
    {
        $this->seedShop();
        $this->addBenOfBetaBakery();
        $this->logIn($this->client, $this->people['Omar']);
        $intentId = $this->beginAsTheLoggedInPerson();
        $this->logIn($this->client, $this->people[$person]);

        $this->postJson($this->completePathOf(str_replace('{intent}', $intentId, $pathIntentId)), '', $this->uploadToken());

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['message' => UploadIntentController::NOTHING_TO_ADD_TO], $this->answer());
        $this->assertIntentStayedUnused();
    }

    /**
     * What replaces the token (null: no header), the body, the status and the message.
     *
     * @return iterable<string, array{?string, string, int, string}>
     */
    public static function completeRequestsRefused(): iterable
    {
        yield 'no form token' => [null, '', 422, UploadIntentController::FORM_EXPIRED];
        yield 'a made-up form token' => ['not-a-token', '', 422, UploadIntentController::FORM_EXPIRED];
        yield 'a body one byte over 4 KB' => ['{token}', str_repeat(' ', UploadIntentController::MAX_BODY_BYTES + 1), 413, UploadIntentController::BODY_TOO_LARGE];
    }

    #[Test]
    #[DataProvider('completeRequestsRefused')]
    public function aCompleteRequestThatIsRefusedSaysWhyAndTheIntentStaysUnused(?string $token, string $body, int $expectedStatus, string $expectedMessage): void
    {
        $this->seedShop();
        $this->logIn($this->client, $this->people['Omar']);
        $intentId = $this->beginAsTheLoggedInPerson();

        $this->postJson($this->completePathOf($intentId), $body, '{token}' === $token ? $this->uploadToken() : $token);

        self::assertResponseStatusCodeSame($expectedStatus);
        self::assertSame(['message' => $expectedMessage], $this->answer());
        $this->assertIntentStayedUnused();
    }

    #[Test]
    public function omarWhoIsNoLongerAMemberOfShopMayNotCompleteAndTheIntentStaysUnused(): void
    {
        $this->seedShop();
        $this->logIn($this->client, $this->people['Omar']);
        $intentId = $this->beginAsTheLoggedInPerson();
        $this->makeOmarAViewerOfShop();

        $this->postJson($this->completePathOf($intentId), '', $this->uploadToken());

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['message' => UploadIntentController::NOT_ALLOWED], $this->answer());
        $this->assertIntentStayedUnused();
    }

    #[Test]
    public function anIntentCompletedADayAfterItBeganIsRefusedAsAPageOpenTooLong(): void
    {
        $this->seedShop();
        $this->logIn($this->client, $this->people['Omar']);
        $intentId = $this->beginAsTheLoggedInPerson();
        $this->clock->modify('+1 day');

        $this->postJson($this->completePathOf($intentId), '', $this->uploadToken());

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['message' => DirectUploads::EXPIRED], $this->answer());
        $this->assertIntentStayedUnused();
    }

    #[Test]
    public function aSecondCompleteOfTheSameIntentIsRefusedAndQueuesNothingMore(): void
    {
        $this->seedShop();
        $this->logIn($this->client, $this->people['Omar']);
        $intentId = $this->beginAsTheLoggedInPerson();
        $this->postJson($this->completePathOf($intentId), '', $this->uploadToken());
        self::assertResponseStatusCodeSame(202);
        $this->clock->modify('+1 minute');

        $this->postJson($this->completePathOf($intentId), '', $this->uploadToken());

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['message' => DirectUploads::USED_ALREADY], $this->answer());
        self::assertSame(['2026-11-06 12:00:00'], array_column($this->storedIntents(), 'used_at'));
        self::assertCount(1, $this->storedMediaObjects());
        self::assertCount(1, $this->queuedMessages());
    }

    #[Test]
    public function anAnonymousCompleteIsSentToLogInAndTheIntentStaysUnused(): void
    {
        $this->seedShop();
        $this->logIn($this->client, $this->people['Omar']);
        $intentId = $this->beginAsTheLoggedInPerson();
        $this->client->getCookieJar()->clear();

        $this->postJson($this->completePathOf($intentId), '', 'not-a-token');

        self::assertResponseStatusCodeSame(302);
        self::assertSame('/login', parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_PATH));
        $this->assertIntentStayedUnused();
    }

    /**
     * Acme Foods' people and SHOP-1, a bug of Shop's team in its backlog, reported by Lena and assigned to Omar.
     *
     * @param 'member'|'viewer'|null $miaTeamRole
     */
    private function seedShop(?string $miaTeamRole = 'viewer'): void
    {
        $this->people = $this->createAcmeFoodsPeople($miaTeamRole);
        $shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $backlogStep = Uuid::v7()->toRfc4122();
        $this->shopTicket = Uuid::v7()->toRfc4122();
        $parameters = [
            $this->acmeFoods->toString(), $backlogStep,
            $this->acmeFoods->toString(), $this->shopTicket, $shopProject, $this->shop->toString(), $backlogStep,
            $this->idOf('Lena'), $this->idOf('Omar'),
        ];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($parameters): int {
            $migrator->executeStatement(
                "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'Backlog', 'todo', 'backlog', 'core', 0)",
                \array_slice($parameters, 0, 2),
            );
            $migrator->executeStatement(
                "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                     step_id, reporter_id, assignee_id, reviewer_id, waiting_on_user_id, visibility, version, components, check_reasons, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 1, 'SHOP-1', 'Cart total shows the wrong sum', '', 'bug', 'major', 2, ?, ?, ?, NULL, NULL, 'all', 1, '{}', '{}',
                     TIMESTAMPTZ '2026-11-04 10:00:00+00', TIMESTAMPTZ '2026-11-05 10:00:00+00')",
                \array_slice($parameters, 2),
            );

            return 0;
        });
    }

    private function addBenOfBetaBakery(): void
    {
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->betaBakery = $betaBakery;
        $this->people['Ben'] = $this->createUserWithoutOrganization('Ben');
        $this->addMembership($this->people['Ben'], $betaBakery);
        $this->setOrgRoles($this->people['Ben'], $betaBakery, '{admin}');
    }

    private function makeOmarAViewerOfShop(): void
    {
        $parameters = [$this->acmeFoods->toString(), $this->shop->toString(), $this->idOf('Omar')];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE org_team_member SET team_role = 'viewer' WHERE organization_id = ? AND team_id = ? AND user_id = ?",
            $parameters,
        ));
    }

    private function idOf(string $person): string
    {
        return $this->people[$person]->id()->toString();
    }

    private function uploadToken(): string
    {
        return $this->csrfTokenFor($this->client, UploadIntentController::CSRF_ID);
    }

    /**
     * @param string|null $csrfToken null sends no X-CSRF-Token header
     */
    private function postJson(string $path, string $body, ?string $csrfToken): void
    {
        $server = self::JSON_HEADERS;
        if (null !== $csrfToken) {
            $server['HTTP_X_CSRF_TOKEN'] = $csrfToken;
        }
        $this->client->request('POST', $path, [], [], $server, $body);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function beginBody(array $overrides = []): string
    {
        return json_encode([
            'purpose' => 'screenshot',
            'mime' => 'image/png',
            'bytes' => self::SCREENSHOT_BYTES,
            'target' => ['kind' => 'ticket', 'id' => $this->shopTicket],
            ...$overrides,
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * @return string the new intent's id
     */
    private function beginAsTheLoggedInPerson(): string
    {
        $this->postJson(self::BEGIN_PATH, $this->beginBody(), $this->uploadToken());
        self::assertResponseStatusCodeSame(201);
        $intentId = $this->answer()['intentId'] ?? null;
        self::assertIsString($intentId);

        return $intentId;
    }

    private function completePathOf(string $intentId): string
    {
        return self::BEGIN_PATH.'/'.$intentId.'/complete';
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

    private function assertNothingStored(): void
    {
        self::assertSame([], $this->storedIntents());
        self::assertSame([], $this->storedMediaObjects());
        self::assertSame([], $this->queuedMessages());
        if (null !== $this->betaBakery) {
            self::assertSame([], $this->storedIntents($this->betaBakery));
            self::assertSame([], $this->storedMediaObjects($this->betaBakery));
            self::assertSame([], $this->queuedMessages($this->betaBakery));
        }
    }

    private function assertIntentStayedUnused(): void
    {
        $intents = $this->storedIntents();
        self::assertCount(1, $intents);
        self::assertNull($intents[0]['used_at']);
        self::assertSame([], $this->storedMediaObjects());
        self::assertSame([], $this->queuedMessages());
    }

    /**
     * @return list<array<string, mixed>> the organization's upload intents, times in UTC
     */
    private function storedIntents(?OrganizationId $organizationId = null): array
    {
        return $this->rowsOf($organizationId ?? $this->acmeFoods, "SELECT id, user_id, target_kind, target_id, max_bytes, mime_allowed,
                to_char(expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS expires_at,
                to_char(used_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS used_at
            FROM med_upload_intent WHERE organization_id = ? ORDER BY id");
    }

    /**
     * @return list<array<string, mixed>> the organization's media objects, times in UTC
     */
    private function storedMediaObjects(?OrganizationId $organizationId = null): array
    {
        return $this->rowsOf($organizationId ?? $this->acmeFoods, "SELECT id, storage_key, mime, bytes, uploaded_by, status,
                to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS created_at
            FROM med_object WHERE organization_id = ? ORDER BY id");
    }

    /**
     * @return list<object> the message of each outbox row that names the organization, oldest first
     */
    private function queuedMessages(?OrganizationId $organizationId = null): array
    {
        $serializer = new PhpSerializer();

        return array_map(static function (array $row) use ($serializer): object {
            self::assertIsString($row['body']);

            return $serializer->decode(['body' => $row['body']])->getMessage();
        }, $this->rowsOf($organizationId ?? $this->acmeFoods, "SELECT body FROM messenger_outbox WHERE queue_name = 'outbox' AND position(? in body) > 0 ORDER BY id"));
    }

    /**
     * @param string $sql with the organization id as its one parameter
     *
     * @return list<array<string, mixed>>
     */
    private function rowsOf(OrganizationId $organizationId, string $sql): array
    {
        $rows = [];
        $this->committedAs($organizationId, static function (Connection $migrator) use ($organizationId, $sql, &$rows): int {
            $rows = $migrator->fetchAllAssociative($sql, [$organizationId->toString()]);

            return 0;
        });

        return $rows;
    }
}
