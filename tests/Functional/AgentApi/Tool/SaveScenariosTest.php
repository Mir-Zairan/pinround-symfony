<?php

declare(strict_types=1);

namespace App\Tests\Functional\AgentApi\Tool;

use App\AgentApi\Infrastructure\Mcp\Tool\AddNotebookScreenshot;
use App\AgentApi\Infrastructure\Mcp\Tool\CreateNotebook;
use App\AgentApi\Infrastructure\Mcp\Tool\CreateNotebookUpload;
use App\AgentApi\Infrastructure\Mcp\Tool\GetNotebook;
use App\AgentApi\Infrastructure\Mcp\Tool\ListMyNotebooks;
use App\AgentApi\Infrastructure\Mcp\Tool\SaveScenarios;
use App\AgentApi\Infrastructure\Mcp\ToolCallAuditor;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\User;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\IssuesApiTokens;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Foundry\Test\Factories;

/**
 * save_scenarios through POST /mcp (P13.T7, ADR-0046): the notebooks:write scope, Notebooks::get, NotebookVoter's EDIT
 * and Notebooks::replaceScenarios behind the gate. Lena's notebook starts at version 3 with two scenarios. Lena is a
 * member of Acme Foods and of Beta Bakery; Sara is Acme Foods' admin. The clock is frozen on 10 Nov 2026 at 09:00:20, a
 * day an aud_event partition exists for and not today.
 */
final class SaveScenariosTest extends WebTestCase
{
    use CallsNotebookTools;
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesApiTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string NOW = '2026-11-10 09:00:20 UTC';
    private const string LENAS_NOTEBOOK = '0197a3c0-0000-7000-8000-0000000b0001';
    private const string USED_AT = '2026-11-05 08:00:00.500000';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private OrganizationId $betaBakery;

    /** @var array{scenarios: mixed, version: mixed, updated_at: mixed, last_used_at: mixed} */
    private array $rowBefore;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(self::NOW));
        $this->people = $this->createAcmeFoodsPeople(null);
        $this->betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($this->people['Lena'], $this->betaBakery);
        $this->addNotebook($this->acmeFoods, $this->people['Lena'], self::LENAS_NOTEBOOK, 'Coupon questions', self::USED_AT.'+00', [], [self::scenario('Old first'), self::scenario('Old second')], 3);
        $this->rowBefore = $this->notebookRowOf($this->acmeFoods, self::LENAS_NOTEBOOK);
        self::assertSame(3, $this->rowBefore['version']);
        self::assertIsArray($this->rowBefore['scenarios']);
        self::assertCount(2, $this->rowBefore['scenarios']);
    }

    protected function tearDown(): void
    {
        $this->removeNotebooksAndAuditRows();
        $this->removeApiTokens();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function aValidSetReplacesAllTheScenariosAndGetNotebookReturnsThem(): void
    {
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::Read, ApiTokenScope::NotebooksWrite]);
        $newScenario = [
            'title' => 'Coupon over the total',
            'why' => "CartTotal::apply()\r\nlets it go below 0 € \u{2028}",
            'setup' => '',
            'start_url' => 'http://localhost:8000/cart',
            'steps' => [
                ['type' => 'type', 'say' => 'Type the code.', 'target' => ['label' => 'Coupon'], 'text' => ''],
                ['type' => 'click', 'say' => 'Apply it.', 'target' => ['role' => 'button', 'name' => 'Apply']],
                ['type' => 'pause', 'say' => 'Look at the total.'],
            ],
        ];

        $this->callTool($token, SaveScenarios::NAME, ['notebook' => self::LENAS_NOTEBOOK, 'scenarios' => [$newScenario]]);

        self::assertSame(['notebook_id' => self::LENAS_NOTEBOOK, 'status' => SaveScenarios::SAVED, 'scenario_count' => 1], $this->answer());
        $rowAfter = $this->notebookRowOf($this->acmeFoods, self::LENAS_NOTEBOOK);
        // jsonb keeps an object's keys in its own order, so the scenarios are compared with their keys as a set.
        self::assertEquals([$newScenario], $rowAfter['scenarios']);
        self::assertSame([4, '2026-11-10 09:00:20.000000', '2026-11-10 09:00:20.000000'], [$rowAfter['version'], $rowAfter['updated_at'], $rowAfter['last_used_at']], 'A save is a change and a use.');
        self::assertSame([[$tokenId, SaveScenarios::AUDIT_TARGET_TYPE, self::LENAS_NOTEBOOK, SaveScenarios::NAME, 'ok']], $this->toolRowsOf($this->acmeFoods));

        $this->callTool($token, GetNotebook::NAME, ['notebook' => self::LENAS_NOTEBOOK]);

        $notebook = $this->answer();
        self::assertSame([[
            'title' => '<untrusted_text>Coupon over the total</untrusted_text>',
            'why' => "<untrusted_text>CartTotal::apply()\r\nlets it go below 0 € \u{2028}</untrusted_text>",
            'setup' => '<untrusted_text></untrusted_text>',
            'start_url' => '<untrusted_text>http://localhost:8000/cart</untrusted_text>',
            'steps' => [
                ['type' => 'type', 'say' => '<untrusted_text>Type the code.</untrusted_text>', 'target' => ['label' => '<untrusted_text>Coupon</untrusted_text>'], 'text' => '<untrusted_text></untrusted_text>'],
                ['type' => 'click', 'say' => '<untrusted_text>Apply it.</untrusted_text>', 'target' => ['role' => '<untrusted_text>button</untrusted_text>', 'name' => '<untrusted_text>Apply</untrusted_text>']],
                ['type' => 'pause', 'say' => '<untrusted_text>Look at the total.</untrusted_text>'],
            ],
        ]], $notebook['scenarios']);
        self::assertSame(4, $notebook['version']);
    }

    /**
     * The SDK decodes the arguments as PHP arrays, so an empty object arrives as the empty list does; both remove the
     * scenarios, as the tool's description says.
     *
     * @return iterable<string, array{mixed}>
     */
    public static function emptySets(): iterable
    {
        yield 'an empty list' => [[]];
        yield 'an empty object' => [new \stdClass()];
    }

    #[Test]
    #[DataProvider('emptySets')]
    public function anEmptySetRemovesEveryScenario(mixed $scenarios): void
    {
        [, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::NotebooksWrite]);

        $this->callTool($token, SaveScenarios::NAME, ['notebook' => self::LENAS_NOTEBOOK, 'scenarios' => $scenarios]);

        self::assertSame(['notebook_id' => self::LENAS_NOTEBOOK, 'status' => SaveScenarios::SAVED, 'scenario_count' => 0], $this->answer());
        $rowAfter = $this->notebookRowOf($this->acmeFoods, self::LENAS_NOTEBOOK);
        self::assertSame([[], 4], [$rowAfter['scenarios'], $rowAfter['version']]);
    }

    /**
     * Each set is wrong in one place only, so the message names the rule that place breaks.
     *
     * @return iterable<string, array{list<mixed>, string}>
     */
    public static function setsTheRulesRefuse(): iterable
    {
        $unknownStep = self::scenario('New');
        $unknownStep['steps'][] = ['type' => 'hover', 'say' => 'Hover the total.', 'target' => ['text' => 'Total']];
        yield 'an unknown step type' => [[self::scenario('New'), $unknownStep], 'Scenario 2, step 2: type must be one of go_to, click, type, select, wait_for, expect_text, expect_visible, pause, capture.'];

        $cssTarget = self::scenario('New');
        $cssTarget['steps'][] = ['type' => 'click', 'say' => 'Pay.', 'target' => ['css' => '#pay > button']];
        yield 'a CSS target' => [[$cssTarget], 'Scenario 1, step 2: find the element by role and name, by visible text or by label, never by a CSS or XPath string.'];

        yield '21 scenarios' => [array_map(static fn (int $number): array => self::scenario('New '.$number), range(1, 21)), 'A notebook holds at most 20 scenarios.'];

        $otherHost = self::scenario('New');
        $otherHost['steps'][] = ['type' => 'go_to', 'say' => 'Leave.', 'url' => 'https://elsewhere.test/'];
        yield 'a go_to to another host' => [[$otherHost], 'Scenario 1, step 2: go_to must stay on the scheme, host and port of start_url.'];
    }

    /**
     * @param list<mixed> $scenarios
     */
    #[Test]
    #[DataProvider('setsTheRulesRefuse')]
    public function aSetThatBreaksARuleIsRefusedWithItsMessageAndTheOldScenariosStay(array $scenarios, string $message): void
    {
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::NotebooksWrite]);

        $this->callTool($token, SaveScenarios::NAME, ['notebook' => self::LENAS_NOTEBOOK, 'scenarios' => $scenarios]);

        $this->assertRefused($message);
        self::assertSame($this->rowBefore, $this->notebookRowOf($this->acmeFoods, self::LENAS_NOTEBOOK));
        self::assertSame([[$tokenId, SaveScenarios::AUDIT_TARGET_TYPE, self::LENAS_NOTEBOOK, SaveScenarios::NAME, 'refused']], $this->toolRowsOf($this->acmeFoods));
    }

    /**
     * A token made for Claude Code starts without notebooks:write (ADR-0046).
     */
    #[Test]
    public function aTokenWithoutNotebooksWriteGetsMissingScopeAndNothingChanges(): void
    {
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods);

        $this->callTool($token, SaveScenarios::NAME, ['notebook' => self::LENAS_NOTEBOOK, 'scenarios' => []]);

        $this->assertRefused(\sprintf(ToolRefusal::MISSING_SCOPE, 'notebooks:write'));
        self::assertSame($this->rowBefore, $this->notebookRowOf($this->acmeFoods, self::LENAS_NOTEBOOK));
        self::assertSame([[$tokenId, ToolCallAuditor::TOOL_TARGET_TYPE, null, SaveScenarios::NAME, 'missing_scope']], $this->toolRowsOf($this->acmeFoods));
    }

    /**
     * Omar and Sara hold notebooks:write in Acme Foods, and Lena's other token is of Beta Bakery: none of them finds
     * Lena's notebook, and an unknown or malformed id is not found too.
     */
    #[Test]
    public function anotherPersonsTheAdminsAndAnotherOrganizationsTokenAndAnUnknownIdGetNotFoundAndNothingChanges(): void
    {
        $calls = [
            [$this->acmeFoods, $this->people['Omar'], self::LENAS_NOTEBOOK],
            [$this->acmeFoods, $this->people['Sara'], self::LENAS_NOTEBOOK],
            [$this->betaBakery, $this->people['Lena'], self::LENAS_NOTEBOOK],
            [$this->acmeFoods, $this->people['Lena'], '0197a3c0-0000-7000-8000-0000000b9999'],
            [$this->acmeFoods, $this->people['Lena'], 'not-a-notebook'],
        ];

        foreach ($calls as [$organizationId, $person, $notebookId]) {
            [$tokenId, $token] = $this->issueApiToken($person, $organizationId, scopes: [ApiTokenScope::NotebooksWrite]);

            $this->callTool($token, SaveScenarios::NAME, ['notebook' => $notebookId, 'scenarios' => []]);

            $this->assertRefused(ToolRefusal::NOT_FOUND);
            self::assertContains([$tokenId, ToolCallAuditor::TOOL_TARGET_TYPE, null, SaveScenarios::NAME, 'not_found'], $this->toolRowsOf($organizationId));
        }
        self::assertSame($this->rowBefore, $this->notebookRowOf($this->acmeFoods, self::LENAS_NOTEBOOK));
    }

    /**
     * Notebooks never make a ticket (ADR-0046): making one, uploading and adding a picture, reading, listing and
     * saving leave tkt_ticket as it was.
     */
    #[Test]
    public function noNotebookToolMakesATicket(): void
    {
        [, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::Read, ApiTokenScope::NotebooksWrite]);
        $uploadId = $this->addUpload($this->acmeFoods, $this->people['Lena'], 'notebook', self::LENAS_NOTEBOOK, 'ready');
        $ticketsBefore = $this->ticketCount();

        $this->callTool($token, CreateNotebook::NAME, ['title' => 'Delivery questions']);
        $this->answer();
        $this->callTool($token, CreateNotebookUpload::NAME, ['notebook' => self::LENAS_NOTEBOOK, 'mime' => 'image/png', 'bytes' => 2048, 'sha256' => str_repeat('a', 64)]);
        $this->answer();
        $this->callTool($token, AddNotebookScreenshot::NAME, ['notebook' => self::LENAS_NOTEBOOK, 'upload_id' => $uploadId]);
        $this->answer();
        $this->callTool($token, ListMyNotebooks::NAME, []);
        $this->answer();
        $this->callTool($token, GetNotebook::NAME, ['notebook' => self::LENAS_NOTEBOOK]);
        $this->answer();
        $this->callTool($token, SaveScenarios::NAME, ['notebook' => self::LENAS_NOTEBOOK, 'scenarios' => [self::scenario('New')]]);
        $this->answer();

        self::assertSame($ticketsBefore, $this->ticketCount());
    }

    private function ticketCount(): int
    {
        $count = null;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use (&$count): int {
            $count = $migrator->fetchOne('SELECT count(*) FROM tkt_ticket');

            return 0;
        });
        self::assertIsInt($count);

        return $count;
    }

    /**
     * @return array{title: string, why: string, setup: string, start_url: string, steps: list<array<string, mixed>>} a
     *                                                                                                                scenario in scenario.v1.json's format
     */
    private static function scenario(string $title): array
    {
        return [
            'title' => $title,
            'why' => 'OrderLine::warn() runs when stock is 0.',
            'setup' => 'One SHOP product with no stock.',
            'start_url' => 'http://localhost:8000/orders',
            'steps' => [['type' => 'go_to', 'say' => 'Open the order.', 'url' => 'http://localhost:8000/orders/7']],
        ];
    }
}
