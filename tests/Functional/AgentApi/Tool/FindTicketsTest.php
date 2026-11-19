<?php

declare(strict_types=1);

namespace App\Tests\Functional\AgentApi\Tool;

use App\AgentApi\Infrastructure\Mcp\Tool\FindTickets;
use App\AgentApi\Infrastructure\Mcp\ToolCallAuditor;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\AgentApi\Infrastructure\Mcp\UntrustedText;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenHashing;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\User;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\IssuesApiTokens;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Integration\ReadsSentSql;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The MCP tool find_tickets through POST /mcp (P13.T3): the api_token firewall, the tool call gate and its audit row,
 * on Acme Foods' projects SHOP and OPS of team Shop, where Lena leads and Omar is a member, and MOB of team Mobile, which
 * Omar is not in. The clock is frozen at 2026-11-10 09:00:20 UTC, as aud_event has partitions for months around the
 * build and none in 2099, where IssuesApiTokens' tokens are issued so the database clock finds them live.
 */
final class FindTicketsTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesApiTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use ReadsSentSql;

    private const string MCP = '/mcp';
    private const string NOW = '2026-11-10 09:00:20 UTC';
    /** JSON-RPC's "Invalid params". */
    private const int INVALID_PARAMS = -32602;
    private const string PROTOCOL_VERSION = '2025-06-18';
    private const string ACCEPT = 'application/json, text/event-stream';
    private const string HIDDEN_TITLE = 'Cart rules for staff';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    /** @var array<string, string> project id by key */
    private array $projects = [];

    private string $doingStep;

    private string $doneStep;

    /** @var array<string, int> the next ticket number by project key */
    private array $nextNumbers = [];

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(self::NOW));
        $this->people = $this->createAcmeFoodsPeople();
        $this->projects['SHOP'] = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->projects['OPS'] = $this->addProject($this->shop, 'OPS', 'Operations');
        $this->projects['MOB'] = $this->addProject($this->mobile, 'MOB', 'Mobile');
        $this->doingStep = $this->addStep($this->acmeFoods, 'In progress', 'doing', 'progress', 0);
        $this->doneStep = $this->addStep($this->acmeFoods, 'Done', 'done', 'done', 7);
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
     * Omar's matches: SHOP-1, his turn; SHOP-2, Lena's turn but assigned to him; SHOP-3, Lena's turn with no assignee,
     * so not his to draft on. Never his: SHOP-4, assigned to him but Done; SHOP-5, which does not match; MOB-1, a
     * team-only ticket of Mobile, which he is not in.
     */
    #[Test]
    public function aReadTokenFindsTheOpenMatchesItMaySeeWithWhoseTurnAndMayStartDraftAndOneOkRowWithNoContent(): void
    {
        $omar = $this->people['Omar'];
        $lena = $this->people['Lena'];
        $this->addTicket('SHOP', 'Cart total shows the wrong sum', $this->doingStep, $omar, null);
        $this->addTicket('SHOP', 'Cart badge keeps the old count', $this->doingStep, $lena, $omar);
        $this->addTicket('SHOP', 'Cart page loads slowly', $this->doingStep, $lena, null);
        $this->addTicket('SHOP', 'Cart coupon takes any text', $this->doneStep, null, $omar);
        $this->addTicket('SHOP', 'Search shows no results', $this->doingStep, $omar, null);
        $this->addTicket('MOB', self::HIDDEN_TITLE, $this->doingStep, $lena, null, 'team');
        [$tokenId, $token] = $this->issueApiToken($omar, $this->acmeFoods);
        $sessionId = $this->startSession($token);

        $this->callTool($token, $sessionId, FindTickets::NAME, ['text' => 'cart']);

        self::assertResponseStatusCodeSame(200);
        $structuredContent = $this->responseValueAt('result', 'structuredContent');
        self::assertIsArray($structuredContent);
        self::assertSame([
            'SHOP-1' => ['key' => 'SHOP-1', 'title' => UntrustedText::wrap('Cart total shows the wrong sum'), 'step' => 'In progress', 'whose_turn' => UntrustedText::wrap('Omar'), 'may_start_draft' => true],
            'SHOP-2' => ['key' => 'SHOP-2', 'title' => UntrustedText::wrap('Cart badge keeps the old count'), 'step' => 'In progress', 'whose_turn' => UntrustedText::wrap('Lena'), 'may_start_draft' => true],
            'SHOP-3' => ['key' => 'SHOP-3', 'title' => UntrustedText::wrap('Cart page loads slowly'), 'step' => 'In progress', 'whose_turn' => UntrustedText::wrap('Lena'), 'may_start_draft' => false],
        ], $this->foundTicketsByKey());
        self::assertFalse($structuredContent['has_more'] ?? null);
        self::assertSame([['type' => 'text', 'text' => json_encode($structuredContent, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)]], $this->responseValueAt('result', 'content'));
        self::assertStringNotContainsString(self::HIDDEN_TITLE, $this->client->getInternalResponse()->getContent());
        self::assertSame([$this->toolCallRow($omar, $tokenId, 'ok')], $this->auditRowsOf($this->acmeFoods));
        $this->assertNoAuditRowHolds($this->acmeFoods, [$token, 'cart', 'Cart', 'SHOP-1', 'SHOP-2', 'SHOP-3']);
    }

    /**
     * The exact key is the strongest match, ahead of a title that merely holds the same words.
     */
    #[Test]
    public function aTicketKeyFindsThatTicketFirst(): void
    {
        $omar = $this->people['Omar'];
        $this->addTicket('SHOP', 'Cart total shows the wrong sum', $this->doingStep, $omar, null);
        $this->addTicket('SHOP', 'Shop 2 cart total', $this->doingStep, $omar, null);
        [, $token] = $this->issueApiToken($omar, $this->acmeFoods);
        $sessionId = $this->startSession($token);

        $this->callTool($token, $sessionId, FindTickets::NAME, ['text' => ' shop-1 ']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('SHOP-1', array_key_first($this->foundTicketsByKey()));
    }

    /**
     * Twelve tickets match, each waiting on one of three people; one other matches a word of its own. Both searches
     * send the same number of queries, and the matches are read by one statement.
     */
    #[Test]
    public function itReturnsAtMostTenSaysThereAreMoreAndSendsTheSameQueriesForOneMatchAsForTwelve(): void
    {
        $turnPeople = [$this->people['Omar'], $this->people['Lena'], $this->people['Mia']];
        for ($ticket = 0; $ticket < 12; ++$ticket) {
            $this->addTicket('SHOP', 'Cart total shows the wrong sum', $this->doingStep, $turnPeople[$ticket % 3], null);
        }
        $this->addTicket('SHOP', 'Coupon field takes any text', $this->doingStep, $this->people['Lena'], null);
        [, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);
        $sessionId = $this->startSession($token);
        // The first call fills the steps' cache, which a cold call reads besides.
        $this->callTool($token, $sessionId, FindTickets::NAME, ['text' => 'coupon']);
        self::assertResponseStatusCodeSame(200);

        $oneMatchSql = $this->sqlOfCall($token, $sessionId, ['text' => 'coupon']);
        self::assertSame(['SHOP-13'], array_keys($this->foundTicketsByKey()));
        $twelveMatchesSql = $this->sqlOfCall($token, $sessionId, ['text' => 'cart']);

        self::assertCount(10, $this->foundTicketsByKey());
        self::assertTrue($this->responseValueAt('result', 'structuredContent', 'has_more'));
        self::assertSame(\count($oneMatchSql), \count($twelveMatchesSql));
        self::assertCount(1, array_filter($twelveMatchesSql, static fn (string $sql): bool => str_contains($sql, 'search_ticket_ids')));
    }

    /**
     * 201 characters are refused by the input schema before the tool runs, so no ticket, turn or project is read; 200,
     * each a two-byte character, are searched.
     */
    #[Test]
    public function aTextOverTwoHundredCharactersIsRefusedBeforeAnyTicketQueryWithOneInvalidInputRow(): void
    {
        $omar = $this->people['Omar'];
        $this->addTicket('SHOP', 'Cart total shows the wrong sum', $this->doingStep, $omar, null);
        [$tokenId, $token] = $this->issueApiToken($omar, $this->acmeFoods);
        $sessionId = $this->startSession($token);

        $refusedSql = $this->sqlOfCall($token, $sessionId, ['text' => str_repeat('é', 201)]);

        self::assertSame(self::INVALID_PARAMS, $this->responseValueAt('error', 'code'));
        $message = $this->responseValueAt('error', 'message');
        self::assertIsString($message);
        self::assertStringStartsWith(\sprintf(ToolRefusal::INVALID_INPUT, '/text: '), $message);
        self::assertNull($this->responseValueAt('result'));
        self::assertNotSame([], $refusedSql);
        self::assertSame([], array_values(array_filter($refusedSql, static fn (string $sql): bool => 1 === preg_match('/search_ticket_ids|\btkt_|\bins_ticket_turn\b/', $sql))));
        self::assertSame([$this->toolCallRow($omar, $tokenId, 'invalid_input')], $this->auditRowsOf($this->acmeFoods));

        $this->sqlOfCall($token, $sessionId, ['text' => str_repeat('é', 200)]);

        self::assertFalse($this->responseValueAt('result', 'isError'));
        self::assertSame(['tickets' => [], 'has_more' => false], $this->responseValueAt('result', 'structuredContent'));
    }

    #[Test]
    public function aProjectKeySearchesThatProjectOnlyAndAnUnknownOneIsNotFound(): void
    {
        $omar = $this->people['Omar'];
        $this->addTicket('SHOP', 'Cart total shows the wrong sum', $this->doingStep, $omar, null);
        $this->addTicket('OPS', 'Cart export fails', $this->doingStep, $omar, null);
        [$tokenId, $token] = $this->issueApiToken($omar, $this->acmeFoods);
        $sessionId = $this->startSession($token);

        $this->callTool($token, $sessionId, FindTickets::NAME, ['text' => 'cart']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['OPS-1', 'SHOP-1'], array_keys($this->foundTicketsByKey()));

        $this->callTool($token, $sessionId, FindTickets::NAME, ['text' => 'cart', 'project' => 'OPS']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['OPS-1'], array_keys($this->foundTicketsByKey()));

        $this->callTool($token, $sessionId, FindTickets::NAME, ['text' => 'cart', 'project' => 'NOPE']);
        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->responseValueAt('result', 'isError'));
        self::assertSame([['type' => 'text', 'text' => ToolRefusal::NOT_FOUND]], $this->responseValueAt('result', 'content'));
        self::assertSame(
            [$this->toolCallRow($omar, $tokenId, 'ok'), $this->toolCallRow($omar, $tokenId, 'ok'), $this->toolCallRow($omar, $tokenId, 'not_found')],
            $this->auditRowsOf($this->acmeFoods),
        );
    }

    #[Test]
    public function aTokenWithoutTheReadScopeGetsMissingScopeNoTicketAndOneMissingScopeRow(): void
    {
        $omar = $this->people['Omar'];
        $this->addTicket('SHOP', self::HIDDEN_TITLE, $this->doingStep, $omar, null);
        [$tokenId, $token] = $this->issueApiTokenWithScopes($omar, $this->acmeFoods, [ApiTokenScope::RoundsWrite]);
        $sessionId = $this->startSession($token);

        $this->callTool($token, $sessionId, FindTickets::NAME, ['text' => 'cart']);

        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->responseValueAt('result', 'isError'));
        self::assertSame([['type' => 'text', 'text' => \sprintf(ToolRefusal::MISSING_SCOPE, 'read')]], $this->responseValueAt('result', 'content'));
        self::assertNull($this->responseValueAt('result', 'structuredContent'));
        self::assertStringNotContainsString(self::HIDDEN_TITLE, $this->client->getInternalResponse()->getContent());
        self::assertSame([$this->toolCallRow($omar, $tokenId, 'missing_scope')], $this->auditRowsOf($this->acmeFoods));
    }

    /**
     * Omar works in both; the token is Acme Foods', and Beta Bakery's matching ticket is assigned to him there.
     */
    #[Test]
    public function anotherOrganizationsTicketsAreNeverFound(): void
    {
        $omar = $this->people['Omar'];
        $this->addTicket('SHOP', 'Cart total shows the wrong sum', $this->doingStep, $omar, null);
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($omar, $betaBakery);
        $this->addBetaBakeryTicketAssignedTo($omar, $betaBakery);
        [, $token] = $this->issueApiToken($omar, $this->acmeFoods);
        $sessionId = $this->startSession($token);

        $this->callTool($token, $sessionId, FindTickets::NAME, ['text' => 'cart']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['SHOP-1'], array_keys($this->foundTicketsByKey()));
        self::assertStringNotContainsString(self::HIDDEN_TITLE, $this->client->getInternalResponse()->getContent());
        self::assertSame([], $this->auditRowsOf($betaBakery));
    }

    /**
     * @return array<string, array<mixed>> each found ticket's fields by key, in the order returned; fails when the
     *                                     call was refused or found none
     */
    private function foundTicketsByKey(): array
    {
        self::assertFalse($this->responseValueAt('result', 'isError'));
        $tickets = $this->responseValueAt('result', 'structuredContent', 'tickets');
        self::assertIsArray($tickets);
        self::assertNotSame([], $tickets);
        $byKey = [];
        foreach ($tickets as $ticket) {
            self::assertIsArray($ticket);
            self::assertIsString($ticket['key'] ?? null);
            $byKey[$ticket['key']] = $ticket;
        }
        ksort($byKey);

        return $byKey;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return list<string> the SQL the call sent
     */
    private function sqlOfCall(string $token, string $sessionId, array $arguments): array
    {
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        \assert($debugData instanceof DebugDataHolder);
        $debugData->reset();
        $this->callTool($token, $sessionId, FindTickets::NAME, $arguments);
        self::assertResponseStatusCodeSame(200);

        return self::sqlOf($debugData);
    }

    private function addStep(OrganizationId $organizationId, string $name, string $group, string $coreCode, int $position): string
    {
        $stepId = Uuid::v7()->toRfc4122();
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, ?, ?, ?, 'core', ?)",
            [$organizationId->toString(), $stepId, $name, $group, $coreCode, $position],
        ));

        return $stepId;
    }

    /**
     * A major bug <project>-<next number> of the project's team, reported by Mia, with a turn row waiting on $turnPerson
     * unless it is Done, as the turn projector leaves a Done ticket without one.
     */
    private function addTicket(string $projectKey, string $title, string $stepId, ?User $turnPerson, ?User $assignee, string $visibility = 'all'): void
    {
        $number = $this->nextNumbers[$projectKey] = ($this->nextNumbers[$projectKey] ?? 0) + 1;
        $ticketId = Uuid::v7()->toRfc4122();
        $organizationId = $this->acmeFoods->toString();
        $teamId = ('MOB' === $projectKey ? $this->mobile : $this->shop)->toString();
        $projectId = $this->projects[$projectKey];
        $reporterId = $this->people['Mia']->id()->toString();
        $assigneeId = $assignee?->id()->toString();
        $isDone = $stepId === $this->doneStep;
        $turnUserId = $turnPerson?->id()->toString();
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, $ticketId, $projectId, $teamId, $number, $projectKey, $title, $stepId, $reporterId, $assigneeId, $visibility, $isDone, $turnUserId): int {
            self::insertTicket($migrator, [$organizationId, $ticketId, $projectId, $teamId, $number, $projectKey.'-'.$number, $title, $stepId, $reporterId, $assigneeId, $visibility, $isDone ? '2026-11-09 23:00:00+00' : null]);
            if ($isDone) {
                return 0;
            }

            return (int) $migrator->executeStatement(
                "INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, turn_label, kind, base_score, waiting_since, visibility, reporter_id, assignee_id)
                 VALUES (?, ?, ?, ?, 'doing', ?, NULL, 'build', 100, TIMESTAMPTZ '2026-11-01 09:00:00+00', ?, ?, ?)",
                [$organizationId, $ticketId, $teamId, $projectId, $turnUserId, $visibility, $reporterId, $assigneeId],
            );
        });
    }

    private function addBetaBakeryTicketAssignedTo(User $assignee, OrganizationId $betaBakery): void
    {
        $projectId = $this->addProject(null, 'SHOP', 'Shop', $betaBakery);
        $stepId = $this->addStep($betaBakery, 'In progress', 'doing', 'progress', 0);
        $row = [$betaBakery->toString(), Uuid::v7()->toRfc4122(), $projectId, null, 2, 'SHOP-2', self::HIDDEN_TITLE, $stepId, $assignee->id()->toString(), $assignee->id()->toString(), 'all', null];
        $this->committedAs($betaBakery, static fn (Connection $migrator): int => self::insertTicket($migrator, $row));
    }

    /**
     * $row holds, in order: organization, id, project, team, number, key, title, step, reporter, assignee, visibility
     * and closed at.
     *
     * @param array{string, string, string, string|null, int, string, string, string, string, string|null, string, string|null} $row
     */
    private static function insertTicket(Connection $migrator, array $row): int
    {
        return (int) $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority,
                 priority_rank, step_id, reporter_id, assignee_id, visibility, components, check_reasons, created_at, updated_at, closed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, '', 'bug', 'major', 2, ?, ?, ?, ?, '{}', '{}', TIMESTAMPTZ '2026-08-01 09:00:00+00', TIMESTAMPTZ '2026-11-09 08:00:00+00', CAST(? AS timestamptz))",
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
     * @param array<string, mixed> $arguments
     */
    private function callTool(string $token, string $sessionId, string $toolName, array $arguments): void
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
        return ['token', $user->id()->toString(), $tokenId, ToolCallAuditor::ACTION, ToolCallAuditor::TOOL_TARGET_TYPE, null, '2026-11-10 09:00:20', ['outcome' => $outcome, 'tool' => FindTickets::NAME]];
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
