<?php

declare(strict_types=1);

namespace App\Tests\Functional\AgentApi\Tool;

use App\AgentApi\Infrastructure\Mcp\Tool\CreateNotebook;
use App\AgentApi\Infrastructure\Mcp\ToolCallAuditor;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\User;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\IssuesApiTokens;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Foundry\Test\Factories;

/**
 * create_notebook through POST /mcp (P13.T8, ADR-0046): the notebooks:write scope, NotebookVoter's EDIT on the notebook
 * to be, and Notebooks::create behind the gate. Lena is a member of Acme Foods and of Beta Bakery, where Omar already
 * has a notebook the new one must not touch. The clock is frozen on 10 Nov 2026 at 09:00:20, a day an aud_event
 * partition exists for and not today.
 */
final class CreateNotebookTest extends WebTestCase
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
    private const string OMARS_NOTEBOOK = '0197a3c0-0000-7000-8000-0000000c0001';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private OrganizationId $betaBakery;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(self::NOW));
        $this->people = $this->createAcmeFoodsPeople(null);
        $this->betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($this->people['Lena'], $this->betaBakery);
        $this->addNotebook($this->acmeFoods, $this->people['Omar'], self::OMARS_NOTEBOOK, 'Omar\'s questions', '2026-11-05 08:00:00+00');
    }

    protected function tearDown(): void
    {
        $this->removeNotebooksAndAuditRows();
        $this->removeApiTokens();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function theCallerGetsANewEmptyNotebookOfTheirOwnWithTheTitleTrimmedAndOneOkRowNamingThem(): void
    {
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::NotebooksWrite]);
        $omarsRowBefore = $this->notebookRowsOf($this->acmeFoods);

        $this->callTool($token, CreateNotebook::NAME, ['title' => '  Coupon questions ']);

        $answer = $this->answer();
        self::assertSame(['notebook_id'], array_keys($answer));
        $notebookId = $answer['notebook_id'];
        self::assertIsString($notebookId);
        $lena = $this->people['Lena']->id()->toString();
        $rows = $this->notebookRowsOf($this->acmeFoods);
        self::assertCount(2, $rows);
        self::assertContains([$notebookId, $lena, 'Coupon questions', '[]', 1], $rows);
        self::assertContains($omarsRowBefore[0], $rows, 'Omar\'s notebook changed');
        self::assertSame([[$tokenId, CreateNotebook::AUDIT_TARGET_TYPE, $lena, CreateNotebook::NAME, 'ok']], $this->toolRowsOf($this->acmeFoods));
        self::assertSame([], $this->notebookRowsOf($this->betaBakery));
    }

    /**
     * The tenant is the token's organization, so Lena's Beta Bakery token makes the notebook there and Acme Foods gets
     * none.
     */
    #[Test]
    public function aTokenOfAnotherOrganizationMakesTheNotebookThereOnly(): void
    {
        [, $token] = $this->issueApiToken($this->people['Lena'], $this->betaBakery, scopes: [ApiTokenScope::NotebooksWrite]);
        $acmeRowsBefore = $this->notebookRowsOf($this->acmeFoods);

        $this->callTool($token, CreateNotebook::NAME, ['title' => 'Bakery questions']);

        $notebookId = $this->answer()['notebook_id'] ?? null;
        self::assertSame([[$notebookId, $this->people['Lena']->id()->toString(), 'Bakery questions', '[]', 1]], $this->notebookRowsOf($this->betaBakery));
        self::assertSame($acmeRowsBefore, $this->notebookRowsOf($this->acmeFoods));
    }

    /**
     * A token made for Claude Code starts without notebooks:write (ADR-0046).
     */
    #[Test]
    public function aTokenWithoutNotebooksWriteGetsMissingScopeAndMakesNothing(): void
    {
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::Read]);
        $rowsBefore = $this->notebookRowsOf($this->acmeFoods);
        self::assertCount(1, $rowsBefore);

        $this->callTool($token, CreateNotebook::NAME, ['title' => 'Coupon questions']);

        $this->assertRefused(\sprintf(ToolRefusal::MISSING_SCOPE, 'notebooks:write'));
        self::assertSame($rowsBefore, $this->notebookRowsOf($this->acmeFoods));
        self::assertSame([[$tokenId, ToolCallAuditor::TOOL_TARGET_TYPE, null, CreateNotebook::NAME, 'missing_scope']], $this->toolRowsOf($this->acmeFoods));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function titlesTheRulesRefuse(): iterable
    {
        yield 'a blank title' => ['   ', 'Give the notebook a title.'];
        yield 'a title of 121 characters' => [str_repeat('é', 121), 'Keep the notebook title to 120 characters or fewer.'];
        yield 'a title with an escape character' => ["Coupon\e[31m questions", 'This text has a character we cannot save. Remove it and try again.'];
    }

    #[Test]
    #[DataProvider('titlesTheRulesRefuse')]
    public function aTitleThatBreaksARuleIsRefusedWithItsMessageAndMakesNothing(string $title, string $message): void
    {
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::NotebooksWrite]);
        $rowsBefore = $this->notebookRowsOf($this->acmeFoods);

        $this->callTool($token, CreateNotebook::NAME, ['title' => $title]);

        $this->assertRefused($message);
        self::assertSame($rowsBefore, $this->notebookRowsOf($this->acmeFoods));
        self::assertSame([[$tokenId, CreateNotebook::AUDIT_TARGET_TYPE, $this->people['Lena']->id()->toString(), CreateNotebook::NAME, 'refused']], $this->toolRowsOf($this->acmeFoods));
    }
}
