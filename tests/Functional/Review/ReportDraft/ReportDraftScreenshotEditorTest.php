<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review\ReportDraft;

use App\Identity\Domain\User;
use App\Review\Api\ReportDraftRefused;
use App\Review\UI\ReportDraftController;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Review\SeedsMediaObjects;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The report draft page shows its pictures in the ScreenshotEditor, one at a time (P14.T10), and its form posts what
 * the page posted before: screenshots[i][title], [summary], [pins][p] and [remove]. Mia owns the drafts. The clock is
 * frozen on 6 Nov 2026.
 */
final class ReportDraftScreenshotEditorTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesCsrfTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use SeedsMediaObjects;

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string DRAFTS = '/report-drafts';
    private const string TITLE = 'Total is doubled on the cart';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));
        $this->people = $this->createAcmeFoodsPeople(null);
        $this->addProject($this->shop, 'SHOP', 'Shop');
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (['rvw_report_draft', 'med_object'] as $table) {
                    $migrator->executeStatement(\sprintf('DELETE FROM %s WHERE organization_id = ?', $table), [$organizationId]);
                }

                return 0;
            });
        }
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function fourScreenshotsShowOneAtATimeEachWithItsTitleSummaryAndPinNotes(): void
    {
        $draft = $this->addDraft($this->fourScreenshots());
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::DRAFTS.'/'.$draft);

        self::assertResponseStatusCodeSame(200);
        $editor = $page->filter('form.login__form #draft-shots.screenshot-editor');
        self::assertCount(1, $editor);
        $panels = $editor->filter('[role="tabpanel"]');
        self::assertCount(4, $panels);
        self::assertSame([null, '', '', ''], $panels->each(static fn (Crawler $panel): ?string => $panel->attr('hidden')), 'Only the first shows.');
        self::assertSame(['Screenshot 1 of 4', 'Screenshot 2 of 4', 'Screenshot 3 of 4', 'Screenshot 4 of 4'], $editor->filter('button[role="tab"]')->each(static fn (Crawler $tab): string => $tab->text()));
        self::assertSame(['Cart', '', 'Sum', 'Coupon'], $editor->filter('input[name$="[title]"]')->each(static fn (Crawler $field): string => (string) $field->attr('value')));
        self::assertSame(['screenshots[0][title]', 'screenshots[1][title]', 'screenshots[2][title]', 'screenshots[3][title]'], $editor->filter('input[name$="[title]"]')->each(static fn (Crawler $field): string => (string) $field->attr('name')));
        self::assertSame(['The cart page.', '', '', 'The coupon field.'], $editor->filter('textarea[name$="[summary]"]')->each(static fn (Crawler $field): string => $field->text()));
        self::assertSame(
            [
                'screenshots[0][pins][0]' => 'Total',
                'screenshots[0][pins][1]' => 'Count',
                'screenshots[2][pins][0]' => 'Sum line',
                'screenshots[3][pins][0]' => 'Empty field',
                'screenshots[3][pins][1]' => '',
                'screenshots[3][pins][2]' => 'Wrong code',
            ],
            $this->pinNotesOf($page),
        );
        self::assertCount(0, $page->filter('[name^="pins["]'), 'No pin-placer fields: a draft\'s pins keep their spots, and pins[n][note] would collide with nothing.');
        self::assertCount(0, $editor->filter('.screenshot-editor__add'), 'A draft takes no new picture here.');
        self::assertSame(['', '', '', ''], $editor->filter('input[type="hidden"][name$="[remove]"]')->each(static fn (Crawler $field): ?string => $field->attr('disabled')), 'No picture is removed until Remove is pressed.');
    }

    #[Test]
    public function aDraftWithoutScreenshotsShowsNoEditorAndNoPictureFields(): void
    {
        $draft = $this->addDraft([]);
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::DRAFTS.'/'.$draft);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::TITLE, $page->filter('input[name=title]')->attr('value'), 'The page itself is there.');
        self::assertCount(0, $page->filter('.screenshot-editor'));
        self::assertCount(0, $page->filter('[name^="screenshots["]'));
        self::assertStringNotContainsString('Picture title', $page->filter('main')->text());
    }

    #[Test]
    public function moreDetailStartsAtFiveLinesAndTheEditorStylesFollowTheReviewStyles(): void
    {
        $draft = $this->addDraft([]);
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::DRAFTS.'/'.$draft);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('5', $page->filter('textarea[name=detail]')->attr('rows'));
        $stylesheets = $page->filter('link[rel=stylesheet]')->each(static fn (Crawler $link): string => (string) $link->attr('href'));
        $review = self::positionOf('#/styles/review[-.]#', $stylesheets);
        $editor = self::positionOf('#/styles/screenshot-editor[-.]#', $stylesheets);
        self::assertSame($review + 1, $editor, 'The editor\'s styles come right after review.css, which they build on.');
    }

    /**
     * The form as the page renders it, saved untouched, keeps every screenshot as it was: the editor posts the names
     * and values the save reads, hidden screenshots included. The draft page it goes back to says so; the e2e spec
     * leaves that one-time toast to this test, as another spec on the same saved session may take it first.
     */
    #[Test]
    public function savingTheFormUntouchedKeepsEveryScreenshotPinAndNote(): void
    {
        $screenshots = $this->fourScreenshots();
        $draft = $this->addDraft($screenshots);
        $this->logIn($this->client, $this->people['Mia']);
        $page = $this->client->request('GET', self::DRAFTS.'/'.$draft);
        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('.toast-region [data-toast-message-value]'), 'No toast before the save.');

        $this->client->submit($page->selectButton('Save changes')->form());

        self::assertResponseStatusCodeSame(303);
        self::assertResponseRedirects(self::DRAFTS.'/'.$draft);
        self::assertEquals($screenshots, $this->storedScreenshotsOf($draft, 2));
        $savedPage = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(ReportDraftController::SAVED, $savedPage->filter('.toast-region [data-toast-message-value]')->attr('data-toast-message-value'));
    }

    /**
     * Notes and titles typed on screenshots shown or hidden are all saved by position; Remove drops only its own
     * screenshot, and the pins keep their spots.
     */
    #[Test]
    public function aSaveWithFourScreenshotsStoresEachTypedNoteAndDropsTheRemovedOne(): void
    {
        $screenshots = $this->fourScreenshots();
        $draft = $this->addDraft($screenshots);
        $this->logIn($this->client, $this->people['Mia']);
        $page = $this->client->request('GET', self::DRAFTS.'/'.$draft);
        self::assertResponseStatusCodeSame(200);
        $form = $page->selectButton('Save changes')->form();
        $form['screenshots[0][pins][1]'] = 'Count is 3, not 2';
        $form['screenshots[1][title]'] = 'Menu';
        $form['screenshots[3][pins][1]'] = 'No message';
        $values = $form->getPhpValues();
        self::assertIsArray($values['screenshots']);
        self::assertIsArray($values['screenshots'][2]);
        $values['screenshots'][2]['remove'] = '1';

        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseStatusCodeSame(303);
        $screenshots[0]['pins'][1]['note'] = 'Count is 3, not 2';
        $screenshots[1]['title'] = 'Menu';
        $screenshots[3]['pins'][1]['note'] = 'No message';
        self::assertEquals([$screenshots[0], $screenshots[1], $screenshots[3]], $this->storedScreenshotsOf($draft, 2));
    }

    /**
     * A refused save shows what was typed for each screenshot, past the caps too, and keeps a removed one removed;
     * nothing is saved.
     */
    #[Test]
    public function aRefusedSaveShowsTheTypedTextAndRemoveOfEveryScreenshot(): void
    {
        $screenshots = $this->fourScreenshots();
        $draft = $this->addDraft($screenshots);
        $this->logIn($this->client, $this->people['Mia']);
        $tooLong = str_repeat('é', 601);

        $page = $this->client->request('POST', self::DRAFTS.'/'.$draft, [
            ReportDraftController::CSRF_FIELD => $this->csrfTokenFor($this->client, ReportDraftController::csrfIdOf($draft)),
            ReportDraftController::VERSION_FIELD => '1',
            'title' => self::TITLE,
            'screenshots' => [
                ['title' => 'Cart page', 'pins' => ['Total twice', 'Count']],
                ['remove' => '1'],
                ['summary' => $tooLong],
                ['pins' => [2 => "Wrong code\r\nTwice"]],
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(ReportDraftRefused::PICTURE_SUMMARY_TOO_LONG, $page->filter('#report-draft-error')->text());
        self::assertSame('Cart page', $page->filter('input[name="screenshots[0][title]"]')->attr('value'));
        self::assertSame($tooLong, $page->filter('textarea[name="screenshots[2][summary]"]')->text());
        self::assertSame('The coupon field.', $page->filter('textarea[name="screenshots[3][summary]"]')->text(), 'Not posted, so as saved.');
        $notes = $this->pinNotesOf($page);
        self::assertSame(['Total twice', 'Count', "Wrong code\nTwice"], [$notes['screenshots[0][pins][0]'], $notes['screenshots[0][pins][1]'], str_replace("\r\n", "\n", $notes['screenshots[3][pins][2]'])]);
        self::assertSame('Empty field', $notes['screenshots[3][pins][0]'], 'Not posted, so as saved.');
        $removed = $page->filter('input[name="screenshots[1][remove]"]');
        self::assertNull($removed->attr('disabled'), 'Removed stays removed.');
        self::assertSame('', $page->filter('input[name="screenshots[0][remove]"]')->attr('disabled'));
        self::assertEquals($screenshots, $this->storedScreenshotsOf($draft, 1));
    }

    /**
     * Four screenshots: two pins, none, one, and three of which one has no note; one has no title or summary.
     *
     * @return list<array{media_id: string, title: ?string, summary: ?string, pins: list<array{x_pct: float, y_pct: float, note: string}>}>
     */
    private function fourScreenshots(): array
    {
        return [
            ['media_id' => $this->addMedia('ready'), 'title' => 'Cart', 'summary' => 'The cart page.', 'pins' => [['x_pct' => 10.5, 'y_pct' => 20.0, 'note' => 'Total'], ['x_pct' => 60.0, 'y_pct' => 70.25, 'note' => 'Count']]],
            ['media_id' => $this->addMedia('ready'), 'title' => null, 'summary' => null, 'pins' => []],
            ['media_id' => $this->addMedia('pending'), 'title' => 'Sum', 'summary' => null, 'pins' => [['x_pct' => 50.0, 'y_pct' => 50.0, 'note' => 'Sum line']]],
            ['media_id' => $this->addMedia('ready'), 'title' => 'Coupon', 'summary' => 'The coupon field.', 'pins' => [['x_pct' => 5.0, 'y_pct' => 5.0, 'note' => 'Empty field'], ['x_pct' => 95.0, 'y_pct' => 95.0, 'note' => ''], ['x_pct' => 30.0, 'y_pct' => 40.0, 'note' => 'Wrong code']]],
        ];
    }

    /**
     * @return array<string, string> each draft pin note field's name and text, in page order
     */
    private function pinNotesOf(Crawler $page): array
    {
        $notes = [];
        foreach ($page->filter('textarea[name^="screenshots["][name*="[pins]"]') as $field) {
            self::assertInstanceOf(\DOMElement::class, $field);
            $notes[$field->getAttribute('name')] = $field->textContent;
        }
        self::assertNotSame([], $notes);

        return $notes;
    }

    /**
     * @param list<string> $hrefs
     */
    private static function positionOf(string $pattern, array $hrefs): int
    {
        foreach ($hrefs as $position => $href) {
            if (1 === preg_match($pattern, $href)) {
                return $position;
            }
        }
        self::fail(\sprintf('No stylesheet matches %s.', $pattern));
    }

    /**
     * @return list<mixed> the draft's screenshots as stored, after checking its version
     */
    private function storedScreenshotsOf(string $draftId, int $version): array
    {
        $row = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($draftId, &$row): int {
            $row = $migrator->fetchAssociative('SELECT screenshots::text AS screenshots, version FROM rvw_report_draft WHERE id = ?', [$draftId]);

            return 0;
        });
        self::assertIsArray($row);
        self::assertSame($version, $row['version']);
        self::assertIsString($row['screenshots']);
        $screenshots = json_decode($row['screenshots'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsList($screenshots);

        return $screenshots;
    }

    /**
     * @param list<array{media_id: string, title: ?string, summary: ?string, pins: list<array{x_pct: float, y_pct: float, note: string}>}> $screenshots
     *
     * @return string the draft's id
     */
    private function addDraft(array $screenshots): string
    {
        $draftId = Uuid::v7()->toRfc4122();
        $parameters = [$this->acmeFoods->toString(), $draftId, $this->people['Mia']->id()->toString(), self::TITLE, json_encode($screenshots, \JSON_THROW_ON_ERROR)];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO rvw_report_draft (organization_id, id, owner_id, project_key, title, detail, screenshots, version, created_at, updated_at)
             VALUES (?, ?, ?, 'SHOP', ?, '', CAST(? AS jsonb), 1, '2026-11-05 07:00:00+00', '2026-11-05 08:00:00+00')",
            $parameters,
        ));

        return $draftId;
    }
}
