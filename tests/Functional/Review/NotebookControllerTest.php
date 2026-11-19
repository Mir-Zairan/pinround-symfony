<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\Identity\Domain\User;
use App\Review\Api\NotebookRefused;
use App\Review\Domain\Notebook\NotebookRefused as NotebookRuleBroken;
use App\Review\UI\Controller\RefusedWithToast;
use App\Review\UI\NotebookController;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The "My notebooks" pages (P13.T6 step 3, ADR-0046). Lena owns the notebooks; Omar is a member of her team, Sara an
 * admin of Acme Foods and Ben a person of Beta Bakery, and none of them may see one. Notebooks are written straight into
 * rvw_notebook, as an agent leaves them. The clock is frozen on 6 Nov 2026 at noon UTC.
 */
final class NotebookControllerTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesCsrfTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use SeedsTicketRounds;

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string LIST = '/notebooks';
    /** With the notebook's number, 01 to 21; Omar's ids sort above Lena's. */
    private const string LENAS_PAGED_NOTEBOOK = '0197a3c0-0000-7000-8000-0000000a00%02d';
    private const string OMARS_PAGED_NOTEBOOK = '0197a3c0-0000-7000-8000-0000000e00%02d';
    private const string LENAS_NOTEBOOK = '0197a3c0-0000-7000-8000-0000000b0001';
    private const string LENAS_SECOND_NOTEBOOK = '0197a3c0-0000-7000-8000-0000000b0002';
    private const string OMARS_NOTEBOOK = '0197a3c0-0000-7000-8000-0000000c0001';
    private const string LENAS_NOTEBOOK_IN_BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000f0001';
    private const string TITLE = 'Coupon field questions';
    private const string USED_AT = '2026-11-05 08:00:00.5+00';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private OrganizationId $betaBakery;
    private User $ben;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        self::getContainer()->set('clock', new MockClock(new \DateTimeImmutable(self::NOW)));
        $this->people = $this->createAcmeFoodsPeople(null);
        $this->betaBakery = $this->createOrganization('Beta Bakery');
        $this->ben = $this->createUserWithoutOrganization('Ben');
        $this->addMembership($this->ben, $this->betaBakery);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static fn (Connection $migrator): int|string => $migrator->executeStatement('DELETE FROM rvw_notebook WHERE organization_id = ?', [$organizationId]));
        }
        $this->removeSeededRounds();
        $this->removeUsersAndOrganizations();
    }

    /**
     * Omar's notebook sits in Lena's organization, and Beta Bakery's names Lena as its owner: the list must leave out
     * the first by its owner and the second by its organization.
     */
    #[Test]
    public function theListShowsOnlyTheOwnersNotebooksLastUsedFirstWithTheirCounts(): void
    {
        $this->addNotebook($this->acmeFoods, 'Lena', self::LENAS_NOTEBOOK, 'Older notebook', self::USED_AT, [self::screenshotOf($this->addMedia('ready', 'Lena')), self::screenshotOf($this->addMedia('ready', 'Lena'))], [self::scenario('Warn on empty stock')]);
        $this->addNotebook($this->acmeFoods, 'Lena', self::LENAS_SECOND_NOTEBOOK, 'Newer notebook', '2026-11-06 09:00:00+00', [self::screenshotOf($this->addMedia('ready', 'Lena'))], [self::scenario('First'), self::scenario('Second')]);
        $this->addNotebook($this->acmeFoods, 'Omar', self::OMARS_NOTEBOOK, 'Omar own notebook', '2026-11-06 10:00:00+00');
        $this->addNotebook($this->betaBakery, 'Lena', self::LENAS_NOTEBOOK_IN_BETA_BAKERY, 'Beta Bakery notebook', '2026-11-06 11:00:00+00');
        $this->logIn($this->client, $this->people['Lena']);

        $list = $this->client->request('GET', self::LIST);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('My notebooks', $list->filter('h1')->text());
        self::assertSame(['Newer notebook', 'Older notebook'], self::cardTitlesOf($list));
        self::assertSame(['1 screenshot · 2 scenarios · Last used 3 h ago', '2 screenshots · 1 scenario · Last used 5 Nov'], $list->filter('.report-drafts__card p')->each(static fn (Crawler $line): string => $line->text()));
        self::assertSame([self::LIST.'/'.self::LENAS_SECOND_NOTEBOOK, self::LIST.'/'.self::LENAS_NOTEBOOK], $list->filter('.report-drafts__card h2 a')->each(static fn (Crawler $link): ?string => $link->attr('href')));
        self::assertSame(0, $list->filter('a[rel=next]')->count());

        $this->logIn($this->client, $this->people['Mia']);
        $empty = $this->client->request('GET', self::LIST);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $empty->filter('.report-drafts__card')->count());
        self::assertStringContainsString('You have no notebooks.', $empty->filter('.report-drafts')->text());
    }

    /**
     * Lena's notebook n was last used at minute n / 3, so they tie in threes, and notebooks 1 and 2, on both sides of
     * the page break, tie to the microsecond; ids rise with n, so the order is notebook 21 down to notebook 1. They are
     * inserted out of that order. Omar's notebooks sit at the same times with higher ids and would come between them if
     * the owner were not in the query.
     */
    #[Test]
    public function theListShowsTwentyNotebooksAndShowMoreOpensTheRest(): void
    {
        foreach ([11, 2, 20, 5, 17, 1, 14, 8, 21, 3, 12, 19, 6, 15, 9, 4, 18, 10, 13, 16, 7] as $number) {
            $usedAt = \sprintf('2026-11-05 08:%02d:00.5+00', intdiv($number, 3));
            $this->addNotebook($this->acmeFoods, 'Lena', \sprintf(self::LENAS_PAGED_NOTEBOOK, $number), 'Notebook '.$number, $usedAt);
            if (0 === $number % 5) {
                $this->addNotebook($this->acmeFoods, 'Omar', \sprintf(self::OMARS_PAGED_NOTEBOOK, $number), 'Omar notebook '.$number, $usedAt);
            }
        }
        $this->logIn($this->client, $this->people['Lena']);

        $first = $this->client->request('GET', self::LIST);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(array_map(static fn (int $number): string => 'Notebook '.$number, range(21, 2)), self::cardTitlesOf($first));
        $more = $first->filter('a[rel=next]');
        self::assertSame(['Show more'], $more->each(static fn (Crawler $link): string => trim($link->text())));
        $secondNotebookId = \sprintf(self::LENAS_PAGED_NOTEBOOK, 2);
        self::assertSame(self::LIST.'?'.NotebookController::AFTER_QUERY.'='.new \DateTimeImmutable('2026-11-05 08:00:00.5+00')->format('U.u').'.'.$secondNotebookId, $more->attr('href'));

        $second = $this->client->request('GET', $more->attr('href'));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Notebook 1'], self::cardTitlesOf($second));
        self::assertSame(0, $second->filter('a[rel=next]')->count(), 'Nothing follows notebook 1.');
    }

    #[Test]
    public function aMalformedListCursorIsNotFound(): void
    {
        $this->addNotebook($this->acmeFoods, 'Lena', self::LENAS_NOTEBOOK, self::TITLE, self::USED_AT);
        $this->logIn($this->client, $this->people['Lena']);

        $malformed = [
            'empty' => '',
            'words' => 'next',
            'no microseconds' => '1762329600.'.self::LENAS_NOTEBOOK,
            'short microseconds' => '1762329600.5.'.self::LENAS_NOTEBOOK,
            'uppercase id' => '1762329600.500000.'.strtoupper(self::LENAS_NOTEBOOK),
            'a UUIDv4 id' => '1762329600.500000.0197a3c0-0000-4000-8000-0000000b0001',
            'trailing newline' => '1762329600.500000.'.self::LENAS_NOTEBOOK."\n",
        ];
        foreach ($malformed as $case => $after) {
            $this->client->request('GET', self::LIST, [NotebookController::AFTER_QUERY => $after]);
            self::assertResponseStatusCodeSame(404, $case);
            self::assertStringContainsString(NotebookController::LIST_PAGE_NOT_FOUND, (string) $this->client->getResponse()->getContent(), $case);
        }
        $this->client->request('GET', self::LIST, [NotebookController::AFTER_QUERY => ['1762329600.500000.'.self::LENAS_NOTEBOOK]]);
        self::assertResponseStatusCodeSame(404, 'A list of cursors');

        $page = $this->client->request('GET', self::LIST, [NotebookController::AFTER_QUERY => '99999999999.000000.'.self::LENAS_NOTEBOOK]);
        self::assertResponseStatusCodeSame(200, 'A well-formed cursor of no notebook starts the page at its place.');
        self::assertSame([self::TITLE], self::cardTitlesOf($page));
        $page = $this->client->request('GET', self::LIST, [NotebookController::AFTER_QUERY => '0.000000.'.self::LENAS_NOTEBOOK]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $page->filter('.report-drafts__card')->count());
        self::assertStringContainsString('No more notebooks.', $page->filter('.report-drafts')->text());
    }

    #[Test]
    public function theOwnerSeesTheNotebooksPicturesPinsAndScenariosAndOpeningItIsNoUse(): void
    {
        $ready = $this->addMedia('ready', 'Lena');
        $pending = $this->addMedia('pending', 'Lena');
        $this->addNotebook($this->acmeFoods, 'Lena', self::LENAS_NOTEBOOK, self::TITLE, self::USED_AT, [
            ['media_id' => $ready, 'title' => 'Cart', 'summary' => 'The cart page.', 'pins' => [['x_pct' => 10.5, 'y_pct' => 20.0, 'note' => 'Why is this empty?'], ['x_pct' => 70.0, 'y_pct' => 30.0, 'note' => 'Where does this come from?']]],
            ['media_id' => $pending, 'title' => null, 'summary' => null, 'pins' => []],
        ], [self::scenario('Warn on empty stock'), self::scenario('Coupon clears on reload')]);
        $before = $this->notebookRowOf(self::LENAS_NOTEBOOK);
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::LIST.'/'.self::LENAS_NOTEBOOK);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::TITLE, $page->filter('h1')->text());
        self::assertSame(self::TITLE, $page->filter('input[name=title]')->attr('value'));
        self::assertSame(['Screenshot 1', 'Screenshot 2'], $page->filter('section.report-draft__picture h3')->each(static fn (Crawler $heading): string => $heading->text()));
        self::assertSame('Cart', $page->filter('#notebook-shot-1 .screenshot__title')->text());
        self::assertNotEmpty((string) $page->filter('#notebook-shot-1 img.screenshot__image')->attr('src'));
        self::assertSame('Processing…', $page->filter('#notebook-shot-2 .screenshot__placeholder')->text());
        self::assertSame(['Why is this empty?', 'Where does this come from?'], $page->filter('.pin-list__note')->each(static fn (Crawler $note): string => $note->text()));
        self::assertSame(['Warn on empty stock', 'Coupon clears on reload'], $page->filter('section[aria-labelledby=notebook-scenarios-heading] li')->each(static fn (Crawler $scenario): string => $scenario->text()));
        self::assertSame($before, $this->notebookRowOf(self::LENAS_NOTEBOOK), 'Reading a notebook keeps its last use.');
    }

    #[Test]
    public function anEmptyNotebookSaysItHasNoScreenshotsOrScenarios(): void
    {
        $this->addNotebook($this->acmeFoods, 'Lena', self::LENAS_NOTEBOOK, self::TITLE, self::USED_AT);
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::LIST.'/'.self::LENAS_NOTEBOOK);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(0, $page->filter('.screenshot')->count());
        self::assertStringContainsString('No screenshots yet.', $page->filter('section[aria-labelledby=notebook-screenshots-heading]')->text());
        self::assertStringContainsString('No scenarios yet.', $page->filter('section[aria-labelledby=notebook-scenarios-heading]')->text());
    }

    #[Test]
    public function nobodyButTheOwnerOpensRenamesOrDeletesTheNotebookAndNothingChanges(): void
    {
        $this->addNotebook($this->acmeFoods, 'Lena', self::LENAS_NOTEBOOK, self::TITLE, self::USED_AT);
        $before = $this->notebookRowOf(self::LENAS_NOTEBOOK);
        $strangers = ['Omar' => $this->people['Omar'], 'Sara' => $this->people['Sara'], 'Ben of Beta Bakery' => $this->ben];

        foreach ($strangers as $name => $stranger) {
            $this->logIn($this->client, $stranger);
            $this->client->request('GET', self::LIST.'/'.self::LENAS_NOTEBOOK);
            self::assertResponseStatusCodeSame(404, $name);
            self::assertStringContainsString(NotebookRefused::NOT_FOUND, (string) $this->client->getResponse()->getContent(), $name);
            foreach (['/rename', '/delete'] as $action) {
                $this->client->request('POST', self::LIST.'/'.self::LENAS_NOTEBOOK.$action, [...$this->formFields(self::LENAS_NOTEBOOK), NotebookController::TITLE_FIELD => 'Changed by '.$name]);
                self::assertResponseStatusCodeSame(404, $name.$action);
                self::assertStringContainsString(NotebookRefused::NOT_FOUND, (string) $this->client->getResponse()->getContent(), $name.$action);
            }
            $list = $this->client->request('GET', self::LIST);
            self::assertResponseStatusCodeSame(200, $name);
            self::assertSame([], self::cardTitlesOf($list), $name);
        }

        self::assertSame($before, $this->notebookRowOf(self::LENAS_NOTEBOOK));
    }

    #[Test]
    public function aMalformedOrUnknownIdIsNotFound(): void
    {
        $this->addNotebook($this->acmeFoods, 'Lena', self::LENAS_NOTEBOOK, self::TITLE, self::USED_AT);
        $before = $this->notebookRowOf(self::LENAS_NOTEBOOK);
        $this->logIn($this->client, $this->people['Lena']);
        $ids = [
            'malformed' => 'not-a-uuid',
            'a UUIDv4' => '0197a3c0-0000-4000-8000-0000000b0001',
            'unknown' => '0197a3c0-0000-7000-8000-0000000b0099',
            'too long' => str_repeat('a', 5000),
        ];

        foreach ($ids as $case => $id) {
            $this->client->request('GET', self::LIST.'/'.$id);
            self::assertResponseStatusCodeSame(404, $case);
            self::assertStringContainsString(NotebookRefused::NOT_FOUND, (string) $this->client->getResponse()->getContent(), $case);
            foreach (['/rename', '/delete'] as $action) {
                $this->client->request('POST', self::LIST.'/'.$id.$action, [...$this->formFields($id), NotebookController::TITLE_FIELD => 'Renamed']);
                self::assertResponseStatusCodeSame(404, $case.$action);
            }
        }

        self::assertSame($before, $this->notebookRowOf(self::LENAS_NOTEBOOK));
    }

    #[Test]
    public function theOwnerRenamesTheNotebookAndItCountsAsAUse(): void
    {
        $this->addNotebook($this->acmeFoods, 'Lena', self::LENAS_NOTEBOOK, self::TITLE, self::USED_AT);
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', self::LIST.'/'.self::LENAS_NOTEBOOK.'/rename', [...$this->formFields(self::LENAS_NOTEBOOK), NotebookController::TITLE_FIELD => ' Coupon field answers ']);

        self::assertResponseStatusCodeSame(303);
        self::assertSame(self::LIST.'/'.self::LENAS_NOTEBOOK, $this->client->getResponse()->headers->get('Location'));
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(NotebookController::RENAMED, $page->filter('.toast-region [data-toast-message-value]')->attr('data-toast-message-value'));
        self::assertSame('Coupon field answers', $page->filter('h1')->text());
        self::assertSame(['title' => 'Coupon field answers', 'version' => 2, 'last_used_at' => '2026-11-06 12:00:00.000000'], $this->notebookRowOf(self::LENAS_NOTEBOOK));
    }

    /**
     * Each refusal shows the rule's own words over the form and saves nothing. The form keeps a typed title it can show
     * as typed; one with a NUL or invalid UTF-8 shows the saved title instead.
     */
    #[Test]
    public function aRefusedRenameShowsTheReasonAndChangesNothing(): void
    {
        $this->addNotebook($this->acmeFoods, 'Lena', self::LENAS_NOTEBOOK, self::TITLE, self::USED_AT);
        $before = $this->notebookRowOf(self::LENAS_NOTEBOOK);
        $this->logIn($this->client, $this->people['Lena']);
        $tooLong = str_repeat('t', 121);

        $refusals = [
            'over the cap' => [$tooLong, NotebookRuleBroken::TITLE_TOO_LONG, $tooLong],
            'blank' => ['   ', NotebookRuleBroken::TITLE_BLANK, '   '],
            'a NUL character' => ["Coupon\0notes", NotebookRuleBroken::UNSAVABLE_TEXT, self::TITLE],
            'invalid UTF-8' => ["Coupon \xC3\x28 notes", NotebookRuleBroken::UNSAVABLE_TEXT, self::TITLE],
        ];
        foreach ($refusals as $case => [$title, $reason, $shownTitle]) {
            $page = $this->client->request('POST', self::LIST.'/'.self::LENAS_NOTEBOOK.'/rename', [...$this->formFields(self::LENAS_NOTEBOOK), NotebookController::TITLE_FIELD => $title]);
            self::assertResponseStatusCodeSame(422, $case);
            self::assertSame($reason, $page->filter('#notebook-error')->text(), $case);
            self::assertSame($shownTitle, $page->filter('input[name=title]')->attr('value'), $case);
            self::assertSame(self::TITLE, $page->filter('h1')->text(), $case);
            self::assertSame($before, $this->notebookRowOf(self::LENAS_NOTEBOOK), $case);
        }
    }

    #[Test]
    public function aWriteWithoutAValidFormTokenChangesNothing(): void
    {
        $this->addNotebook($this->acmeFoods, 'Lena', self::LENAS_NOTEBOOK, self::TITLE, self::USED_AT);
        $before = $this->notebookRowOf(self::LENAS_NOTEBOOK);
        $this->logIn($this->client, $this->people['Lena']);
        $otherNotebooksToken = $this->csrfTokenFor($this->client, NotebookController::csrfIdOf(self::LENAS_SECOND_NOTEBOOK));

        foreach (['expired' => 'expired', 'missing' => null, "another notebook's" => $otherNotebooksToken] as $case => $token) {
            $fields = null === $token ? [] : [NotebookController::CSRF_FIELD => $token];

            $page = $this->client->request('POST', self::LIST.'/'.self::LENAS_NOTEBOOK.'/rename', [...$fields, NotebookController::TITLE_FIELD => 'From an expired form']);
            self::assertResponseStatusCodeSame(422, $case);
            self::assertSame(RefusedWithToast::FORM_EXPIRED, $page->filter('#notebook-error')->text(), $case);

            $this->client->request('POST', self::LIST.'/'.self::LENAS_NOTEBOOK.'/delete', $fields);
            self::assertResponseStatusCodeSame(303, $case);
            self::assertSame(self::LIST.'/'.self::LENAS_NOTEBOOK, $this->client->getResponse()->headers->get('Location'), $case);
            $page = $this->client->followRedirect();
            self::assertResponseStatusCodeSame(200, $case);
            self::assertSame(RefusedWithToast::FORM_EXPIRED, $page->filter('.toast-region [data-toast-message-value]')->attr('data-toast-message-value'), $case);

            self::assertSame($before, $this->notebookRowOf(self::LENAS_NOTEBOOK), $case);
        }

        $this->client->request('POST', self::LIST.'/'.self::LENAS_NOTEBOOK.'/delete', [NotebookController::CSRF_FIELD => 'expired'], server: ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html']);
        self::assertResponseStatusCodeSame(422, 'A Turbo form gets the toast alone');
        self::assertStringContainsString(RefusedWithToast::FORM_EXPIRED, (string) $this->client->getResponse()->getContent());
        self::assertSame($before, $this->notebookRowOf(self::LENAS_NOTEBOOK));
    }

    #[Test]
    public function theOwnerDeletesTheNotebookAndASecondDeleteIsNotFound(): void
    {
        $this->addNotebook($this->acmeFoods, 'Lena', self::LENAS_NOTEBOOK, self::TITLE, self::USED_AT);
        $this->addNotebook($this->acmeFoods, 'Lena', self::LENAS_SECOND_NOTEBOOK, 'Kept notebook', self::USED_AT);
        $this->logIn($this->client, $this->people['Lena']);
        $fields = $this->formFields(self::LENAS_NOTEBOOK);

        $this->client->request('POST', self::LIST.'/'.self::LENAS_NOTEBOOK.'/delete', $fields);

        self::assertResponseStatusCodeSame(303);
        self::assertSame(self::LIST, $this->client->getResponse()->headers->get('Location'));
        $list = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(NotebookController::DELETED, $list->filter('.toast-region [data-toast-message-value]')->attr('data-toast-message-value'));
        self::assertSame(['Kept notebook'], self::cardTitlesOf($list));
        self::assertSame([self::LENAS_SECOND_NOTEBOOK], $this->notebookIds());

        $this->client->request('POST', self::LIST.'/'.self::LENAS_NOTEBOOK.'/delete', $fields);
        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString(NotebookRefused::NOT_FOUND, (string) $this->client->getResponse()->getContent());
        self::assertSame([self::LENAS_SECOND_NOTEBOOK], $this->notebookIds());
    }

    #[Test]
    public function theListRunsAsManyQueriesForTwentyNotebooksAsForOne(): void
    {
        $this->addNotebook($this->acmeFoods, 'Lena', \sprintf(self::LENAS_PAGED_NOTEBOOK, 1), 'Notebook 1', self::USED_AT, [self::screenshotOf($this->addMedia('ready', 'Lena'))], [self::scenario('First')]);
        $this->logIn($this->client, $this->people['Lena']);
        // logIn() leaves the user in the entity manager, so the first request skips the user refresh every later one pays.
        $this->queriesOfTheList(1);
        $oneNotebookQueries = $this->queriesOfTheList(1);

        for ($number = 2; $number <= 20; ++$number) {
            $this->addNotebook($this->acmeFoods, 'Lena', \sprintf(self::LENAS_PAGED_NOTEBOOK, $number), 'Notebook '.$number, self::USED_AT, [self::screenshotOf($this->addMedia('ready', 'Lena'))], [self::scenario('First')]);
        }
        $twentyNotebookQueries = $this->queriesOfTheList(20);

        self::assertCount(\count($oneNotebookQueries), $twentyNotebookQueries);
        self::assertCount(1, array_filter($twentyNotebookQueries, static fn (string $sql): bool => str_contains($sql, 'rvw_notebook')), 'One query reads the page of notebooks.');
    }

    #[Test]
    public function theReportDraftsListLinksToMyNotebooks(): void
    {
        $this->logIn($this->client, $this->people['Lena']);

        $drafts = $this->client->request('GET', '/report-drafts');

        self::assertResponseStatusCodeSame(200);
        $link = $drafts->filter('main a')->reduce(static fn (Crawler $anchor): bool => 'Your notebooks' === trim($anchor->text()));
        self::assertSame([self::LIST], $link->each(static fn (Crawler $anchor): ?string => $anchor->attr('href')));
    }

    #[Test]
    public function aVisitorWhoIsNotLoggedInGoesToTheLoginPage(): void
    {
        $this->addNotebook($this->acmeFoods, 'Lena', self::LENAS_NOTEBOOK, self::TITLE, self::USED_AT);
        $before = $this->notebookRowOf(self::LENAS_NOTEBOOK);

        foreach ([['GET', self::LIST], ['GET', self::LIST.'/'.self::LENAS_NOTEBOOK], ['POST', self::LIST.'/'.self::LENAS_NOTEBOOK.'/delete']] as [$method, $uri]) {
            $this->client->request($method, $uri);
            self::assertResponseStatusCodeSame(302, $method.' '.$uri);
            self::assertStringEndsWith('/login', (string) $this->client->getResponse()->headers->get('Location'));
        }
        self::assertSame($before, $this->notebookRowOf(self::LENAS_NOTEBOOK));
    }

    #[Test]
    public function aPersonOfNoOrganizationIsSentToChooseOneFirst(): void
    {
        $this->logIn($this->client, $this->createUserWithoutOrganization('Nia'));

        $this->client->request('GET', self::LIST);

        self::assertResponseStatusCodeSame(302);
        self::assertSame('/org/choose', $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * @return list<string> the SQL of every query one load of the list ran
     */
    private function queriesOfTheList(int $expectedCardCount): array
    {
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();
        $page = $this->client->request('GET', self::LIST);
        self::assertResponseStatusCodeSame(200);
        self::assertCount($expectedCardCount, self::cardTitlesOf($page));

        $queries = [];
        foreach ($debugData->getData() as $queriesOfOneConnection) {
            foreach ($queriesOfOneConnection as $query) {
                $queries[] = \is_string($query['sql'] ?? null) ? $query['sql'] : '';
            }
        }

        return $queries;
    }

    /**
     * @return list<string>
     */
    private static function cardTitlesOf(Crawler $page): array
    {
        return $page->filter('.report-drafts__card h2')->each(static fn (Crawler $heading): string => $heading->text());
    }

    /**
     * @param list<array{media_id: string, title: ?string, summary: ?string, pins: list<array{x_pct: float, y_pct: float, note: string}>}> $screenshots
     * @param list<array<string, mixed>>                                                                                                   $scenarios
     */
    private function addNotebook(OrganizationId $organizationId, string $owner, string $notebookId, string $title, string $lastUsedAt, array $screenshots = [], array $scenarios = []): void
    {
        $parameters = [
            $organizationId->toString(), $notebookId, $this->people[$owner]->id()->toString(), $title,
            json_encode($screenshots, \JSON_THROW_ON_ERROR), json_encode($scenarios, \JSON_THROW_ON_ERROR), $lastUsedAt, $lastUsedAt,
        ];
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO rvw_notebook (organization_id, id, owner_id, title, screenshots, scenarios, version, created_at, updated_at, last_used_at)
             VALUES (?, ?, ?, ?, CAST(? AS jsonb), CAST(? AS jsonb), 1, '2026-11-01 08:00:00+00', ?, ?)",
            $parameters,
        ));
    }

    /**
     * @return array{media_id: string, title: null, summary: null, pins: array{}}
     */
    private static function screenshotOf(string $mediaId): array
    {
        return ['media_id' => $mediaId, 'title' => null, 'summary' => null, 'pins' => []];
    }

    /**
     * @return array<string, mixed> a scenario in scenario.v1.json's format
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

    /**
     * @return array<string, string>
     */
    private function formFields(string $notebookId): array
    {
        return [NotebookController::CSRF_FIELD => $this->csrfTokenFor($this->client, NotebookController::csrfIdOf($notebookId))];
    }

    /**
     * @return array<string, mixed> the title, version and last use, the last as PostgreSQL prints it in UTC
     */
    private function notebookRowOf(string $notebookId): array
    {
        $row = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($notebookId, &$row): int {
            $row = $migrator->fetchAssociative(
                "SELECT title, version, to_char(last_used_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') AS last_used_at FROM rvw_notebook WHERE id = ?",
                [$notebookId],
            );

            return 0;
        });
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return list<mixed> the ids of Acme Foods' notebooks
     */
    private function notebookIds(): array
    {
        $ids = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use (&$ids): int {
            $ids = $migrator->fetchFirstColumn('SELECT id FROM rvw_notebook ORDER BY id');

            return 0;
        });

        return $ids;
    }
}
