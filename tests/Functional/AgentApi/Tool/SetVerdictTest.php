<?php

declare(strict_types=1);

namespace App\Tests\Functional\AgentApi\Tool;

use App\AgentApi\Infrastructure\Mcp\Tool\SetVerdict;
use App\AgentApi\Infrastructure\Mcp\ToolCallAuditor;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenHashing;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\User;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\IssuesApiTokens;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Review\SeedsTicketRounds;
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
 * set_verdict through POST /mcp end to end (P09.T4, FR-MCP-06): the api_token firewall, the gate, the voters of the
 * draft round editor and the checklist card, Review's commands and the audit row, on Acme Foods' ticket SHOP-1. Omar
 * is its assignee and owns an unshared draft and a shared one there; Mia, a member of Shop, owns another unshared
 * draft. Round 0 has one pin, whose checklist item is open. The clock is fixed on 10 Nov 2026, a day aud_event has a
 * partition for and not today.
 */
final class SetVerdictTest extends WebTestCase
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
    /** JSON-RPC's "Invalid params". */
    private const int INVALID_PARAMS = -32602;
    private const string SHARED_AT = '2026-11-05 09:00:00+00';
    private const string TITLE = 'Case B: a new delivery date leaves the total alone';
    private const string OLD_SUMMARY = 'The total before the fix.';
    private const string NEW_SUMMARY = 'The total now leaves out the old delivery fee.';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;
    private string $stepId;
    private string $ticketId;
    private string $omarsDraft;
    private string $omarsShot;
    private string $omarsSharedDraft;
    private string $omarsSharedShot;
    private string $miasDraft;
    private string $miasShot;
    private string $checkItem;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(self::NOW));
        $this->people = $this->createAcmeFoodsPeople('member');
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->stepId = Uuid::v7()->toRfc4122();
        $organizationId = $this->acmeFoods->toString();
        $stepId = $this->stepId;
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'To do', 'todo', 'todo', 'core', 1)",
            [$organizationId, $stepId],
        ));

        $this->ticketId = $this->addTicket('SHOP-1');
        $reportShot = $this->addScreenshot($this->addRound($this->ticketId, 0, 'report', 'Mia', self::SHARED_AT), $this->addMedia('ready'), 0, 'Cart', null);
        $checklist = $this->addRound($this->ticketId, 1, 'checklist', 'Mia', self::SHARED_AT);
        $this->checkItem = $this->addCheckItem($checklist, $this->addPin($reportShot, 1, '10.00', '10.00', 'The total', 'Mia'), 'open');
        $this->omarsSharedDraft = $this->addRound($this->ticketId, 2, 'draft', 'Omar', self::SHARED_AT);
        $this->omarsSharedShot = $this->addScreenshot($this->omarsSharedDraft, $this->addMedia('ready', 'Omar'), 0, null, null);
        $this->omarsDraft = $this->addRound($this->ticketId, null, 'draft', 'Omar', null);
        $this->omarsShot = $this->addScreenshot($this->omarsDraft, $this->addMedia('ready', 'Omar'), 0, self::TITLE, self::OLD_SUMMARY);
        $this->miasDraft = $this->addRound($this->ticketId, null, 'draft', 'Mia', null);
        $this->miasShot = $this->addScreenshot($this->miasDraft, $this->addMedia('ready', 'Mia'), 0, 'Mia shot', 'Mia summary');
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static fn (Connection $migrator): int|string => $migrator->executeStatement('DELETE FROM aud_event WHERE organization_id = ?', [$organizationId]));
        }
        $this->removeApiTokens();
        $this->removeSeededRounds();
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function theOwnerSetsAVerdictAndASummaryInOneCallWithOneOkRowThatHoldsNoContent(): void
    {
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);
        $before = $this->stored();

        $this->callSetVerdict($token, ['key' => 'SHOP-1', 'screenshot_id' => $this->omarsShot, 'verdict' => SetVerdict::NOT_RIGHT_YET, 'summary' => '  '.self::NEW_SUMMARY."\n"]);

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->responseValueAt('result', 'isError'));
        self::assertSame(
            ['screenshot_id' => $this->omarsShot, 'verdict' => 'not_right_yet', 'verdict_label' => 'Not right yet', 'summary_saved' => true],
            $this->responseValueAt('result', 'structuredContent'),
        );
        $expected = $before;
        foreach ($expected['rounds'] as $index => $round) {
            if ($this->omarsDraft === $round['id']) {
                // The verdict's save and the summary's, one version each.
                $expected['rounds'][$index]['version'] = 3;
            }
        }
        $expected['screenshots'][$this->omarsDraft] = [['id' => $this->omarsShot, 'position' => 0, 'verdict' => 'not_right', 'title' => self::TITLE, 'summary' => self::NEW_SUMMARY]];
        self::assertSame($expected, $this->stored(), 'only the verdict and summary of Omar\'s screenshot changed, and the title stayed');
        self::assertSame([$this->toolRow('Omar', $tokenId, 'screenshot', $this->omarsShot, 'ok')], $this->toolRowsOf($this->acmeFoods));
        $this->assertNoAuditRowHolds([self::NEW_SUMMARY, self::TITLE, $token]);
    }

    #[Test]
    public function aVerdictWithoutASummaryLeavesTheSummaryAsItWas(): void
    {
        [, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);

        $this->callSetVerdict($token, ['key' => 'SHOP-1', 'screenshot_id' => $this->omarsShot, 'verdict' => SetVerdict::LOOKS_RIGHT]);

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->responseValueAt('result', 'isError'));
        self::assertSame(
            [['id' => $this->omarsShot, 'position' => 0, 'verdict' => 'looks_right', 'title' => self::TITLE, 'summary' => self::OLD_SUMMARY]],
            $this->screenshotRowsOf($this->omarsDraft),
        );
    }

    /**
     * setDraftVerdict saves the verdict, then editDraftScreenshot refuses the summary: only the gate's savepoint can
     * take the saved verdict back, in a request whose transaction commits.
     */
    #[Test]
    public function aSummaryOverSixHundredCharactersIsInvalidAndTheVerdictSavedBeforeItIsRolledBack(): void
    {
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);
        $before = $this->stored();

        $this->callSetVerdict($token, ['key' => 'SHOP-1', 'screenshot_id' => $this->omarsShot, 'verdict' => SetVerdict::LOOKS_RIGHT, 'summary' => str_repeat('é', 601)]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['code' => self::INVALID_PARAMS, 'message' => \sprintf(ToolRefusal::INVALID_INPUT, 'A picture summary holds at most 600 characters.')],
            $this->responseValueAt('error'),
        );
        self::assertSame($before, $this->stored());
        self::assertSame([$this->toolRow('Omar', $tokenId, 'screenshot', $this->omarsShot, 'invalid_input')], $this->toolRowsOf($this->acmeFoods));
    }

    #[Test]
    public function aMixOfBothShapesIsInvalidInputAndChangesNothing(): void
    {
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);
        $before = $this->stored();

        $this->callSetVerdict($token, ['key' => 'SHOP-1', 'screenshot_id' => $this->omarsShot, 'verdict' => SetVerdict::LOOKS_RIGHT, 'check_item_id' => $this->checkItem, 'state' => SetVerdict::FIXED]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::INVALID_PARAMS, $this->responseValueAt('error', 'code'));
        self::assertSame($before, $this->stored());
        self::assertSame([$this->toolRow('Omar', $tokenId, ToolCallAuditor::TOOL_TARGET_TYPE, null, 'invalid_input')], $this->toolRowsOf($this->acmeFoods));
    }

    #[Test]
    public function anotherPersonsDraftIsNotFoundAndNothingIsWritten(): void
    {
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);
        $before = $this->stored();

        $this->callSetVerdict($token, ['key' => 'SHOP-1', 'screenshot_id' => $this->miasShot, 'verdict' => SetVerdict::LOOKS_RIGHT, 'summary' => self::NEW_SUMMARY]);

        $this->assertRefused(ToolRefusal::NOT_FOUND);
        self::assertSame($before, $this->stored());
        self::assertSame([$this->toolRow('Omar', $tokenId, ToolCallAuditor::TOOL_TARGET_TYPE, null, 'not_found')], $this->toolRowsOf($this->acmeFoods));
    }

    #[Test]
    public function theOwnersSharedDraftIsReadOnlyAndNothingIsWritten(): void
    {
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);
        $before = $this->stored();

        $this->callSetVerdict($token, ['key' => 'SHOP-1', 'screenshot_id' => $this->omarsSharedShot, 'verdict' => SetVerdict::LOOKS_RIGHT, 'summary' => self::NEW_SUMMARY]);

        $this->assertRefused(ToolRefusal::READ_ONLY);
        self::assertSame($before, $this->stored());
        self::assertSame([$this->toolRow('Omar', $tokenId, 'screenshot', $this->omarsSharedShot, 'read_only')], $this->toolRowsOf($this->acmeFoods));
    }

    #[Test]
    public function aTokenWithoutRoundsWriteGetsMissingScopeAndNothingIsWritten(): void
    {
        [$tokenId, $token] = $this->issueReadOnlyToken($this->people['Omar'], $this->acmeFoods);
        $before = $this->stored();

        $this->callSetVerdict($token, ['key' => 'SHOP-1', 'screenshot_id' => $this->omarsShot, 'verdict' => SetVerdict::LOOKS_RIGHT, 'summary' => self::NEW_SUMMARY]);

        $this->assertRefused(\sprintf(ToolRefusal::MISSING_SCOPE, 'rounds:write'));
        self::assertSame($before, $this->stored());
        self::assertSame([$this->toolRow('Omar', $tokenId, ToolCallAuditor::TOOL_TARGET_TYPE, null, 'missing_scope')], $this->toolRowsOf($this->acmeFoods));
    }

    /**
     * Omar works in Beta Bakery too; under its token, Acme Foods' ticket and Omar's own draft there are another
     * organization's.
     */
    #[Test]
    public function aTicketOfAnotherOrganizationIsNotFoundAndNothingIsWritten(): void
    {
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($this->people['Omar'], $betaBakery);
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $betaBakery);
        $before = $this->stored();

        $this->callSetVerdict($token, ['key' => 'SHOP-1', 'screenshot_id' => $this->omarsShot, 'verdict' => SetVerdict::LOOKS_RIGHT]);
        $this->assertRefused(ToolRefusal::NOT_FOUND);
        $this->callSetVerdict($token, ['key' => 'SHOP-1', 'check_item_id' => $this->checkItem, 'state' => SetVerdict::FIXED]);
        $this->assertRefused(ToolRefusal::NOT_FOUND);

        self::assertSame($before, $this->stored());
        self::assertSame([], $this->toolRowsOf($this->acmeFoods));
        $notFoundRow = $this->toolRow('Omar', $tokenId, ToolCallAuditor::TOOL_TARGET_TYPE, null, 'not_found');
        self::assertSame([$notFoundRow, $notFoundRow], $this->toolRowsOf($betaBakery));
    }

    #[Test]
    public function theAssigneeMarksAChecklistItemFixed(): void
    {
        [$tokenId, $token] = $this->issueApiToken($this->people['Omar'], $this->acmeFoods);
        $before = $this->stored();

        $this->callSetVerdict($token, ['key' => 'SHOP-1', 'check_item_id' => strtoupper($this->checkItem), 'state' => SetVerdict::FIXED]);

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->responseValueAt('result', 'isError'));
        self::assertSame(['check_item_id' => $this->checkItem, 'pin_number' => 1, 'state' => 'fixed'], $this->responseValueAt('result', 'structuredContent'));
        self::assertSame(['state' => 'fixed', 'changed_by' => $this->people['Omar']->id()->toString()], $this->checkItemRow());
        self::assertSame($before['screenshots'], $this->stored()['screenshots']);
        self::assertSame([$this->toolRow('Omar', $tokenId, 'check_item', $this->checkItem, 'ok')], $this->toolRowsOf($this->acmeFoods));
    }

    /**
     * Mia may see and edit SHOP-1 but is not its assignee: the voter denies her, which the gate answers as not found,
     * where the web's checklist card answers 403 with "Only the ticket's assignee can mark a pin fixed.".
     */
    #[Test]
    public function someoneOtherThanTheAssigneeIsRefusedAndTheItemStaysOpen(): void
    {
        [$tokenId, $token] = $this->issueApiToken($this->people['Mia'], $this->acmeFoods);

        $this->callSetVerdict($token, ['key' => 'SHOP-1', 'check_item_id' => $this->checkItem, 'state' => SetVerdict::FIXED]);

        $this->assertRefused(ToolRefusal::NOT_FOUND);
        self::assertSame(['state' => 'open', 'changed_by' => null], $this->checkItemRow());
        self::assertSame([$this->toolRow('Mia', $tokenId, 'check_item', $this->checkItem, 'not_found')], $this->toolRowsOf($this->acmeFoods));
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
     * Starts and confirms a session as Claude Code does, then calls set_verdict in it.
     *
     * @param array<string, mixed> $arguments
     */
    private function callSetVerdict(string $token, array $arguments): void
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

        $this->postJsonRpc($token, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => SetVerdict::NAME, 'arguments' => $arguments]], $sessionId);
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
     * Every round of SHOP-1 with its screenshots, and the checklist item, as stored.
     *
     * @return array{rounds: list<array<string, mixed>>, screenshots: array<string, list<array<string, mixed>>>, check_item: array<string, mixed>}
     */
    private function stored(): array
    {
        $rounds = $this->roundRowsOf($this->ticketId);
        self::assertCount(5, $rounds);
        $screenshots = [];
        foreach ([$this->omarsDraft, $this->omarsSharedDraft, $this->miasDraft] as $roundId) {
            $screenshots[$roundId] = $this->screenshotRowsOf($roundId);
            self::assertCount(1, $screenshots[$roundId]);
        }

        return ['rounds' => $rounds, 'screenshots' => $screenshots, 'check_item' => $this->checkItemRow()];
    }

    /**
     * @return array<string, mixed>
     */
    private function checkItemRow(): array
    {
        $row = [];
        $itemId = $this->checkItem;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($itemId, &$row): int {
            $row = $migrator->fetchAssociative('SELECT state, changed_by FROM rvw_check_item WHERE id = ?', [$itemId]);

            return 0;
        });
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return array{string, string, string, string, string, string|null, string, array<string, string>}
     */
    private function toolRow(string $person, string $tokenId, string $targetType, ?string $targetId, string $outcome): array
    {
        return ['token', $this->people[$person]->id()->toString(), $tokenId, ToolCallAuditor::ACTION, $targetType, $targetId, '2026-11-10 09:00:20', ['outcome' => $outcome, 'tool' => SetVerdict::NAME]];
    }

    /**
     * The organization's mcp.tool_called rows; marking an item fixed may add rows of its own, which are not the tool's.
     *
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
