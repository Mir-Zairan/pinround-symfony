<?php

declare(strict_types=1);

namespace App\Tests\Functional\AgentApi\Tool;

use App\AgentApi\Infrastructure\Mcp\Tool\CreateNotebookUpload;
use App\AgentApi\Infrastructure\Mcp\ToolCallAuditor;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\User;
use App\Media\Api\UploadRefused;
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
    /** 15 MB, the largest file Media lets an upload begin for. */
    private const int MAX_BYTES = 15 * 1024 * 1024;
    /** The begins of media_upload_begin_per_user in its window. */
    private const int BEGIN_LIMIT = 60;

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

    /**
     * The fields that differ from a PNG screenshot of 240 KB, and Media's plain message for them.
     *
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function filesMediaRefuses(): iterable
    {
        yield 'a GIF' => [['mime' => 'image/gif'], 'This file is not a PNG, JPEG or WebP screenshot.'];
        yield 'a file one byte over 15 MB' => [['bytes' => self::MAX_BYTES + 1], 'This file is empty or bigger than 15 MB. Pick a screenshot of up to 15 MB.'];
        yield 'an empty file' => [['bytes' => 0], 'This file is empty or bigger than 15 MB. Pick a screenshot of up to 15 MB.'];
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[Test]
    #[DataProvider('filesMediaRefuses')]
    public function aTypeOrSizeMediaRefusesIsRefusedWithItsMessageAndBeginsNothing(array $fields, string $message): void
    {
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::NotebooksWrite]);

        $this->callTool($token, CreateNotebookUpload::NAME, ['notebook' => self::LENAS_NOTEBOOK, ...self::FILE, ...$fields]);

        $this->assertRefused($message);
        self::assertSame([], $this->uploadIntentsOf($this->acmeFoods));
        self::assertSame([[$tokenId, CreateNotebookUpload::AUDIT_TARGET_TYPE, self::LENAS_NOTEBOOK, CreateNotebookUpload::NAME, 'refused']], $this->toolRowsOf($this->acmeFoods));
    }

    #[Test]
    public function aFileOfExactly15MbBeginsAnUpload(): void
    {
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::NotebooksWrite]);

        $this->callTool($token, CreateNotebookUpload::NAME, ['notebook' => self::LENAS_NOTEBOOK, ...self::FILE, 'bytes' => self::MAX_BYTES]);

        $answer = $this->answer();
        self::assertSame([[$answer['upload_id'] ?? null, 'notebook', self::LENAS_NOTEBOOK, null]], $this->uploadIntentsOf($this->acmeFoods));
        self::assertSame([[$tokenId, CreateNotebookUpload::AUDIT_TARGET_TYPE, self::LENAS_NOTEBOOK, CreateNotebookUpload::NAME, 'ok']], $this->toolRowsOf($this->acmeFoods));
    }

    /**
     * Lena used up her begins of this window before the call; the limiter counts on the state Valkey by user id, and
     * each test has new users.
     */
    #[Test]
    public function aBeginPastTheCallersLimitIsRefusedWithMediasMessageAndBeginsNothing(): void
    {
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::NotebooksWrite]);
        $beginsPerUser = self::getContainer()->get('limiter.media_upload_begin_per_user');
        self::assertTrue($beginsPerUser->create($this->people['Lena']->id()->toString())->consume(self::BEGIN_LIMIT)->isAccepted());

        $this->callTool($token, CreateNotebookUpload::NAME, ['notebook' => self::LENAS_NOTEBOOK, ...self::FILE]);

        $this->assertRefused(UploadRefused::ADDING_TOO_FAST);
        self::assertSame([], $this->uploadIntentsOf($this->acmeFoods));
        self::assertSame([[$tokenId, CreateNotebookUpload::AUDIT_TARGET_TYPE, self::LENAS_NOTEBOOK, CreateNotebookUpload::NAME, 'refused']], $this->toolRowsOf($this->acmeFoods));
    }
}
