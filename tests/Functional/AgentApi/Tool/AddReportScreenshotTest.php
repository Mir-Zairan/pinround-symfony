<?php

declare(strict_types=1);

namespace App\Tests\Functional\AgentApi\Tool;

use App\AgentApi\Infrastructure\Mcp\Tool\AddReportScreenshot;
use App\AgentApi\Infrastructure\Mcp\ToolCallAuditor;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\User;
use App\Review\Api\ReportDraftRefused;
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
 * add_report_screenshot through POST /mcp (P13.T5, ADR-0039): the reports:write scope, ReportDrafts::ofOwner, the
 * upload's own new_report target, Media's completion, ReportDraftVoter's EDIT and ReportDrafts::addScreenshot behind
 * the gate. Lena's SHOP draft holds one picture at version 2; she has a MOBILE draft too, and Omar a SHOP draft of his
 * own. Shop owns SHOP and Mobile owns MOBILE. Lena is a member of Acme Foods and of Beta Bakery; Sara is Acme Foods'
 * admin. The clock is frozen on 10 Nov 2026 at 09:00:20, a day an aud_event partition exists for and not today.
 */
final class AddReportScreenshotTest extends WebTestCase
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
    private const string LENAS_DRAFT = '0197a3c0-0000-7000-8000-0000000f0001';
    private const string LENAS_MOBILE_DRAFT = '0197a3c0-0000-7000-8000-0000000f0002';
    private const string OMARS_DRAFT = '0197a3c0-0000-7000-8000-0000000f0003';
    private const string FIRST_PICTURE = '0197a3c0-0000-7000-8000-0000000e0001';

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
        $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->addProject($this->mobile, 'MOBILE', 'Mobile');
        $this->betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($this->people['Lena'], $this->betaBakery);
        $this->addReportDraft($this->people['Lena'], self::LENAS_DRAFT, 'SHOP', [self::storedPicture(self::FIRST_PICTURE)], version: 2);
        $this->addReportDraft($this->people['Lena'], self::LENAS_MOBILE_DRAFT, 'MOBILE');
        $this->addReportDraft($this->people['Omar'], self::OMARS_DRAFT, 'SHOP');
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static fn (Connection $migrator): int|string => $migrator->executeStatement('DELETE FROM rvw_report_draft WHERE organization_id = ?', [$organizationId]));
        }
        $this->removeNotebooksAndAuditRows();
        $this->removeApiTokens();
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function theOwnersPictureGoesAtTheEndWithItsTitleSummaryAndPinsAndNoTicketIsMade(): void
    {
        $uploadId = $this->addUpload($this->acmeFoods, $this->people['Lena'], 'new_report', 'SHOP', 'ready');
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::ReportsWrite]);
        $otherDraftsBefore = $this->draftRowsExcept(self::LENAS_DRAFT);

        $this->callTool($token, AddReportScreenshot::NAME, [
            'report' => self::LENAS_DRAFT,
            'upload_id' => $uploadId,
            'title' => 'The cart',
            'summary' => 'The total goes below 0.',
            'pins' => [['x_pct' => 12.5, 'y_pct' => 40, 'note' => 'Why is this negative?']],
        ]);

        self::assertSame(['draft_id' => self::LENAS_DRAFT, 'media_id' => $uploadId, 'status' => AddReportScreenshot::ADDED, 'screenshot_count' => 2], $this->answer());
        [$screenshots, $version, $updatedAt] = $this->screenshotsVersionAndUpdatedAtOf(self::LENAS_DRAFT);
        self::assertEquals([
            self::storedPicture(self::FIRST_PICTURE),
            ['media_id' => $uploadId, 'title' => 'The cart', 'summary' => 'The total goes below 0.', 'pins' => [['x_pct' => 12.5, 'y_pct' => 40.0, 'note' => 'Why is this negative?']]],
        ], $screenshots);
        self::assertSame([3, '2026-11-10 09:00:20'], [$version, $updatedAt]);
        self::assertSame($otherDraftsBefore, $this->draftRowsExcept(self::LENAS_DRAFT));
        self::assertSame([[$tokenId, AddReportScreenshot::AUDIT_TARGET_TYPE, self::LENAS_DRAFT, AddReportScreenshot::NAME, 'ok']], $this->toolRowsOf($this->acmeFoods));
        self::assertSame(0, $this->ticketCount());
    }

    /**
     * The first call completes the upload; the picture is added while the worker still processes it.
     */
    #[Test]
    public function anUploadNotCompletedYetIsCompletedAndItsPictureAdded(): void
    {
        $uploadId = $this->addUpload($this->acmeFoods, $this->people['Lena'], 'new_report', 'SHOP', null);
        [, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::ReportsWrite]);

        $this->callTool($token, AddReportScreenshot::NAME, ['report' => self::LENAS_DRAFT, 'upload_id' => $uploadId]);

        self::assertSame(2, $this->answer()['screenshot_count'] ?? null);
        self::assertSame([[$uploadId, 'new_report', 'SHOP', '2026-11-10 09:00:20']], $this->uploadIntentsOf($this->acmeFoods));
        self::assertSame([self::FIRST_PICTURE, $uploadId], array_column($this->screenshotsVersionAndUpdatedAtOf(self::LENAS_DRAFT)[0], 'media_id'));
    }

    /**
     * A call sent again, as after a lost answer: the picture is in the draft once and the version moves once.
     */
    #[Test]
    public function thePictureSentTwiceIsInTheDraftOnce(): void
    {
        $uploadId = $this->addUpload($this->acmeFoods, $this->people['Lena'], 'new_report', 'SHOP', 'ready');
        [, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::ReportsWrite]);

        $this->callTool($token, AddReportScreenshot::NAME, ['report' => self::LENAS_DRAFT, 'upload_id' => $uploadId]);
        self::assertSame(AddReportScreenshot::ADDED, $this->answer()['status'] ?? null);
        $this->callTool($token, AddReportScreenshot::NAME, ['report' => self::LENAS_DRAFT, 'upload_id' => $uploadId]);

        self::assertSame(['draft_id' => self::LENAS_DRAFT, 'media_id' => $uploadId, 'status' => AddReportScreenshot::ALREADY_IN_DRAFT, 'screenshot_count' => 2], $this->answer());
        [$screenshots, $version] = $this->screenshotsVersionAndUpdatedAtOf(self::LENAS_DRAFT);
        self::assertSame([self::FIRST_PICTURE, $uploadId], array_column($screenshots, 'media_id'));
        self::assertSame(3, $version);
    }

    /**
     * Two captures in a row: each answer counts the pictures its own add left in the draft.
     */
    #[Test]
    public function twoCapturesInARowAnswerTheCountEachOneLeft(): void
    {
        $secondPicture = $this->addUpload($this->acmeFoods, $this->people['Lena'], 'new_report', 'SHOP', 'ready');
        $thirdPicture = $this->addUpload($this->acmeFoods, $this->people['Lena'], 'new_report', 'SHOP', null);
        [, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::ReportsWrite]);

        $this->callTool($token, AddReportScreenshot::NAME, ['report' => self::LENAS_DRAFT, 'upload_id' => $secondPicture]);
        self::assertSame(['draft_id' => self::LENAS_DRAFT, 'media_id' => $secondPicture, 'status' => AddReportScreenshot::ADDED, 'screenshot_count' => 2], $this->answer());
        $this->callTool($token, AddReportScreenshot::NAME, ['report' => self::LENAS_DRAFT, 'upload_id' => $thirdPicture]);

        self::assertSame(['draft_id' => self::LENAS_DRAFT, 'media_id' => $thirdPicture, 'status' => AddReportScreenshot::ADDED, 'screenshot_count' => 3], $this->answer());
        [$screenshots, $version] = $this->screenshotsVersionAndUpdatedAtOf(self::LENAS_DRAFT);
        self::assertSame([self::FIRST_PICTURE, $secondPicture, $thirdPicture], array_column($screenshots, 'media_id'));
        self::assertSame(4, $version);
    }

    /**
     * The tenth picture sent again, as after a lost answer, is in the full draft already: not the cap's refusal.
     */
    #[Test]
    public function theTenthPictureSentAgainIsAlreadyInTheFullDraftAndNothingChanges(): void
    {
        $nearlyFullDraft = '0197a3c0-0000-7000-8000-0000000f0006';
        $pictures = array_map(static fn (int $number): array => self::storedPicture(Uuid::v7()->toRfc4122()), range(1, 9));
        $this->addReportDraft($this->people['Lena'], $nearlyFullDraft, 'SHOP', $pictures);
        $tenthPicture = $this->addUpload($this->acmeFoods, $this->people['Lena'], 'new_report', 'SHOP', 'ready');
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::ReportsWrite]);
        $this->callTool($token, AddReportScreenshot::NAME, ['report' => $nearlyFullDraft, 'upload_id' => $tenthPicture]);
        self::assertSame(['draft_id' => $nearlyFullDraft, 'media_id' => $tenthPicture, 'status' => AddReportScreenshot::ADDED, 'screenshot_count' => 10], $this->answer());
        $draftsBefore = $this->draftRows();

        $this->callTool($token, AddReportScreenshot::NAME, ['report' => $nearlyFullDraft, 'upload_id' => $tenthPicture]);

        self::assertSame(['draft_id' => $nearlyFullDraft, 'media_id' => $tenthPicture, 'status' => AddReportScreenshot::ALREADY_IN_DRAFT, 'screenshot_count' => 10], $this->answer());
        self::assertSame($draftsBefore, $this->draftRows());
        self::assertSame(2, $this->screenshotsVersionAndUpdatedAtOf($nearlyFullDraft)[1]);
        self::assertSame(array_fill(0, 2, [$tokenId, AddReportScreenshot::AUDIT_TARGET_TYPE, $nearlyFullDraft, AddReportScreenshot::NAME, 'ok']), $this->toolRowsOf($this->acmeFoods));
    }

    #[Test]
    public function aRejectedPictureIsRefusedAndTheDraftStaysAsItWas(): void
    {
        $uploadId = $this->addUpload($this->acmeFoods, $this->people['Lena'], 'new_report', 'SHOP', 'rejected');
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::ReportsWrite]);
        $draftsBefore = $this->draftRows();

        $this->callTool($token, AddReportScreenshot::NAME, ['report' => self::LENAS_DRAFT, 'upload_id' => $uploadId]);

        $this->assertRefused(AddReportScreenshot::PICTURE_REJECTED);
        self::assertSame($draftsBefore, $this->draftRows());
        self::assertSame([[$tokenId, AddReportScreenshot::AUDIT_TARGET_TYPE, self::LENAS_DRAFT, AddReportScreenshot::NAME, 'refused']], $this->toolRowsOf($this->acmeFoods));
    }

    /**
     * The completion runs in the gate's savepoint, so the refusal leaves the upload unused.
     */
    #[Test]
    public function anEleventhPictureIsRefusedWithReviewsTextAndTheUploadStaysUnused(): void
    {
        $fullDraft = '0197a3c0-0000-7000-8000-0000000f0004';
        $pictures = array_map(static fn (int $number): array => self::storedPicture(Uuid::v7()->toRfc4122()), range(1, 10));
        $this->addReportDraft($this->people['Lena'], $fullDraft, 'SHOP', $pictures);
        $uploadId = $this->addUpload($this->acmeFoods, $this->people['Lena'], 'new_report', 'SHOP', null);
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::ReportsWrite]);
        $draftsBefore = $this->draftRows();

        $this->callTool($token, AddReportScreenshot::NAME, ['report' => $fullDraft, 'upload_id' => $uploadId]);

        $this->assertRefused(ReportDraftRefused::TOO_MANY_SCREENSHOTS);
        self::assertSame($draftsBefore, $this->draftRows());
        self::assertCount(10, $this->screenshotsVersionAndUpdatedAtOf($fullDraft)[0]);
        self::assertSame([[$uploadId, 'new_report', 'SHOP', null]], $this->uploadIntentsOf($this->acmeFoods));
        self::assertSame([[$tokenId, AddReportScreenshot::AUDIT_TARGET_TYPE, $fullDraft, AddReportScreenshot::NAME, 'refused']], $this->toolRowsOf($this->acmeFoods));
    }

    /**
     * Omar and Sara hold reports:write in Acme Foods, and Lena's other token is of Beta Bakery, each with an upload of
     * their own begun for SHOP: none of them finds Lena's draft. An unknown or malformed id is not found too.
     */
    #[Test]
    public function anotherPersonsTheAdminsAndAnotherOrganizationsTokenAndAnUnknownIdGetNotFoundAndNothingChanges(): void
    {
        $calls = [
            [$this->acmeFoods, $this->people['Omar'], self::LENAS_DRAFT],
            [$this->acmeFoods, $this->people['Sara'], self::LENAS_DRAFT],
            [$this->betaBakery, $this->people['Lena'], self::LENAS_DRAFT],
            [$this->acmeFoods, $this->people['Lena'], '0197a3c0-0000-7000-8000-0000000f9999'],
            [$this->acmeFoods, $this->people['Lena'], 'not-a-uuid'],
        ];
        $draftsBefore = $this->draftRows();

        foreach ($calls as [$organizationId, $person, $draftId]) {
            $uploadId = $this->addUpload($organizationId, $person, 'new_report', 'SHOP', null);
            [$tokenId, $token] = $this->issueApiToken($person, $organizationId, scopes: [ApiTokenScope::ReportsWrite]);

            $this->callTool($token, AddReportScreenshot::NAME, ['report' => $draftId, 'upload_id' => $uploadId]);

            $this->assertRefused(ToolRefusal::NOT_FOUND);
            self::assertContains([$tokenId, ToolCallAuditor::TOOL_TARGET_TYPE, null, AddReportScreenshot::NAME, 'not_found'], $this->toolRowsOf($organizationId));
            self::assertContains([$uploadId, 'new_report', 'SHOP', null], $this->uploadIntentsOf($organizationId));
        }
        self::assertSame($draftsBefore, $this->draftRows());
    }

    /**
     * Each upload is one Lena may not attach to her SHOP draft: Omar's for SHOP, and hers for MOBILE, for a round and
     * for a notebook.
     */
    #[Test]
    public function anUploadBegunByAnotherPersonOrForAnythingButTheDraftsProjectIsNotFoundAndStaysUnused(): void
    {
        $uploads = [
            $this->addUpload($this->acmeFoods, $this->people['Omar'], 'new_report', 'SHOP', 'ready'),
            $this->addUpload($this->acmeFoods, $this->people['Lena'], 'new_report', 'MOBILE', null),
            $this->addUpload($this->acmeFoods, $this->people['Lena'], 'round', Uuid::v7()->toRfc4122(), null),
            $this->addUpload($this->acmeFoods, $this->people['Lena'], 'notebook', Uuid::v7()->toRfc4122(), null),
        ];
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::ReportsWrite]);
        $draftsBefore = $this->draftRows();
        $intentsBefore = $this->uploadIntentsOf($this->acmeFoods);

        foreach ($uploads as $uploadId) {
            $this->callTool($token, AddReportScreenshot::NAME, ['report' => self::LENAS_DRAFT, 'upload_id' => $uploadId]);

            $this->assertRefused(ToolRefusal::NOT_FOUND);
        }

        self::assertSame($draftsBefore, $this->draftRows());
        self::assertSame($intentsBefore, $this->uploadIntentsOf($this->acmeFoods));
        self::assertSame(array_fill(0, 4, [$tokenId, ToolCallAuditor::TOOL_TARGET_TYPE, null, AddReportScreenshot::NAME, 'not_found']), $this->toolRowsOf($this->acmeFoods));
    }

    #[Test]
    public function anUploadThatExpiredUnusedIsRefusedAndTheDraftStaysAsItWas(): void
    {
        $uploadId = $this->addUpload($this->acmeFoods, $this->people['Lena'], 'new_report', 'SHOP', null);
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE med_upload_intent SET expires_at = TIMESTAMPTZ '2026-11-10 09:00:19+00' WHERE id = ?",
            [$uploadId],
        ));
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::ReportsWrite]);
        $draftsBefore = $this->draftRows();

        $this->callTool($token, AddReportScreenshot::NAME, ['report' => self::LENAS_DRAFT, 'upload_id' => $uploadId]);

        $this->assertRefused(AddReportScreenshot::UPLOAD_EXPIRED);
        self::assertSame($draftsBefore, $this->draftRows());
        self::assertSame([[$uploadId, 'new_report', 'SHOP', null]], $this->uploadIntentsOf($this->acmeFoods));
        self::assertSame([[$tokenId, AddReportScreenshot::AUDIT_TARGET_TYPE, self::LENAS_DRAFT, AddReportScreenshot::NAME, 'refused']], $this->toolRowsOf($this->acmeFoods));
    }

    /**
     * Media asks the new_report target again when it completes the upload: a project that no team owns any more refuses
     * it, so the call is not found and the upload stays unused.
     */
    #[Test]
    public function anUploadForADraftWhoseProjectNoTeamOwnsIsNotFoundAndStaysUnused(): void
    {
        $this->addProject(null, 'LOOSE', 'Loose ends');
        $looseDraft = '0197a3c0-0000-7000-8000-0000000f0005';
        $this->addReportDraft($this->people['Lena'], $looseDraft, 'LOOSE');
        $uploadId = $this->addUpload($this->acmeFoods, $this->people['Lena'], 'new_report', 'LOOSE', null);
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::ReportsWrite]);
        $draftsBefore = $this->draftRows();

        $this->callTool($token, AddReportScreenshot::NAME, ['report' => $looseDraft, 'upload_id' => $uploadId]);

        $this->assertRefused(ToolRefusal::NOT_FOUND);
        self::assertSame($draftsBefore, $this->draftRows());
        self::assertSame([[$uploadId, 'new_report', 'LOOSE', null]], $this->uploadIntentsOf($this->acmeFoods));
        self::assertSame([[$tokenId, AddReportScreenshot::AUDIT_TARGET_TYPE, $looseDraft, AddReportScreenshot::NAME, 'not_found']], $this->toolRowsOf($this->acmeFoods));
    }

    #[Test]
    public function aTokenWithoutReportsWriteGetsMissingScopeAndNothingChanges(): void
    {
        $uploadId = $this->addUpload($this->acmeFoods, $this->people['Lena'], 'new_report', 'SHOP', null);
        [$tokenId, $token] = $this->issueApiToken($this->people['Lena'], $this->acmeFoods, scopes: [ApiTokenScope::Read, ApiTokenScope::RoundsWrite, ApiTokenScope::NotebooksWrite]);
        $draftsBefore = $this->draftRows();

        $this->callTool($token, AddReportScreenshot::NAME, ['report' => self::LENAS_DRAFT, 'upload_id' => $uploadId]);

        $this->assertRefused(\sprintf(ToolRefusal::MISSING_SCOPE, 'reports:write'));
        self::assertSame($draftsBefore, $this->draftRows());
        self::assertSame([[$uploadId, 'new_report', 'SHOP', null]], $this->uploadIntentsOf($this->acmeFoods));
        self::assertSame([[$tokenId, ToolCallAuditor::TOOL_TARGET_TYPE, null, AddReportScreenshot::NAME, 'missing_scope']], $this->toolRowsOf($this->acmeFoods));
    }

    /**
     * @param list<array<string, mixed>> $screenshots {media_id, title, summary, pins: [{x_pct, y_pct, note}]} each
     */
    private function addReportDraft(User $owner, string $draftId, string $projectKey, array $screenshots = [], int $version = 1): void
    {
        $parameters = [$this->acmeFoods->toString(), $draftId, $owner->id()->toString(), $projectKey, json_encode($screenshots, \JSON_THROW_ON_ERROR), $version];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO rvw_report_draft (organization_id, id, owner_id, project_key, title, detail, screenshots, version, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'Total is doubled', '', CAST(? AS jsonb), ?, '2026-11-01 08:00:00+00', '2026-11-05 08:00:00+00')",
            $parameters,
        ));
    }

    /**
     * @return list<list<mixed>> each Acme Foods draft's id, owner, project, screenshots (as stored, JSON), version and
     *                           change time, by id
     */
    private function draftRows(): array
    {
        $rows = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use (&$rows): int {
            $rows = $migrator->fetchAllNumeric('SELECT id, owner_id, project_key, screenshots::text, version, updated_at FROM rvw_report_draft ORDER BY id');

            return 0;
        });
        self::assertNotSame([], $rows);

        return $rows;
    }

    /**
     * @return list<list<mixed>>
     */
    private function draftRowsExcept(string $draftId): array
    {
        return array_values(array_filter($this->draftRows(), static fn (array $row): bool => $draftId !== $row[0]));
    }

    /**
     * @return array{array<mixed>, mixed, mixed} the screenshots decoded, the version, and the change time in UTC
     */
    private function screenshotsVersionAndUpdatedAtOf(string $draftId): array
    {
        $rows = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($draftId, &$rows): int {
            $rows = $migrator->fetchAllNumeric("SELECT screenshots::text, version, to_char(updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') FROM rvw_report_draft WHERE id = ?", [$draftId]);

            return 0;
        });
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertIsString($row[0]);
        $screenshots = json_decode($row[0], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($screenshots);

        return [$screenshots, $row[1], $row[2]];
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
     * @return array{media_id: string, title: null, summary: null, pins: list<never>} a picture as rvw_report_draft
     *                                                                                keeps it
     */
    private static function storedPicture(string $mediaId): array
    {
        return ['media_id' => $mediaId, 'title' => null, 'summary' => null, 'pins' => []];
    }
}
