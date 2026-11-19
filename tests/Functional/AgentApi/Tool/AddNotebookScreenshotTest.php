<?php

declare(strict_types=1);

namespace App\Tests\Functional\AgentApi\Tool;

use App\AgentApi\Infrastructure\Mcp\Tool\AddNotebookScreenshot;
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
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * add_notebook_screenshot through POST /mcp (P13.T8, ADR-0046): the notebooks:write scope, Notebooks::get, the upload's
 * own target, Media's completion, NotebookVoter's EDIT and Notebooks::addScreenshot behind the gate. Lena's notebook
 * holds one picture at version 2; she has a second notebook, and Omar one of his own. Lena is a member of Acme Foods and
 * of Beta Bakery; Sara is Acme Foods' admin. The clock is frozen on 10 Nov 2026 at 09:00:20, a day an aud_event
 * partition exists for and not today.
 */
final class AddNotebookScreenshotTest extends WebTestCase
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
    private const string LENAS_NOTEBOOK = '0197a3c0-0000-7000-8000-0000000d0001';
    private const string LENAS_OTHER_NOTEBOOK = '0197a3c0-0000-7000-8000-0000000d0002';
    private const string OMARS_NOTEBOOK = '0197a3c0-0000-7000-8000-0000000d0003';
    private const string FIRST_PICTURE = '0197a3c0-0000-7000-8000-0000000e0001';
    private const string NOTEBOOK_FULL = 'A notebook holds at most 10 screenshots.';

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
        $this->addNotebook($this->acmeFoods, $this->people['Lena'], self::LENAS_NOTEBOOK, 'Coupon questions', '2026-11-05 08:00:00+00', [self::storedPicture(self::FIRST_PICTURE)], version: 2);
        $this->addNotebook($this->acmeFoods, $this->people['Lena'], self::LENAS_OTHER_NOTEBOOK, 'Delivery questions', '2026-11-04 08:00:00+00');
        $this->addNotebook($this->acmeFoods, $this->people['Omar'], self::OMARS_NOTEBOOK, 'Stock questions', '2026-11-03 08:00:00+00');
    }

    protected function tearDown(): void
    {
        $this->removeNotebooksAndAuditRows();
        $this->removeApiTokens();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function theOwnersReadyPictureGoesAtTheEndWithItsTitleSummaryAndPinsAndOneOkRow(): void
    {
        $uploadId = $this->addUpload($this->acmeFoods, $this->people['Lena'], 'notebook', self::LENAS_NOTEBOOK, 'ready');
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::NotebooksWrite]);

        $this->callTool($token, AddNotebookScreenshot::NAME, [
            'notebook' => self::LENAS_NOTEBOOK,
            'upload_id' => $uploadId,
            'title' => 'The cart',
            'summary' => 'The total goes below 0.',
            'pins' => [['x_pct' => 12.5, 'y_pct' => 40, 'note' => 'Why is this negative?']],
        ]);

        self::assertSame(['notebook_id' => self::LENAS_NOTEBOOK, 'media_id' => $uploadId, 'status' => AddNotebookScreenshot::ADDED, 'screenshot_count' => 2], $this->answer());
        [$screenshots, $version] = $this->screenshotsAndVersionOf(self::LENAS_NOTEBOOK);
        self::assertEquals([
            self::storedPicture(self::FIRST_PICTURE),
            ['media_id' => $uploadId, 'title' => 'The cart', 'summary' => 'The total goes below 0.', 'pins' => [['x_pct' => 12.5, 'y_pct' => 40.0, 'note' => 'Why is this negative?']]],
        ], $screenshots);
        self::assertSame(3, $version);
        self::assertSame([[$tokenId, AddNotebookScreenshot::AUDIT_TARGET_TYPE, self::LENAS_NOTEBOOK, AddNotebookScreenshot::NAME, 'ok']], $this->toolRowsOf($this->acmeFoods));
    }

    /**
     * The first call completes the upload; the picture is added while the worker still processes it.
     */
    #[Test]
    public function anUploadNotCompletedYetIsCompletedAndItsPictureAdded(): void
    {
        $uploadId = $this->addUpload($this->acmeFoods, $this->people['Lena'], 'notebook', self::LENAS_NOTEBOOK, null);
        [, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::NotebooksWrite]);

        $this->callTool($token, AddNotebookScreenshot::NAME, ['notebook' => self::LENAS_NOTEBOOK, 'upload_id' => $uploadId]);

        self::assertSame(2, $this->answer()['screenshot_count'] ?? null);
        self::assertSame([[$uploadId, 'notebook', self::LENAS_NOTEBOOK, '2026-11-10 09:00:20']], $this->uploadIntentsOf($this->acmeFoods));
        self::assertSame([self::FIRST_PICTURE, $uploadId], array_column($this->screenshotsAndVersionOf(self::LENAS_NOTEBOOK)[0], 'media_id'));
    }

    #[Test]
    public function aRejectedPictureIsRefusedAndTheNotebookStaysAsItWas(): void
    {
        $uploadId = $this->addUpload($this->acmeFoods, $this->people['Lena'], 'notebook', self::LENAS_NOTEBOOK, 'rejected');
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::NotebooksWrite]);
        $rowsBefore = $this->notebookRowsOf($this->acmeFoods);

        $this->callTool($token, AddNotebookScreenshot::NAME, ['notebook' => self::LENAS_NOTEBOOK, 'upload_id' => $uploadId]);

        $this->assertRefused(AddNotebookScreenshot::PICTURE_REJECTED);
        self::assertSame($rowsBefore, $this->notebookRowsOf($this->acmeFoods));
        self::assertSame([[$tokenId, AddNotebookScreenshot::AUDIT_TARGET_TYPE, self::LENAS_NOTEBOOK, AddNotebookScreenshot::NAME, 'refused']], $this->toolRowsOf($this->acmeFoods));
    }

    /**
     * The completion runs in the gate's savepoint, so the refusal leaves the upload unused for another notebook.
     */
    #[Test]
    public function anEleventhPictureIsRefusedAndTheUploadStaysUnused(): void
    {
        $fullNotebook = '0197a3c0-0000-7000-8000-0000000d0004';
        $pictures = array_map(static fn (int $number): array => self::storedPicture(Uuid::v7()->toRfc4122()), range(1, 10));
        $this->addNotebook($this->acmeFoods, $this->people['Lena'], $fullNotebook, 'Full', '2026-11-02 08:00:00+00', $pictures);
        $uploadId = $this->addUpload($this->acmeFoods, $this->people['Lena'], 'notebook', $fullNotebook, null);
        [, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::NotebooksWrite]);
        $rowsBefore = $this->notebookRowsOf($this->acmeFoods);

        $this->callTool($token, AddNotebookScreenshot::NAME, ['notebook' => $fullNotebook, 'upload_id' => $uploadId]);

        $this->assertRefused(self::NOTEBOOK_FULL);
        self::assertSame($rowsBefore, $this->notebookRowsOf($this->acmeFoods));
        self::assertSame([[$uploadId, 'notebook', $fullNotebook, null]], $this->uploadIntentsOf($this->acmeFoods));
    }

    /**
     * The call sent again, as after a lost answer: the second finds the upload completed already, so Media answers the
     * same picture and Review finds it in the notebook. The picture is there once and the version moved once.
     */
    #[Test]
    public function theSameUploadSentTwiceIsAlreadyInTheNotebookTheSecondTimeAndTheNotebookKeepsItOnce(): void
    {
        $uploadId = $this->addUpload($this->acmeFoods, $this->people['Lena'], 'notebook', self::LENAS_NOTEBOOK, 'ready');
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::NotebooksWrite]);
        $this->callTool($token, AddNotebookScreenshot::NAME, ['notebook' => self::LENAS_NOTEBOOK, 'upload_id' => $uploadId]);
        self::assertSame(['notebook_id' => self::LENAS_NOTEBOOK, 'media_id' => $uploadId, 'status' => AddNotebookScreenshot::ADDED, 'screenshot_count' => 2], $this->answer());
        $rowsAfterTheFirstCall = $this->notebookRowsOf($this->acmeFoods);

        $this->callTool($token, AddNotebookScreenshot::NAME, ['notebook' => self::LENAS_NOTEBOOK, 'upload_id' => $uploadId]);

        self::assertSame(['notebook_id' => self::LENAS_NOTEBOOK, 'media_id' => $uploadId, 'status' => AddNotebookScreenshot::ALREADY_IN_NOTEBOOK, 'screenshot_count' => 2], $this->answer());
        self::assertSame($rowsAfterTheFirstCall, $this->notebookRowsOf($this->acmeFoods));
        [$screenshots, $version] = $this->screenshotsAndVersionOf(self::LENAS_NOTEBOOK);
        self::assertSame([self::FIRST_PICTURE, $uploadId], array_column($screenshots, 'media_id'));
        self::assertSame(3, $version);
        self::assertSame(array_fill(0, 2, [$tokenId, AddNotebookScreenshot::AUDIT_TARGET_TYPE, self::LENAS_NOTEBOOK, AddNotebookScreenshot::NAME, 'ok']), $this->toolRowsOf($this->acmeFoods));
    }

    /**
     * The tenth picture sent again, as after a lost answer, is in the full notebook already: not the cap's refusal.
     */
    #[Test]
    public function theTenthPictureSentAgainIsAlreadyInTheFullNotebookAndNothingChanges(): void
    {
        $nearlyFullNotebook = '0197a3c0-0000-7000-8000-0000000d0005';
        $pictures = array_map(static fn (int $number): array => self::storedPicture(Uuid::v7()->toRfc4122()), range(1, 9));
        $this->addNotebook($this->acmeFoods, $this->people['Lena'], $nearlyFullNotebook, 'Nearly full', '2026-11-02 08:00:00+00', $pictures);
        $tenthPicture = $this->addUpload($this->acmeFoods, $this->people['Lena'], 'notebook', $nearlyFullNotebook, 'ready');
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::NotebooksWrite]);
        $this->callTool($token, AddNotebookScreenshot::NAME, ['notebook' => $nearlyFullNotebook, 'upload_id' => $tenthPicture]);
        self::assertSame(['notebook_id' => $nearlyFullNotebook, 'media_id' => $tenthPicture, 'status' => AddNotebookScreenshot::ADDED, 'screenshot_count' => 10], $this->answer());
        $rowsBefore = $this->notebookRowsOf($this->acmeFoods);

        $this->callTool($token, AddNotebookScreenshot::NAME, ['notebook' => $nearlyFullNotebook, 'upload_id' => $tenthPicture]);

        self::assertSame(['notebook_id' => $nearlyFullNotebook, 'media_id' => $tenthPicture, 'status' => AddNotebookScreenshot::ALREADY_IN_NOTEBOOK, 'screenshot_count' => 10], $this->answer());
        self::assertSame($rowsBefore, $this->notebookRowsOf($this->acmeFoods));
        self::assertSame(2, $this->screenshotsAndVersionOf($nearlyFullNotebook)[1]);
        self::assertSame(array_fill(0, 2, [$tokenId, AddNotebookScreenshot::AUDIT_TARGET_TYPE, $nearlyFullNotebook, AddNotebookScreenshot::NAME, 'ok']), $this->toolRowsOf($this->acmeFoods));
    }

    /**
     * The upload expired one second before the frozen clock and was never used.
     */
    #[Test]
    public function anUploadThatExpiredUnusedIsRefusedAndStaysUnusedAndTheNotebookAsItWas(): void
    {
        $uploadId = $this->addUpload($this->acmeFoods, $this->people['Lena'], 'notebook', self::LENAS_NOTEBOOK, null);
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE med_upload_intent SET expires_at = TIMESTAMPTZ '2026-11-10 09:00:19+00' WHERE id = ?",
            [$uploadId],
        ));
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::NotebooksWrite]);
        $rowsBefore = $this->notebookRowsOf($this->acmeFoods);

        $this->callTool($token, AddNotebookScreenshot::NAME, ['notebook' => self::LENAS_NOTEBOOK, 'upload_id' => $uploadId]);

        $this->assertRefused(AddNotebookScreenshot::UPLOAD_EXPIRED);
        self::assertSame($rowsBefore, $this->notebookRowsOf($this->acmeFoods));
        self::assertSame([[$uploadId, 'notebook', self::LENAS_NOTEBOOK, null]], $this->uploadIntentsOf($this->acmeFoods));
        self::assertSame([[$tokenId, AddNotebookScreenshot::AUDIT_TARGET_TYPE, self::LENAS_NOTEBOOK, AddNotebookScreenshot::NAME, 'refused']], $this->toolRowsOf($this->acmeFoods));
    }

    /**
     * Omar and Sara hold notebooks:write in Acme Foods, and Lena's other token is of Beta Bakery, each with an upload
     * of their own begun for Lena's notebook id: none of them finds it. An unknown or malformed id is not found too.
     */
    #[Test]
    public function anotherPersonsTheAdminsAndAnotherOrganizationsTokenAndAnUnknownIdGetNotFoundAndNothingChanges(): void
    {
        $calls = [
            [$this->acmeFoods, $this->people['Omar'], self::LENAS_NOTEBOOK],
            [$this->acmeFoods, $this->people['Sara'], self::LENAS_NOTEBOOK],
            [$this->betaBakery, $this->people['Lena'], self::LENAS_NOTEBOOK],
            [$this->acmeFoods, $this->people['Lena'], '0197a3c0-0000-7000-8000-0000000d9999'],
            [$this->acmeFoods, $this->people['Lena'], 'not-a-notebook'],
        ];
        $rowsBefore = $this->notebookRowsOf($this->acmeFoods);

        foreach ($calls as [$organizationId, $person, $notebookId]) {
            $uploadId = $this->addUpload($organizationId, $person, 'notebook', $notebookId, null);
            [$tokenId, $token] = $this->issueApiToken($person, $organizationId, scopes: [ApiTokenScope::NotebooksWrite]);

            $this->callTool($token, AddNotebookScreenshot::NAME, ['notebook' => $notebookId, 'upload_id' => $uploadId]);

            $this->assertRefused(ToolRefusal::NOT_FOUND);
            self::assertContains([$tokenId, ToolCallAuditor::TOOL_TARGET_TYPE, null, AddNotebookScreenshot::NAME, 'not_found'], $this->toolRowsOf($organizationId));
            self::assertContains([$uploadId, 'notebook', $notebookId, null], $this->uploadIntentsOf($organizationId));
        }
        self::assertSame($rowsBefore, $this->notebookRowsOf($this->acmeFoods));
    }

    /**
     * Each upload is one Lena may not attach to this notebook: Omar's for his notebook, Omar's for hers, hers for a
     * round, and hers for her other notebook.
     */
    #[Test]
    public function anUploadBegunByAnotherPersonOrForAnythingButThisNotebookIsNotFoundAndStaysUnused(): void
    {
        $uploads = [
            $this->addUpload($this->acmeFoods, $this->people['Omar'], 'notebook', self::OMARS_NOTEBOOK, 'ready'),
            $this->addUpload($this->acmeFoods, $this->people['Omar'], 'notebook', self::LENAS_NOTEBOOK, null),
            $this->addUpload($this->acmeFoods, $this->people['Lena'], 'round', Uuid::v7()->toRfc4122(), null),
            $this->addUpload($this->acmeFoods, $this->people['Lena'], 'notebook', self::LENAS_OTHER_NOTEBOOK, null),
        ];
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::NotebooksWrite]);
        $rowsBefore = $this->notebookRowsOf($this->acmeFoods);
        $intentsBefore = $this->uploadIntentsOf($this->acmeFoods);

        foreach ($uploads as $uploadId) {
            $this->callTool($token, AddNotebookScreenshot::NAME, ['notebook' => self::LENAS_NOTEBOOK, 'upload_id' => $uploadId]);

            $this->assertRefused(ToolRefusal::NOT_FOUND);
        }

        self::assertSame($rowsBefore, $this->notebookRowsOf($this->acmeFoods));
        self::assertSame($intentsBefore, $this->uploadIntentsOf($this->acmeFoods));
        self::assertSame(array_fill(0, 4, [$tokenId, ToolCallAuditor::TOOL_TARGET_TYPE, null, AddNotebookScreenshot::NAME, 'not_found']), $this->toolRowsOf($this->acmeFoods));
    }

    #[Test]
    public function aTokenWithoutNotebooksWriteGetsMissingScopeAndNothingChanges(): void
    {
        $uploadId = $this->addUpload($this->acmeFoods, $this->people['Lena'], 'notebook', self::LENAS_NOTEBOOK, null);
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::Read]);
        $rowsBefore = $this->notebookRowsOf($this->acmeFoods);

        $this->callTool($token, AddNotebookScreenshot::NAME, ['notebook' => self::LENAS_NOTEBOOK, 'upload_id' => $uploadId]);

        $this->assertRefused(\sprintf(ToolRefusal::MISSING_SCOPE, 'notebooks:write'));
        self::assertSame($rowsBefore, $this->notebookRowsOf($this->acmeFoods));
        self::assertSame([[$uploadId, 'notebook', self::LENAS_NOTEBOOK, null]], $this->uploadIntentsOf($this->acmeFoods));
        self::assertSame([[$tokenId, ToolCallAuditor::TOOL_TARGET_TYPE, null, AddNotebookScreenshot::NAME, 'missing_scope']], $this->toolRowsOf($this->acmeFoods));
    }

    /**
     * @return array{array<mixed>, mixed} the screenshots decoded, and the version
     */
    private function screenshotsAndVersionOf(string $notebookId): array
    {
        foreach ($this->notebookRowsOf($this->acmeFoods) as $row) {
            if ($notebookId === $row[0]) {
                self::assertIsString($row[3]);
                $screenshots = json_decode($row[3], true, flags: \JSON_THROW_ON_ERROR);
                self::assertIsArray($screenshots);

                return [$screenshots, $row[4]];
            }
        }
        self::fail('no notebook '.$notebookId);
    }

    /**
     * @return array{media_id: string, title: null, summary: null, pins: list<never>} a picture as rvw_notebook keeps it
     */
    private static function storedPicture(string $mediaId): array
    {
        return ['media_id' => $mediaId, 'title' => null, 'summary' => null, 'pins' => []];
    }
}
