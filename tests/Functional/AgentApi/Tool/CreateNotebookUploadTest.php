<?php

declare(strict_types=1);

namespace App\Tests\Functional\AgentApi\Tool;

use App\AgentApi\Infrastructure\Mcp\Tool\CreateNotebookUpload;
use App\AgentApi\Infrastructure\Mcp\ToolCallAuditor;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\User;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\IssuesApiTokens;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Foundry\Test\Factories;

/**
 * create_notebook_upload through POST /mcp (P13.T8, ADR-0046, ADR-0011): the notebooks:write scope, Notebooks::get,
 * NotebookVoter's EDIT and Media's Uploads::begin with the notebook target behind the gate. Lena is a member of Acme
 * Foods and of Beta Bakery; Sara is Acme Foods' admin. The clock is frozen on 10 Nov 2026 at 09:00:20, a day an
 * aud_event partition exists for and not today.
 */
final class CreateNotebookUploadTest extends WebTestCase
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
    private const string LENAS_NOTEBOOK = '0197a3c0-0000-7000-8000-0000000f0001';
    private const string SHA256 = '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08';
    private const array FILE = ['mime' => 'image/png', 'bytes' => 245_760, 'sha256' => self::SHA256];

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
        $this->addNotebook($this->acmeFoods, $this->people['Lena'], self::LENAS_NOTEBOOK, 'Coupon questions', '2026-11-05 08:00:00+00');
    }

    protected function tearDown(): void
    {
        $this->removeNotebooksAndAuditRows();
        $this->removeApiTokens();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function theOwnerBeginsAnUploadForTheNotebookAndTheNotebookStaysAsItWas(): void
    {
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::NotebooksWrite]);
        $rowsBefore = $this->notebookRowsOf($this->acmeFoods);

        $this->callTool($token, CreateNotebookUpload::NAME, ['notebook' => self::LENAS_NOTEBOOK, ...self::FILE]);

        $answer = $this->answer();
        self::assertSame(['upload_id', 'put_url', 'headers', 'expires_at'], array_keys($answer));
        self::assertSame([[$answer['upload_id'], 'notebook', self::LENAS_NOTEBOOK, null]], $this->uploadIntentsOf($this->acmeFoods));
        self::assertSame($rowsBefore, $this->notebookRowsOf($this->acmeFoods));
        self::assertSame([[$tokenId, CreateNotebookUpload::AUDIT_TARGET_TYPE, self::LENAS_NOTEBOOK, CreateNotebookUpload::NAME, 'ok']], $this->toolRowsOf($this->acmeFoods));
    }

    /**
     * Omar and Sara hold notebooks:write in Acme Foods, and Lena's other token is of Beta Bakery: none of them finds
     * Lena's notebook, and an unknown or malformed id is not found too. No upload is begun.
     */
    #[Test]
    public function anotherPersonsTheAdminsAndAnotherOrganizationsTokenAndAnUnknownIdGetNotFoundAndBeginNothing(): void
    {
        $calls = [
            [$this->acmeFoods, $this->people['Omar'], self::LENAS_NOTEBOOK],
            [$this->acmeFoods, $this->people['Sara'], self::LENAS_NOTEBOOK],
            [$this->betaBakery, $this->people['Lena'], self::LENAS_NOTEBOOK],
            [$this->acmeFoods, $this->people['Lena'], '0197a3c0-0000-7000-8000-0000000f9999'],
            [$this->acmeFoods, $this->people['Lena'], 'not-a-notebook'],
        ];
        $rowsBefore = $this->notebookRowsOf($this->acmeFoods);

        foreach ($calls as [$organizationId, $person, $notebookId]) {
            [$tokenId, $token] = $this->issueApiToken($person, $organizationId, scopes: [ApiTokenScope::NotebooksWrite]);

            $this->callTool($token, CreateNotebookUpload::NAME, ['notebook' => $notebookId, ...self::FILE]);

            $this->assertRefused(ToolRefusal::NOT_FOUND);
            self::assertContains([$tokenId, ToolCallAuditor::TOOL_TARGET_TYPE, null, CreateNotebookUpload::NAME, 'not_found'], $this->toolRowsOf($organizationId));
            self::assertSame([], $this->uploadIntentsOf($organizationId));
        }
        self::assertSame($rowsBefore, $this->notebookRowsOf($this->acmeFoods));
    }

    #[Test]
    public function aTokenWithoutNotebooksWriteGetsMissingScopeAndBeginsNothing(): void
    {
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::Read, ApiTokenScope::RoundsWrite, ApiTokenScope::ReportsWrite]);

        $this->callTool($token, CreateNotebookUpload::NAME, ['notebook' => self::LENAS_NOTEBOOK, ...self::FILE]);

        $this->assertRefused(\sprintf(ToolRefusal::MISSING_SCOPE, 'notebooks:write'));
        self::assertSame([], $this->uploadIntentsOf($this->acmeFoods));
        self::assertSame([[$tokenId, ToolCallAuditor::TOOL_TARGET_TYPE, null, CreateNotebookUpload::NAME, 'missing_scope']], $this->toolRowsOf($this->acmeFoods));
    }

    #[Test]
    public function aTypeMediaRefusesIsAPlainRefusalAndBeginsNothing(): void
    {
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::NotebooksWrite]);

        $this->callTool($token, CreateNotebookUpload::NAME, ['notebook' => self::LENAS_NOTEBOOK, ...self::FILE, 'mime' => 'image/gif']);

        self::assertTrue($this->responseValueAt('result', 'isError'));
        self::assertSame([], $this->uploadIntentsOf($this->acmeFoods));
        self::assertSame([[$tokenId, CreateNotebookUpload::AUDIT_TARGET_TYPE, self::LENAS_NOTEBOOK, CreateNotebookUpload::NAME, 'refused']], $this->toolRowsOf($this->acmeFoods));
    }
}
