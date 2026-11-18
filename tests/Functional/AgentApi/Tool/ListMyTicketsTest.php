<?php

declare(strict_types=1);

namespace App\Tests\Functional\AgentApi\Tool;

use App\AgentApi\Infrastructure\Mcp\Tool\ListMyTickets;
use App\AgentApi\Infrastructure\Mcp\ToolCallAuditor;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenHashing;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\User;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\IssuesApiTokens;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The MCP tool list_my_tickets through POST /mcp (P09.T3): the api_token firewall, the tool call gate and its audit row,
 * on Acme Foods' project SHOP of team Shop, where Lena leads and Omar is a member. The clock is frozen at
 * 2026-11-10 09:00:20 UTC, as aud_event has partitions for months around the build and none in 2099, where
 * IssuesApiTokens' tokens are issued so the database clock finds them live.
 */
final class ListMyTicketsTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesApiTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string MCP = '/mcp';
    private const string NOW = '2026-11-10 09:00:20 UTC';
    /** JSON-RPC's "Invalid params". */
    private const int INVALID_PARAMS = -32602;
    private const string PROTOCOL_VERSION = '2025-06-18';
    private const string ACCEPT = 'application/json, text/event-stream';
    private const string HIDDEN_TITLE = 'Price rules for staff';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;

    private string $doingStep;

    private int $nextNumber = 1;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(self::NOW));
        $this->people = $this->createAcmeFoodsPeople();
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->doingStep = $this->addDoingStep($this->acmeFoods);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (['aud_event', 'ins_ticket_turn', 'tkt_ticket', 'wf_step'] as $table) {
                    $migrator->executeStatement(\sprintf('DELETE FROM %s WHERE organization_id = ?', $table), [$organizationId]);
                }

                return 0;
            });
        }
        $this->removeCommittedProjects();
        $this->removeApiTokens();
        $this->removeUsersAndOrganizations();
    }

    /**
     * Omar's: SHOP-1, his turn to build, and SHOP-2, Lena's turn to build but assigned to him, changed later. Not his:
     * SHOP-3, Lena's turn with no assignee, the newest, and SHOP-4, assigned to him but Done, so it has no turn row.
     */
    #[Test]
    public function aReadTokenGetsItsOwnTicketsWithTheirStepAndNowLineAndOneOkRowWithNoContent(): void
    {
        $omar = $this->people['Omar'];
        $this->addTicket('Search shows no results', '2026-11-09 08:00:00', $omar, 'build', null);
        $this->addTicket('Cart total shows the wrong sum', '2026-11-09 10:00:00', $this->people['Lena'], 'build', $omar);
        $this->addTicket(self::HIDDEN_TITLE, '2026-11-09 12:00:00', $this->people['Lena'], 'build', null);
        $this->addDoneTicketAssignedTo($omar, 'Coupon field takes any text');
        [$tokenId, $token] = $this->issueApiToken($omar, $this->acmeFoods);
        $sessionId = $this->startSession($token);

        $this->callTool($token, $sessionId, ListMyTickets::NAME, new \stdClass());

        self::assertResponseStatusCodeSame(200);
        $answer = [
            'tickets' => [
                ['key' => 'SHOP-2', 'title' => 'Cart total shows the wrong sum', 'step' => 'In progress', 'now' => 'Lena builds it', 'is_your_turn' => false, 'is_assignee' => true, 'updated_at' => '2026-11-09T10:00:00+00:00'],
                ['key' => 'SHOP-1', 'title' => 'Search shows no results', 'step' => 'In progress', 'now' => 'Omar builds it', 'is_your_turn' => true, 'is_assignee' => false, 'updated_at' => '2026-11-09T08:00:00+00:00'],
            ],
            'has_more' => false,
        ];
        self::assertFalse($this->responseValueAt('result', 'isError'));
        self::assertSame($answer, $this->responseValueAt('result', 'structuredContent'));
        self::assertSame([['type' => 'text', 'text' => json_encode($answer, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)]], $this->responseValueAt('result', 'content'));
        self::assertStringNotContainsString(self::HIDDEN_TITLE, $this->client->getInternalResponse()->getContent());
        self::assertSame([$this->toolCallRow($omar, $tokenId, 'ok')], $this->auditRowsOf($this->acmeFoods));
        $this->assertNoAuditRowHolds($this->acmeFoods, [$token, 'SHOP-1', 'SHOP-2', 'Search shows no results', 'Cart total shows the wrong sum', 'Omar builds it']);
    }

    #[Test]
    public function aTokenWithoutTheReadScopeGetsMissingScopeNoTicketAndOneMissingScopeRow(): void
    {
        $omar = $this->people['Omar'];
        $this->addTicket(self::HIDDEN_TITLE, '2026-11-09 08:00:00', $omar, 'build', null);
        [$tokenId, $token] = $this->issueApiTokenWithScopes($omar, $this->acmeFoods, [ApiTokenScope::RoundsWrite]);
        $sessionId = $this->startSession($token);

        $this->callTool($token, $sessionId, ListMyTickets::NAME, new \stdClass());

        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->responseValueAt('result', 'isError'));
        self::assertSame([['type' => 'text', 'text' => \sprintf(ToolRefusal::MISSING_SCOPE, 'read')]], $this->responseValueAt('result', 'content'));
        self::assertNull($this->responseValueAt('result', 'structuredContent'));
        self::assertStringNotContainsString(self::HIDDEN_TITLE, $this->client->getInternalResponse()->getContent());
        self::assertSame([$this->toolCallRow($omar, $tokenId, 'missing_scope')], $this->auditRowsOf($this->acmeFoods));
    }

    /**
     * Each is a limit the input schema refuses: below 1, above 50, no number, no whole number. Each gets JSON-RPC's
     * "Invalid params", never a server error, and one invalid_input row.
     */
    #[Test]
    public function aLimitOutsideOneToFiftyGetsAnInvalidParamsErrorAndOneInvalidInputRowEach(): void
    {
        $omar = $this->people['Omar'];
        $this->addTicket(self::HIDDEN_TITLE, '2026-11-09 08:00:00', $omar, 'build', null);
        [$tokenId, $token] = $this->issueApiToken($omar, $this->acmeFoods);
        $sessionId = $this->startSession($token);
        $refusedLimits = ['zero' => 0, 'fifty-one' => 51, 'text' => 'ten', 'a fraction' => 2.5, 'negative' => -1];

        foreach ($refusedLimits as $case => $limit) {
            $this->callTool($token, $sessionId, ListMyTickets::NAME, ['limit' => $limit]);

            self::assertResponseStatusCodeSame(200, $case);
            self::assertSame(self::INVALID_PARAMS, $this->responseValueAt('error', 'code'), $case);
            $message = $this->responseValueAt('error', 'message');
            self::assertIsString($message, $case);
            self::assertStringStartsWith(\sprintf(ToolRefusal::INVALID_INPUT, ''), $message, $case);
            self::assertNull($this->responseValueAt('result'), $case);
            self::assertStringNotContainsString(self::HIDDEN_TITLE, $this->client->getInternalResponse()->getContent(), $case);
        }

        self::assertSame(array_fill(0, \count($refusedLimits), $this->toolCallRow($omar, $tokenId, 'invalid_input')), $this->auditRowsOf($this->acmeFoods));
    }

    /**
     * Omar works in both; the token is Acme Foods', and Beta Bakery's ticket, the newest, waits on him there.
     */
    #[Test]
    public function anotherOrganizationsTicketsAreNeverListed(): void
    {
        $omar = $this->people['Omar'];
        $this->addTicket('Search shows no results', '2026-11-09 08:00:00', $omar, 'build', null);
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($omar, $betaBakery);
        $this->addBetaBakeryTicketWaitingOn($omar, $betaBakery);
        [, $token] = $this->issueApiToken($omar, $this->acmeFoods);
        $sessionId = $this->startSession($token);

        $this->callTool($token, $sessionId, ListMyTickets::NAME, new \stdClass());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['SHOP-1'], array_column($this->listedTickets(), 'key'));
        self::assertStringNotContainsString(self::HIDDEN_TITLE, $this->client->getInternalResponse()->getContent());
        self::assertSame([], $this->auditRowsOf($betaBakery));
    }

    /**
     * 51 tickets wait on Omar, each changed a minute after the one before.
     */
    #[Test]
    public function itListsAtMostFiftyTheLastChangedFirstAndSaysThereAreMore(): void
    {
        $omar = $this->people['Omar'];
        for ($ticket = 0; $ticket < 51; ++$ticket) {
            $this->addTicket('Search shows no results', new \DateTimeImmutable('2026-11-01 08:00:00')->modify(\sprintf('+%d minutes', $ticket))->format('Y-m-d H:i:s'), $omar, 'build', null);
        }
        [, $token] = $this->issueApiToken($omar, $this->acmeFoods);
        $sessionId = $this->startSession($token);

        $this->callTool($token, $sessionId, ListMyTickets::NAME, new \stdClass());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(array_map(static fn (int $number): string => 'SHOP-'.$number, range(51, 2)), array_column($this->listedTickets(), 'key'));
        self::assertTrue($this->responseValueAt('result', 'structuredContent', 'has_more'));

        $this->callTool($token, $sessionId, ListMyTickets::NAME, ['limit' => 2]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['SHOP-51', 'SHOP-50'], array_column($this->listedTickets(), 'key'));
        self::assertTrue($this->responseValueAt('result', 'structuredContent', 'has_more'));
    }

    /**
     * @return list<array<mixed>> each listed ticket's fields by name; fails when the call was refused or listed none
     */
    private function listedTickets(): array
    {
        self::assertFalse($this->responseValueAt('result', 'isError'));
        $tickets = $this->responseValueAt('result', 'structuredContent', 'tickets');
        self::assertIsArray($tickets);
        self::assertNotSame([], $tickets);

        return array_values(array_map(static function (mixed $ticket): array {
            self::assertIsArray($ticket);

            return $ticket;
        }, $tickets));
    }

    private function addDoingStep(OrganizationId $organizationId): string
    {
        $stepId = Uuid::v7()->toRfc4122();
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'In progress', 'doing', 'progress', 'core', 0)",
            [$organizationId->toString(), $stepId],
        ));

        return $stepId;
    }

    /**
     * A major bug SHOP-<next number> of Shop's team, reported by Mia and last changed at $updatedAt (UTC), and its turn
     * row: waiting on $turnPerson, with $assignee on both.
     */
    private function addTicket(string $title, string $updatedAt, User $turnPerson, string $kind, ?User $assignee): void
    {
        $number = $this->nextNumber++;
        $ticketId = Uuid::v7()->toRfc4122();
        $organizationId = $this->acmeFoods->toString();
        $shopTeam = $this->shop->toString();
        $projectId = $this->shopProject;
        $stepId = $this->doingStep;
        $reporterId = $this->people['Mia']->id()->toString();
        $turnUserId = $turnPerson->id()->toString();
        $assigneeId = $assignee?->id()->toString();
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, $ticketId, $projectId, $shopTeam, $number, $title, $stepId, $reporterId, $assigneeId, $updatedAt, $turnUserId, $kind): int {
            self::insertTicket($migrator, [$organizationId, $ticketId, $projectId, $shopTeam, $number, 'SHOP-'.$number, $title, $stepId, $reporterId, $assigneeId, $updatedAt.'+00', null]);

            return (int) $migrator->executeStatement(
                "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, turn_label, kind, base_score, waiting_since, visibility, reporter_id, assignee_id)
                 VALUES (?, ?, ?, ?, 'doing', ?, NULL, ?, 100, TIMESTAMPTZ '2026-11-01 09:00:00+00', 'all', ?, ?)",
                [$organizationId, $ticketId, $shopTeam, $projectId, $turnUserId, $kind, $reporterId, $assigneeId],
            );
        });
    }

    /**
     * Closed, with no turn row, as the turn projector leaves a Done ticket; changed after every other.
     */
    private function addDoneTicketAssignedTo(User $assignee, string $title): void
    {
        $number = $this->nextNumber++;
        $row = [$this->acmeFoods->toString(), Uuid::v7()->toRfc4122(), $this->shopProject, $this->shop->toString(), $number, 'SHOP-'.$number, $title, $this->doingStep, $this->people['Mia']->id()->toString(), $assignee->id()->toString(), '2026-11-09 23:00:00+00', '2026-11-09 23:00:00+00'];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int => self::insertTicket($migrator, $row));
    }

    private function addBetaBakeryTicketWaitingOn(User $turnPerson, OrganizationId $betaBakery): void
    {
        $projectId = $this->addProject(null, 'SHOP', 'Shop', $betaBakery);
        $stepId = $this->addDoingStep($betaBakery);
        $ticketId = Uuid::v7()->toRfc4122();
        $turnUserId = $turnPerson->id()->toString();
        $organizationId = $betaBakery->toString();
        $this->committedAs($betaBakery, static function (Connection $migrator) use ($organizationId, $ticketId, $projectId, $stepId, $turnUserId): int {
            self::insertTicket($migrator, [$organizationId, $ticketId, $projectId, null, 1, 'SHOP-1', self::HIDDEN_TITLE, $stepId, $turnUserId, $turnUserId, '2026-11-10 08:00:00+00', null]);

            return (int) $migrator->executeStatement(
                "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, turn_label, kind, base_score, waiting_since, visibility, reporter_id, assignee_id)
                 VALUES (?, ?, NULL, ?, 'doing', ?, NULL, 'build', 100, TIMESTAMPTZ '2026-11-01 09:00:00+00', 'all', ?, ?)",
                [$organizationId, $ticketId, $projectId, $turnUserId, $turnUserId, $turnUserId],
            );
        });
    }

    /**
     * $row holds, in order: organization, id, project, team, number, key, title, step, reporter, assignee, updated at
     * and closed at.
     *
     * @param array{string, string, string, string|null, int, string, string, string, string, string|null, string, string|null} $row
     */
    private static function insertTicket(Connection $migrator, array $row): int
    {
        return (int) $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority,
                 priority_rank, step_id, reporter_id, assignee_id, visibility, components, check_reasons, created_at, updated_at, closed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, '', 'bug', 'major', 2, ?, ?, ?, 'all', '{}', '{}', TIMESTAMPTZ '2026-08-01 09:00:00+00', CAST(? AS timestamptz), CAST(? AS timestamptz))",
            $row,
        );
    }

    /**
     * As IssuesApiTokens::issueApiToken, with scopes of its own instead of Claude Code's defaults.
     *
     * @param list<ApiTokenScope> $scopes
     *
     * @return array{string, string} the token's id and its plain text
     */
    private function issueApiTokenWithScopes(User $user, OrganizationId $organizationId, array $scopes): array
    {
        $hashing = self::getContainer()->get('test.identity_api_token_hashing');
        \assert($hashing instanceof ApiTokenHashing);
        $issued = ApiToken::issue($this->apiTokens()->nextIdentity(), $organizationId, $user->id(), 'Claude Code', $scopes, $hashing, new \DateTimeImmutable(self::LIVE_TOKEN_ISSUED_AT), ApiToken::DEFAULT_LIFETIME_DAYS);
        $this->inTokenTransactionAs($organizationId, fn () => $this->apiTokens()->add($issued->token()));

        return [$issued->token()->id(), $issued->reveal()];
    }

    /**
     * Initializes a session and confirms it, as Claude Code does before its first tools/call.
     *
     * @return string the Mcp-Session-Id
     */
    private function startSession(string $token): string
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

        return $sessionId;
    }

    /**
     * @param mixed $arguments sent as they are, so a test can send what no schema allows
     */
    private function callTool(string $token, string $sessionId, string $toolName, mixed $arguments): void
    {
        $this->postJsonRpc($token, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => $toolName, 'arguments' => $arguments]], $sessionId);
    }

    /**
     * @param array<string, mixed> $message
     */
    private function postJsonRpc(string $token, array $message, ?string $sessionId = null): void
    {
        $sessionHeaders = null === $sessionId ? [] : ['HTTP_MCP_SESSION_ID' => $sessionId, 'HTTP_MCP_PROTOCOL_VERSION' => self::PROTOCOL_VERSION];
        $this->client->request(
            'POST',
            self::MCP,
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => self::ACCEPT, 'HTTP_AUTHORIZATION' => 'Bearer '.$token, ...$sessionHeaders],
            content: json_encode($message, \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array{string, string, string, string, string, null, string, array<string, string>}
     */
    private function toolCallRow(User $user, string $tokenId, string $outcome): array
    {
        return ['token', $user->id()->toString(), $tokenId, ToolCallAuditor::ACTION, ToolCallAuditor::TOOL_TARGET_TYPE, null, '2026-11-10 09:00:20', ['outcome' => $outcome, 'tool' => ListMyTickets::NAME]];
    }

    /**
     * @return list<array{mixed, mixed, mixed, mixed, mixed, mixed, mixed, array<mixed>}> actor kind, user, token,
     *                                                                                    action, target type, target,
     *                                                                                    time, data by key
     */
    private function auditRowsOf(OrganizationId $organizationId): array
    {
        $rows = [];
        $this->committedAs($organizationId, static function (Connection $migrator) use ($organizationId, &$rows): int {
            $rows = $migrator->fetchAllNumeric(
                "SELECT actor_kind, actor_user_id, actor_token_id, action, target_type, target_id, to_char(at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS'), data::text FROM aud_event WHERE organization_id = ? ORDER BY at, id",
                [$organizationId->toString()],
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
     * Every column of every row, as text, holds none of $secrets.
     *
     * @param list<string> $secrets
     */
    private function assertNoAuditRowHolds(OrganizationId $organizationId, array $secrets): void
    {
        $rowTexts = [];
        $this->committedAs($organizationId, static function (Connection $migrator) use ($organizationId, &$rowTexts): int {
            $rowTexts = $migrator->fetchFirstColumn('SELECT e::text FROM aud_event e WHERE organization_id = ?', [$organizationId->toString()]);

            return 0;
        });
        self::assertNotSame([], $rowTexts);
        foreach ($rowTexts as $rowText) {
            self::assertIsString($rowText);
            foreach ($secrets as $secret) {
                self::assertStringNotContainsString($secret, $rowText);
            }
        }
    }

    /**
     * @return array<mixed>
     */
    private function responseJson(): array
    {
        $json = json_decode($this->client->getInternalResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($json);

        return $json;
    }

    /**
     * @return mixed null when the last key is missing; fails when a key before it does not hold an array
     */
    private function responseValueAt(string ...$path): mixed
    {
        $value = $this->responseJson();
        foreach ($path as $key) {
            self::assertIsArray($value, \sprintf('no %s in the response', implode('.', $path)));
            $value = $value[$key] ?? null;
        }

        return $value;
    }
}
