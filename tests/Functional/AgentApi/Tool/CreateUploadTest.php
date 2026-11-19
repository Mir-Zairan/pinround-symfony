<?php

declare(strict_types=1);

namespace App\Tests\Functional\AgentApi\Tool;

use App\AgentApi\Infrastructure\Mcp\Tool\CreateUpload;
use App\AgentApi\Infrastructure\Mcp\ToolCallAuditor;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenHashing;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\User;
use App\Media\Api\UploadRefused;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\IssuesApiTokens;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Review\SeedsTicketRounds;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * create_upload through POST /mcp end to end (P09.T5 steps 1-2): the api_token firewall, the gate, the voters of the
 * draft round editor and the upload field, Review's startDraft, Media's Uploads::begin and the audit row, on Acme Foods'
 * ticket SHOP-1, which Mia reported and Omar is assigned. The clock is fixed on 10 Nov 2026, a day aud_event has a
 * partition for and not today. The begin limiter counts on the state Valkey, keyed by user id; every test has new
 * users, so their counts start empty.
 */
final class CreateUploadTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesApiTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use SeedsTicketRounds;

    private const string MCP = '/mcp';
    private const string NOW = '2026-11-10 09:00:20 UTC';
    private const string PROTOCOL_VERSION = '2025-06-18';
    private const string SHARED_AT = '2026-11-05 09:00:00+00';
    private const int SCREENSHOT_BYTES = 245_760;
    private const string SHA256 = '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08';
    /** The begins of media_upload_begin_per_user in its window. */
    private const int BEGIN_LIMIT = 60;

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;
    private string $stepId;
    private string $ticketId;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(self::NOW));
        $this->people = $this->createAcmeFoodsPeople('member');
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->stepId = $this->addTodoStep($this->acmeFoods);
        $this->ticketId = $this->addTicket('SHOP-1');
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                $migrator->executeStatement('DELETE FROM aud_event WHERE organization_id = ?', [$organizationId]);
                $migrator->executeStatement('DELETE FROM med_upload_intent WHERE organization_id = ?', [$organizationId]);

                return 0;
            });
        }
        $this->removeApiTokens();
        $this->removeSeededRounds();
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function theOwnerOfADraftBeginsAnUploadIntoItWithOneOkRowThatHoldsNoContent(): void
    {
        $omarsDraft = $this->addRound($this->ticketId, null, 'draft', 'Omar', null);
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);
        $roundsBefore = $this->roundRowsOf($this->ticketId);

        $this->callCreateUpload($token, $this->arguments());

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->responseValueAt('result', 'isError'));
        $slot = $this->responseValueAt('result', 'structuredContent');
        self::assertIsArray($slot);
        self::assertSame(['upload_id', 'put_url', 'headers', 'expires_at'], array_keys($slot));
        $intentId = $slot['upload_id'];
        self::assertIsString($intentId);
        self::assertTrue(Uuid::isValid($intentId));
        self::assertIsString($slot['put_url']);
        self::assertStringStartsWith(\sprintf('http://localhost:9000/pinround/uploads/%s/%s?', $this->acmeFoods->toString(), $intentId), $slot['put_url']);
        self::assertSame(['Content-Type' => 'image/png', 'Content-Length' => (string) self::SCREENSHOT_BYTES], $slot['headers']);
        self::assertSame('2026-11-10T09:05:20+00:00', $slot['expires_at']);
        self::assertSame([$this->intentRow($intentId, 'Omar', $omarsDraft)], $this->storedIntents($this->acmeFoods));
        self::assertSame($roundsBefore, $this->roundRowsOf($this->ticketId));
        self::assertSame([$this->toolRow('Omar', $tokenId, CreateUpload::ROUND_AUDIT_TYPE, $omarsDraft, 'ok')], $this->toolRowsOf($this->acmeFoods));
        $this->assertNoAuditRowHolds([self::SHA256, $slot['put_url'], 'image/png', $token]);
    }

    #[Test]
    public function theAssigneeWithoutADraftStartsOneAndTheUploadTargetsIt(): void
    {
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);
        self::assertSame([], $this->roundRowsOf($this->ticketId));

        $this->callCreateUpload($token, $this->arguments());

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->responseValueAt('result', 'isError'));
        $rounds = $this->roundRowsOf($this->ticketId);
        self::assertCount(1, $rounds);
        self::assertSame(['draft', $this->people['Omar']->id()->toString(), null, null, 'open', null], [$rounds[0]['kind'], $rounds[0]['owner_id'], $rounds[0]['number'], $rounds[0]['shared_at'], $rounds[0]['state'], $rounds[0]['deleted_at']]);
        $newDraft = $rounds[0]['id'];
        self::assertIsString($newDraft);
        $intentId = $this->responseValueAt('result', 'structuredContent', 'upload_id');
        self::assertIsString($intentId);
        self::assertSame([$this->intentRow($intentId, 'Omar', $newDraft)], $this->storedIntents($this->acmeFoods));
        self::assertSame([$this->toolRow('Omar', $tokenId, CreateUpload::TICKET_AUDIT_TYPE, $this->ticketId, 'ok')], $this->toolRowsOf($this->acmeFoods));
    }

    /**
     * Mia reported SHOP-1 and may see and edit it, but she is not its assignee and it is not her turn, so the voter
     * denies her, which the gate answers as not found.
     */
    #[Test]
    public function aPersonWhoMayNotStartADraftIsNotFoundAndNothingIsWritten(): void
    {
        [$tokenId, $token] = $this->issueApiToken($this->people['Mia'], $this->acmeFoods);

        $this->callCreateUpload($token, $this->arguments());

        $this->assertRefused(ToolRefusal::NOT_FOUND);
        self::assertSame([], $this->roundRowsOf($this->ticketId));
        self::assertSame([], $this->storedIntents($this->acmeFoods));
        self::assertSame([$this->toolRow('Mia', $tokenId, CreateUpload::TICKET_AUDIT_TYPE, $this->ticketId, 'not_found')], $this->toolRowsOf($this->acmeFoods));
    }

    /**
     * Omar's draft on SHOP-1 is his alone: Mia's call never reaches it, and she may not start one of her own.
     */
    #[Test]
    public function anotherPersonsDraftIsNeverTheTargetAndNothingIsWritten(): void
    {
        $this->addRound($this->ticketId, null, 'draft', 'Omar', null);
        [$tokenId, $token] = $this->issueApiToken($this->people['Mia'], $this->acmeFoods);
        $roundsBefore = $this->roundRowsOf($this->ticketId);

        $this->callCreateUpload($token, $this->arguments());

        $this->assertRefused(ToolRefusal::NOT_FOUND);
        self::assertSame($roundsBefore, $this->roundRowsOf($this->ticketId));
        self::assertSame([], $this->storedIntents($this->acmeFoods));
        self::assertSame([$this->toolRow('Mia', $tokenId, CreateUpload::TICKET_AUDIT_TYPE, $this->ticketId, 'not_found')], $this->toolRowsOf($this->acmeFoods));
    }

    /**
     * A shared draft takes no more of its owner's pictures through this tool: like the Rounds section, the assignee
     * starts a new draft, and the shared one keeps its row as it was.
     */
    #[Test]
    public function theOwnersSharedDraftIsNeverTheTargetAndANewDraftIsStarted(): void
    {
        $sharedDraft = $this->addRound($this->ticketId, 1, 'draft', 'Omar', self::SHARED_AT);
        [, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);
        $sharedRowBefore = $this->roundRowsOf($this->ticketId)[0];

        $this->callCreateUpload($token, $this->arguments());

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->responseValueAt('result', 'isError'));
        $rounds = $this->roundRowsOf($this->ticketId);
        self::assertCount(2, $rounds);
        self::assertSame($sharedRowBefore, $rounds[0]);
        self::assertNotSame($sharedDraft, $rounds[1]['id']);
        self::assertNull($rounds[1]['shared_at']);
        $intents = $this->storedIntents($this->acmeFoods);
        self::assertCount(1, $intents);
        self::assertSame(['round', $rounds[1]['id']], [$intents[0]['target_kind'], $intents[0]['target_id']]);
    }

    #[Test]
    public function aTokenWithoutRoundsWriteGetsMissingScopeAndNothingIsWritten(): void
    {
        [$tokenId, $token] = $this->issueReadOnlyToken($this->people['Omar'], $this->acmeFoods);

        $this->callCreateUpload($token, $this->arguments());

        $this->assertRefused(\sprintf(ToolRefusal::MISSING_SCOPE, 'rounds:write'));
        self::assertSame([], $this->roundRowsOf($this->ticketId));
        self::assertSame([], $this->storedIntents($this->acmeFoods));
        self::assertSame([$this->toolRow('Omar', $tokenId, ToolCallAuditor::TOOL_TARGET_TYPE, null, 'missing_scope')], $this->toolRowsOf($this->acmeFoods));
    }

    /**
     * Omar works in Beta Bakery too, which has a SHOP-1 of its own that Mia is assigned. Under his Beta Bakery token the
     * key finds that ticket, his draft on Acme Foods' SHOP-1 is another organization's, and he may not start one on
     * Beta Bakery's: not found, and nothing is written in either organization.
     */
    #[Test]
    public function aDraftOfAnotherOrganizationIsNeverTheTargetAndNothingIsWritten(): void
    {
        $omarsDraft = $this->addRound($this->ticketId, null, 'draft', 'Omar', null);
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($this->people['Omar'], $betaBakery);
        $this->addMembership($this->people['Mia'], $betaBakery);
        $betaShop = $this->addProject(null, 'SHOP', 'Shop', $betaBakery);
        $betaTicket = $this->addTicketOf($betaBakery, $betaShop, 'SHOP-1', $this->addTodoStep($betaBakery), $this->people['Mia']);
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $betaBakery);
        $roundsBefore = $this->roundRowsOf($this->ticketId);

        $this->callCreateUpload($token, $this->arguments());

        $this->assertRefused(ToolRefusal::NOT_FOUND);
        self::assertSame($roundsBefore, $this->roundRowsOf($this->ticketId));
        self::assertSame($omarsDraft, $roundsBefore[0]['id'] ?? null);
        self::assertSame([], $this->storedIntents($this->acmeFoods));
        self::assertSame([], $this->storedIntents($betaBakery));
        self::assertSame([], $this->toolRowsOf($this->acmeFoods));
        self::assertSame([$this->toolRow('Omar', $tokenId, CreateUpload::TICKET_AUDIT_TYPE, $betaTicket, 'not_found')], $this->toolRowsOf($betaBakery));
    }

    /**
     * SHOP-1 moves to Mobile, for that team only, and to Lena: Omar is in no role of Mobile and no longer involved, so
     * he may not see it. MediaPermission UPLOAD still grants him his own unshared draft without reading the ticket, so
     * create_upload asks TicketPermission VIEW itself and answers as for an unknown key.
     */
    #[Test]
    public function theOwnersDraftOnATicketTheyMayNoLongerSeeIsNotFoundAndNothingIsWritten(): void
    {
        $this->addRound($this->ticketId, null, 'draft', 'Omar', null);
        $parameters = [$this->mobile->toString(), $this->people['Lena']->id()->toString(), $this->acmeFoods->toString(), $this->ticketId];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE tkt_ticket SET team_id = ?, visibility = 'team', assignee_id = ? WHERE organization_id = ? AND id = ?",
            $parameters,
        ));
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);
        $roundsBefore = $this->roundRowsOf($this->ticketId);
        self::assertCount(1, $roundsBefore);

        $this->callCreateUpload($token, $this->arguments());

        $this->assertRefused(ToolRefusal::NOT_FOUND);
        self::assertSame($roundsBefore, $this->roundRowsOf($this->ticketId));
        self::assertSame([], $this->storedIntents($this->acmeFoods));
        self::assertSame([$this->toolRow('Omar', $tokenId, ToolCallAuditor::TOOL_TARGET_TYPE, null, 'not_found')], $this->toolRowsOf($this->acmeFoods));
    }

    /**
     * The fields that differ from a PNG screenshot of 240 KB, and the web's plain message for them.
     *
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function filesRefusedBeforeAnyUrl(): iterable
    {
        yield 'a GIF' => [['mime' => 'image/gif'], 'This file is not a PNG, JPEG or WebP screenshot.'];
        yield 'a file one byte over 15 MB' => [['bytes' => 15 * 1024 * 1024 + 1], 'This file is empty or bigger than 15 MB. Pick a screenshot of up to 15 MB.'];
        yield 'an empty file' => [['bytes' => 0], 'This file is empty or bigger than 15 MB. Pick a screenshot of up to 15 MB.'];
    }

    /**
     * Omar has no draft, so the call starts one before Uploads refuses: the gate's savepoint takes it back.
     *
     * @param array<string, mixed> $fields
     */
    #[Test]
    #[DataProvider('filesRefusedBeforeAnyUrl')]
    public function aFileOfTheWrongTypeOrSizeIsRefusedWithTheWebsMessageAndNothingIsWritten(array $fields, string $message): void
    {
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);

        $this->callCreateUpload($token, $this->arguments($fields));

        $this->assertRefused($message);
        self::assertSame([], $this->roundRowsOf($this->ticketId));
        self::assertSame([], $this->storedIntents($this->acmeFoods));
        self::assertSame([$this->toolRow('Omar', $tokenId, CreateUpload::TICKET_AUDIT_TYPE, $this->ticketId, 'refused')], $this->toolRowsOf($this->acmeFoods));
    }

    #[Test]
    public function aBeginPastTheCallersLimitIsRefusedWithTheWebsMessageAndNothingIsWritten(): void
    {
        $omarsDraft = $this->addRound($this->ticketId, null, 'draft', 'Omar', null);
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);
        $beginsPerUser = self::getContainer()->get('limiter.media_upload_begin_per_user');
        self::assertTrue($beginsPerUser->create($this->people['Omar']->id()->toString())->consume(self::BEGIN_LIMIT)->isAccepted());

        $this->callCreateUpload($token, $this->arguments());

        $this->assertRefused(UploadRefused::ADDING_TOO_FAST);
        self::assertSame([], $this->storedIntents($this->acmeFoods));
        self::assertSame([$this->toolRow('Omar', $tokenId, CreateUpload::ROUND_AUDIT_TYPE, $omarsDraft, 'refused')], $this->toolRowsOf($this->acmeFoods));
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function arguments(array $overrides = []): array
    {
        return [...['key' => 'SHOP-1', 'mime' => 'image/png', 'bytes' => self::SCREENSHOT_BYTES, 'sha256' => self::SHA256], ...$overrides];
    }

    private function addTodoStep(OrganizationId $organizationId): string
    {
        $stepId = Uuid::v7()->toRfc4122();
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'To do', 'todo', 'todo', 'core', 1)",
            [$organizationId->toString(), $stepId],
        ));

        return $stepId;
    }

    /**
     * A ticket of no team that $person reported and is assigned, unlike SeedsTicketRounds::addTicket, which is always
     * Acme Foods' Shop ticket.
     *
     * @return string the ticket's id
     */
    private function addTicketOf(OrganizationId $organizationId, string $projectId, string $key, string $stepId, User $person): string
    {
        $ticketId = Uuid::v7()->toRfc4122();
        $parameters = [$organizationId->toString(), $ticketId, $projectId, (int) substr($key, (int) strpos($key, '-') + 1), $key, $stepId, $person->id()->toString(), $person->id()->toString()];
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                 step_id, reporter_id, assignee_id, visibility, version, components, check_reasons, created_at, updated_at)
             VALUES (?, ?, ?, NULL, ?, ?, 'Checkout button is hidden', '', 'bug', 'major', 2, ?, ?, ?, 'all', 3, '{}', '{}',
                 TIMESTAMPTZ '2026-11-04 10:00:00+00', TIMESTAMPTZ '2026-11-05 10:00:00+00')",
            $parameters,
        ));

        return $ticketId;
    }

    /**
     * @return array{string, string} the token's id and its plain text
     */
    private function issueReadOnlyToken(User $user, OrganizationId $organizationId): array
    {
        $hashing = self::getContainer()->get('test.identity_api_token_hashing');
        \assert($hashing instanceof ApiTokenHashing);
        $issued = ApiToken::issue($this->apiTokens()->nextIdentity(), $organizationId, $user->id(), 'Claude Code', [ApiTokenScope::Read], $hashing, new \DateTimeImmutable(self::LIVE_TOKEN_ISSUED_AT), ApiToken::DEFAULT_LIFETIME_DAYS);
        $this->inTokenTransactionAs($organizationId, fn () => $this->apiTokens()->add($issued->token()));

        return [$issued->token()->id(), $issued->reveal()];
    }

    /**
     * Starts and confirms a session as Claude Code does, then calls create_upload in it.
     *
     * @param array<string, mixed> $arguments
     */
    private function callCreateUpload(string $token, array $arguments): void
    {
        $this->postJsonRpc($token, [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => self::PROTOCOL_VERSION, 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'claude-code', 'version' => '1.0.0']],
        ]);
        self::assertResponseStatusCodeSame(200);
        $sessionId = $this->client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertIsString($sessionId);
        $this->postJsonRpc($token, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $sessionId);
        self::assertResponseStatusCodeSame(202);

        $this->postJsonRpc($token, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => CreateUpload::NAME, 'arguments' => $arguments]], $sessionId);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function postJsonRpc(string $token, array $message, ?string $sessionId = null): void
    {
        $sessionHeaders = null === $sessionId ? [] : ['HTTP_MCP_SESSION_ID' => $sessionId, 'HTTP_MCP_PROTOCOL_VERSION' => self::PROTOCOL_VERSION];
        $this->client->request('POST', self::MCP, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ...$sessionHeaders,
        ], content: json_encode($message, \JSON_THROW_ON_ERROR));
    }

    private function assertRefused(string $message): void
    {
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->responseValueAt('result', 'isError'));
        self::assertSame([['type' => 'text', 'text' => $message]], $this->responseValueAt('result', 'content'));
        self::assertNull($this->responseValueAt('result', 'structuredContent'));
    }

    /**
     * @return array<string, mixed> an unused intent of a PNG screenshot of 240 KB, begun at the fixed clock
     */
    private function intentRow(string $intentId, string $person, string $roundId): array
    {
        return [
            'id' => $intentId,
            'user_id' => $this->people[$person]->id()->toString(),
            'target_kind' => 'round',
            'target_id' => $roundId,
            'max_bytes' => self::SCREENSHOT_BYTES,
            'mime_allowed' => '{image/png}',
            'expires_at' => '2026-11-11 09:00:20',
            'used_at' => null,
        ];
    }

    /**
     * @return list<array<string, mixed>> the organization's upload intents, times in UTC
     */
    private function storedIntents(OrganizationId $organizationId): array
    {
        $rows = [];
        $this->committedAs($organizationId, static function (Connection $migrator) use ($organizationId, &$rows): int {
            $rows = $migrator->fetchAllAssociative(
                "SELECT id, user_id, target_kind, target_id, max_bytes, mime_allowed,
                    to_char(expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS expires_at,
                    to_char(used_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS used_at
                 FROM med_upload_intent WHERE organization_id = ? ORDER BY id",
                [$organizationId->toString()],
            );

            return 0;
        });

        return $rows;
    }

    /**
     * @return array{string, string, string, string, string, string|null, string, array<string, string>}
     */
    private function toolRow(string $person, string $tokenId, string $targetType, ?string $targetId, string $outcome): array
    {
        return ['token', $this->people[$person]->id()->toString(), $tokenId, ToolCallAuditor::ACTION, $targetType, $targetId, '2026-11-10 09:00:20', ['outcome' => $outcome, 'tool' => CreateUpload::NAME]];
    }

    /**
     * @return list<array{mixed, mixed, mixed, mixed, mixed, mixed, mixed, array<mixed>}>
     */
    private function toolRowsOf(OrganizationId $organizationId): array
    {
        $rows = [];
        $this->committedAs($organizationId, static function (Connection $migrator) use ($organizationId, &$rows): int {
            $rows = $migrator->fetchAllNumeric(
                "SELECT actor_kind, actor_user_id, actor_token_id, action, target_type, target_id, to_char(at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS'), data::text FROM aud_event WHERE organization_id = ? AND action = ? ORDER BY at, id",
                [$organizationId->toString(), ToolCallAuditor::ACTION],
            );

            return 0;
        });

        return array_map(static function (array $row): array {
            self::assertIsString($row[7]);
            $data = json_decode($row[7], true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($data);
            ksort($data);

            return [$row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $row[6], $data];
        }, $rows);
    }

    /**
     * Every column of every Acme Foods audit row, as text, holds none of $texts.
     *
     * @param list<string> $texts
     */
    private function assertNoAuditRowHolds(array $texts): void
    {
        $rowTexts = [];
        $organizationId = $this->acmeFoods->toString();
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$rowTexts): int {
            $rowTexts = $migrator->fetchFirstColumn('SELECT e::text FROM aud_event e WHERE organization_id = ?', [$organizationId]);

            return 0;
        });
        self::assertNotSame([], $rowTexts);
        foreach ($rowTexts as $rowText) {
            self::assertIsString($rowText);
            foreach ($texts as $text) {
                self::assertStringNotContainsString($text, $rowText);
            }
        }
    }

    /**
     * @return mixed null when the last key is missing; fails when a key before it does not hold an array
     */
    private function responseValueAt(string ...$path): mixed
    {
        $value = json_decode($this->client->getInternalResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        foreach ($path as $key) {
            self::assertIsArray($value, \sprintf('no %s in the response', implode('.', $path)));
            $value = $value[$key] ?? null;
        }

        return $value;
    }
}
