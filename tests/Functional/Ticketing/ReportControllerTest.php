<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ticketing;

use App\Identity\Domain\User;
use App\Review\UI\Component\RoundsSection;
use App\Review\UI\NewTicket\ScreenshotReportOnNewTicket;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use App\Tests\Integration\ReadsSentSql;
use App\Ticketing\Api\DraftChoice;
use App\Ticketing\Api\MoveResult;
use App\Ticketing\Api\ProblemReport;
use App\Ticketing\Api\ReportedTicket;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Application\CreateTicket\ProjectHasNoTeam;
use App\Ticketing\Application\CreateTicket\ProjectNotOfTeam;
use App\Ticketing\Application\ProjectNotFound;
use App\Ticketing\Application\UseCaseTicketCommands;
use App\Ticketing\Infrastructure\Scheduler\AddTicketEventPartitions;
use App\Ticketing\UI\ReportController;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * Report a problem at /report (P06.T1, report-a-problem.md). Lena leads Shop and Mobile; Omar is a Shop developer; Mia
 * is Shop's tester; Raj is a manager in no team; Sara an admin. Shop owns SHOP and WEB, Mobile owns APP; Sara is the
 * triager of tickets with no team. The clock is frozen on 6 Nov 2026.
 */
final class ReportControllerTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesCsrfTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }
    use ReadsSentSql;

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string REPORT = '/report';
    /** 40 characters: no "little detail" reason. */
    private const string ENOUGH_DETAIL = 'The cart adds the coupon twice at pay.!!';
    private const array TURBO_FRAME_HEADERS = ['HTTP_TURBO_FRAME' => RoundsSection::FRAME_ID];
    /**
     * The request's own eight (the user and their password row, their organizations, the transaction's start, tenant and
     * commit, the reporter's roles and team roles), then the triager for the visibility, the duplicate search and the
     * project's next key; never the form's choices (every team, their projects, "Give it to"'s ticket counts).
     */
    private const int WARM_PREVIEW_QUERY_COUNT = 11;

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;
    private string $shopProject;
    private string $appProject;
    private string $backlogStepId;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        $clock = new MockClock(new \DateTimeImmutable(self::NOW));
        self::getContainer()->set('clock', $clock);
        new AddTicketEventPartitions(self::getContainer()->get('doctrine.dbal.migrator_connection'), $clock)();
        $this->people = $this->createAcmeFoodsPeople('member');
        $organizationId = $this->acmeFoods->toString();
        $shopId = $this->shop->toString();
        $omarId = $this->people['Omar']->id()->toString();
        $miaId = $this->people['Mia']->id()->toString();
        $saraId = $this->people['Sara']->id()->toString();
        $backlogStepId = Uuid::v7()->toRfc4122();
        $this->backlogStepId = $backlogStepId;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, $shopId, $omarId, $miaId, $saraId, $backlogStepId): int {
            $migrator->executeStatement("UPDATE org_team_member SET job = 'developer' WHERE team_id = ? AND user_id = ?", [$shopId, $omarId]);
            $migrator->executeStatement("UPDATE org_team_member SET job = 'tester' WHERE team_id = ? AND user_id = ?", [$shopId, $miaId]);
            $migrator->executeStatement("INSERT INTO org_settings (organization_id, data, created_at) VALUES (?, jsonb_build_object('triager_id', ?::text), '2026-11-01 10:00:00+00')", [$organizationId, $saraId]);
            foreach ([[$backlogStepId, 'Backlog', 'backlog', 0], [Uuid::v7()->toRfc4122(), 'To do', 'todo', 1]] as [$stepId, $name, $coreCode, $position]) {
                $migrator->executeStatement("INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, ?, 'todo', ?, 'core', ?)", [$organizationId, $stepId, $name, $coreCode, $position]);
            }

            return 0;
        });
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop web');
        $this->addProject($this->shop, 'WEB', 'Website');
        $this->appProject = $this->addProject($this->mobile, 'APP', 'Mobile app');
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (['rvw_round', 'med_object', 'tkt_ticket_event', 'ins_ticket_turn', 'ins_project_count_ticket', 'ins_project_counts', 'tkt_ticket', 'wf_step', 'org_settings'] as $table) {
                    $migrator->executeStatement(\sprintf('DELETE FROM %s WHERE organization_id = ?', $table), [$organizationId]);
                }
                $migrator->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox' AND position(? in body) > 0", [$organizationId]);

                return 0;
            });
        }
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    /**
     * Lena, Shop's lead, left the team and nobody leads it now: the team chip names nobody rather than an empty
     * "( decides)".
     */
    #[Test]
    public function aTeamWithNoLeadIsOfferedAsThePlainTeam(): void
    {
        $parameters = [$this->shop->toString(), $this->people['Lena']->id()->toString()];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement('DELETE FROM org_team_member WHERE team_id = ? AND user_id = ?', $parameters));
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::REPORT);

        self::assertResponseStatusCodeSame(200);
        $shopGiveTo = $page->filter('[data-report-form-target="giveTo"][data-team="'.$this->shop->toString().'"]');
        self::assertSame(['The team', 'Omar (you)'], $shopGiveTo->filter('label')->each(static fn (Crawler $chip): string => trim($chip->text())));
    }

    #[Test]
    public function theFormShowsTheReportersTeamItsPeopleWithoutTestersAndItsProjects(): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::REPORT);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Report a problem', $page->filter('h1')->text());
        self::assertSame(['Shop', 'Mobile', 'Not sure'], $this->chipsOf($page, 'team_id'), 'The reporter\'s own team first.');
        self::assertSame('Shop', $this->checkedChipOf($page, 'team_id'));
        $shopGiveTo = $page->filter('[data-report-form-target="giveTo"][data-team="'.$this->shop->toString().'"]');
        self::assertCount(1, $shopGiveTo);
        self::assertNull($shopGiveTo->attr('hidden'));
        self::assertSame(['The team (Lena decides)', 'Lena', 'Omar (you)'], $shopGiveTo->filter('label')->each(static fn (Crawler $chip): string => trim($chip->text())), 'Mia is the tester, so she is not offered; Lena and Omar have no tickets, so by name.');
        self::assertSame(['SHOP', 'WEB'], $page->filter('[data-report-form-target="projects"][data-team="'.$this->shop->toString().'"] label')->each(static fn (Crawler $chip): string => trim($chip->text())));
        self::assertNotNull($page->filter('[data-report-form-target="projects"][data-team="none"]')->attr('hidden'), 'Not sure\'s projects stay hidden until it is picked.');
        self::assertSame(['Bug', 'Story', 'Task'], $this->chipsOf($page, 'type'));
        self::assertSame(['Critical', 'Major', 'Normal', 'Minor'], $this->chipsOf($page, 'priority'));
        self::assertSame(['Bug', 'Normal'], [$this->checkedChipOf($page, 'type'), $this->checkedChipOf($page, 'priority')]);
        self::assertSame('Capture with pinround', $page->filter('.screenshot-report__capture summary')->text());
        self::assertSame('Report a problem', $page->filter('header a.btn-tonal')->text());
    }

    /**
     * ADR-0038: Report a problem is open to every role. Raj is in no team, so the form starts on "Not sure", with no
     * "Give it to".
     */
    #[Test]
    public function aManagerInNoTeamReportsWithNotSureByDefault(): void
    {
        $this->logIn($this->client, $this->people['Raj']);

        $page = $this->client->request('GET', self::REPORT);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Not sure', $this->checkedChipOf($page, 'team_id'));
        self::assertCount(0, $page->filter('[data-report-form-target="giveTo"]'));
        self::assertNull($page->filter('[data-report-form-target="projects"][data-team="none"]')->attr('hidden'));
    }

    #[Test]
    public function aReportWithAScreenshotAndTwoPinsBecomesATicketWithRoundZeroAndNeedsNoCheck(): void
    {
        $picture = $this->addReadyPicture('Mia');
        $this->logIn($this->client, $this->people['Mia']);

        $this->postReport([
            'screenshot_media_id' => $picture,
            'screenshot_title' => 'Case B',
            'pins' => [1 => ['x' => '10.5', 'y' => '20', 'note' => 'Total'], 2 => ['x' => '60', 'y' => '70', 'note' => 'Coupon']],
        ]);

        self::assertSame(\sprintf(ReportController::CREATED_UNCHECKED, 'SHOP-1'), $this->toastAfterTheRedirect('/t/SHOP-1'));
        self::assertSame(['Total is counted twice', $this->shop->toString(), 'all', '{}', null, 'backlog', $this->people['Mia']->id()->toString()], $this->ticketRow('SHOP-1'));
        $frame = $this->client->request('GET', '/t/SHOP-1/rounds', [], [], self::TURBO_FRAME_HEADERS);
        self::assertSame(['Round 0 · Report · by Mia · 2 pins'], $frame->filter('.round-card__headline')->each(static fn (Crawler $headline): string => $headline->text()));
    }

    #[Test]
    public function aPickedPersonGetsItInToDo(): void
    {
        $this->logIn($this->client, $this->people['Lena']);

        $this->postReport(['assignee_id' => $this->people['Omar']->id()->toString()]);

        self::assertSame(\sprintf(ReportController::CREATED_UNCHECKED, 'SHOP-1'), $this->toastAfterTheRedirect('/t/SHOP-1'));
        self::assertSame([$this->people['Omar']->id()->toString(), 'todo'], \array_slice($this->ticketRow('SHOP-1'), 4, 2));
        self::assertSame([$this->people['Omar']->id()->toString(), 'build', null], $this->turnRow('SHOP-1'), 'No check reason, so no checker.');
    }

    #[Test]
    public function notSureMakesATicketWithNoTeamThatTheTriagerChecks(): void
    {
        $this->logIn($this->client, $this->people['Raj']);

        $this->postReport(['team_id' => 'none', 'project_id' => $this->appProject]);

        self::assertSame(\sprintf(ReportController::CREATED_CHECKED, 'APP-1', 'Sara'), $this->toastAfterTheRedirect('/t/APP-1'));
        self::assertSame([null, 'all', '{team}', null, 'backlog'], \array_slice($this->ticketRow('APP-1'), 1, 5));
        self::assertSame([$this->people['Sara']->id()->toString(), 'check', $this->people['Sara']->id()->toString()], $this->turnRow('APP-1'), 'The triager takes the quick look (turn rule 2).');
    }

    #[Test]
    public function aSecurityWordMakesItTeamOnlyAndRiskyAndShortDetailWithoutPinsIsLittleInfo(): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $this->postReport(['title' => 'Password shown in the address bar', 'description' => 'Short.']);

        self::assertSame(\sprintf(ReportController::CREATED_CHECKED, 'SHOP-1', 'Lena'), $this->toastAfterTheRedirect('/t/SHOP-1'));
        self::assertSame(['team', '{info,risk}'], \array_slice($this->ticketRow('SHOP-1'), 2, 2));
        self::assertSame([$this->people['Lena']->id()->toString(), 'check', $this->people['Lena']->id()->toString()], $this->turnRow('SHOP-1'), 'The team\'s lead takes the quick look.');
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function previewSentences(): iterable
    {
        yield 'the team decides, nothing to check' => ['Omar', [], 'It becomes ticket SHOP-1 right away. Lena plans it like any other ticket. Nobody needs to check it.'];
        yield 'a person picked' => ['Lena', ['assignee_id' => 'Omar'], 'It becomes ticket SHOP-1 right away and goes straight to Omar. Nobody needs to check it.'];
        yield 'the reporter picks themselves' => ['Omar', ['assignee_id' => 'Omar'], 'It becomes ticket SHOP-1 right away and goes straight to you. Nobody needs to check it.'];
        yield 'little detail, the lead checks' => ['Omar', ['description' => 'Short.'], 'It becomes ticket SHOP-1 right away. Lena takes a quick look, because little detail and no pins.'];
        yield 'the lead reports with little detail and checks it' => ['Lena', ['description' => 'Short.', 'assignee_id' => 'Omar'], 'It becomes ticket SHOP-1 right away. You take a quick look, because little detail and no pins. Omar can start already.'];
        yield 'Not sure and a security word' => ['Raj', ['team_id' => 'none', 'project_id' => 'APP', 'title' => 'Password shown in the address bar'], 'It becomes ticket APP-1 right away. Sara takes a quick look, because no team was picked, it sounds risky. It mentions security, so only the team and admins can see it.'];
        yield 'a tester is not offered, so the team decides' => ['Omar', ['assignee_id' => 'Mia'], 'It becomes ticket SHOP-1 right away. Lena plans it like any other ticket. Nobody needs to check it.'];
        yield 'another team, whose people are not offered' => ['Omar', ['team_id' => 'MOBILE', 'project_id' => 'APP', 'assignee_id' => 'Lena'], 'It becomes ticket APP-1 right away. Lena plans it like any other ticket. Nobody needs to check it.'];
        yield 'a project of another team says nothing yet' => ['Omar', ['project_id' => 'APP'], ''];
        yield 'Not sure with a project no team owns says nothing yet' => ['Raj', ['team_id' => 'none', 'project_id' => 'LOOSE'], ''];
        yield 'an unknown project says nothing yet' => ['Omar', ['project_id' => '0197a3c0-0000-7000-8000-00000000dead'], ''];
    }

    /**
     * @param array<string, mixed> $overrides as refusedReports() reads them
     */
    #[Test]
    #[DataProvider('previewSentences')]
    public function thePreviewSaysWhatTheCreateWillDo(string $person, array $overrides, string $sentence): void
    {
        $this->logIn($this->client, $this->people[$person]);

        $this->client->request('POST', '/report/preview', $this->reportFields($this->resolved($overrides)));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['duplicates' => '', 'preview' => $sentence], $this->jsonAnswer());
    }

    #[Test]
    public function aWarmPreviewReadsOnlyThePostedProjectAndTheSearchNotTheFormsChoices(): void
    {
        $this->logIn($this->client, $this->people['Lena']);
        $fields = $this->reportFields($this->resolved(['assignee_id' => 'Omar']));
        // The first request fills the team people cache and skips the user refresh every later request pays.
        $this->client->request('POST', '/report/preview', $fields);
        self::assertResponseStatusCodeSame(200);
        $this->debugData()->reset();

        $this->client->request('POST', '/report/preview', $fields);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('It becomes ticket SHOP-1 right away and goes straight to Omar. Nobody needs to check it.', $this->answerText('preview'));
        $queries = self::sqlOf($this->debugData());
        $allQueries = implode("\n", $queries);
        self::assertSame(
            [0, 1, 1],
            [self::countMatching('/FROM org_team WHERE|FROM tkt_ticket\b|FROM idn_user WHERE id = ANY/', $queries), self::countMatching('/FROM org_team_member\b/', $queries), self::countMatching('/FROM tkt_project\b/', $queries)],
            'No team list, no ticket counts and no names; the reporter\'s roles and the project once: '.$allQueries,
        );
        self::assertCount(self::WARM_PREVIEW_QUERY_COUNT, $queries, $allQueries);
    }

    /**
     * @param list<string> $queries
     */
    private static function countMatching(string $pattern, array $queries): int
    {
        return \count(array_filter($queries, static fn (string $sql): bool => 1 === preg_match($pattern, $sql)));
    }

    /**
     * FR-INT-15: three shared words that are at least 30 % of the old title. NFR-SEC-13: a team-only Mobile ticket is
     * never shown to Omar, who is not in Mobile, while Lena, who leads it, sees it.
     */
    #[Test]
    public function aSimilarOpenTicketIsSuggestedOnlyToWhoMaySeeItAndMakesTheReportADupe(): void
    {
        $this->addOpenTicket($this->shopProject, 'SHOP-41', 'Cart total counted twice at checkout', 'all', $this->shop->toString());
        $this->addOpenTicket($this->appProject, 'APP-7', 'Cart total counted twice in the app', 'team', $this->mobile->toString());
        $this->addOpenTicket($this->shopProject, 'SHOP-42', 'Cart colours look wrong', 'all', $this->shop->toString());
        $fields = ['title' => 'Cart total counted twice'];

        $this->logIn($this->client, $this->people['Omar']);
        $this->client->request('POST', '/report/preview', $this->reportFields($fields));
        self::assertResponseStatusCodeSame(200);
        $omarSees = new Crawler($this->answerText('duplicates'));
        self::assertSame(['SHOP-41'], $omarSees->filter('.report-duplicates__key')->each(static fn (Crawler $key): string => $key->text()));
        self::assertSame('Maybe already reported (found by matching words)', $omarSees->filter('h2')->text());
        self::assertStringContainsString('Lena takes a quick look, because maybe the same as an older ticket.', $this->answerText('preview'));

        $this->logIn($this->client, $this->people['Lena']);
        $this->client->request('POST', '/report/preview', $this->reportFields($fields));
        // Both share four words; the search ranks the closer title first on the tie.
        self::assertSame(['APP-7', 'SHOP-41'], new Crawler($this->answerText('duplicates'))->filter('.report-duplicates__key')->each(static fn (Crawler $key): string => $key->text()));

        $this->logIn($this->client, $this->people['Omar']);
        $this->postReport($fields);
        self::assertSame(\sprintf(ReportController::CREATED_CHECKED, 'SHOP-43', 'Lena'), $this->toastAfterTheRedirect('/t/SHOP-43'));
        self::assertSame('{dupe}', $this->ticketRow('SHOP-43')[3]);
    }

    /**
     * The page itself, not the live answer, shows the duplicates box, with the old title as text, not markup.
     */
    #[Test]
    public function aRefusedReportPageShowsTheSimilarTicketsWithTheirTitlesEscaped(): void
    {
        $this->addOpenTicket($this->shopProject, 'SHOP-41', 'Cart total counted twice <b>at</b> checkout', 'all', $this->shop->toString());
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->postReport(['title' => 'Cart total counted twice', ...$this->resolved(['assignee_id' => 'Mia'])]);

        self::assertResponseStatusCodeSame(422);
        $box = $page->filter('[data-report-form-target="duplicates"]');
        self::assertSame(['SHOP-41'], $box->filter('.report-duplicates__key')->each(static fn (Crawler $key): string => $key->text()));
        self::assertSame('Cart total counted twice <b>at</b> checkout', $box->filter('.report-duplicates__item a')->text());
        self::assertCount(0, $box->filter('b'));
        self::assertSame(1, $this->ticketCount(), 'Only SHOP-41.');
    }

    #[Test]
    public function aShortTitleGetsNoSuggestionsAndNobodyOutsideTheOrganizationGetsAPreview(): void
    {
        $this->addOpenTicket($this->shopProject, 'SHOP-41', 'Cart total counted twice at checkout', 'all', $this->shop->toString());
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', '/report/preview', $this->reportFields(['title' => 'Cart to']));

        self::assertResponseStatusCodeSame(200);
        self::assertSame('', $this->jsonAnswer()['duplicates'] ?? null, 'Under 8 characters.');
        $this->client->getCookieJar()->clear();
        $this->client->request('POST', '/report/preview', $this->reportFields(['title' => 'Cart total counted twice']));
        self::assertResponseRedirects();
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function refusedReports(): iterable
    {
        yield 'no title' => [['title' => '   '], 'title', ReportController::TITLE_MISSING];
        yield 'a project of another team' => [['project_id' => 'APP'], 'project', ReportController::PROJECT_UNKNOWN];
        yield 'a tester, who is not offered' => [['assignee_id' => 'Mia'], 'assignee', ReportController::ASSIGNEE_UNKNOWN];
        yield 'a person for Not sure' => [['team_id' => 'none', 'project_id' => 'APP', 'assignee_id' => 'Omar'], 'assignee', ReportController::ASSIGNEE_UNKNOWN];
        yield 'a team that is not offered' => [['team_id' => '0197a3c0-0000-7000-8000-00000000dead'], 'team', ReportController::TEAM_UNKNOWN];
        yield 'a picture summary of 601 characters' => [['screenshot_media_id' => 'ready', 'screenshot_summary' => str_repeat('s', 601)], 'screenshot', 'A picture summary holds at most 600 characters.'];
    }

    /**
     * @param array<string, mixed> $overrides "APP" stands for the APP project's id, a first name for that person's id,
     *                                        "ready" for a ready picture of Omar's
     */
    #[Test]
    #[DataProvider('refusedReports')]
    public function aRefusedReportShowsWhyKeepsTheTextAndMakesNoTicket(array $overrides, string $field, string $message): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->postReport(['description' => 'Kept as typed', ...$this->resolved($overrides)]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($message, $page->filter('#report-'.$field.'-error, #screenshot-error')->text());
        self::assertSame('Kept as typed', $page->filter('textarea[name="description"]')->text());
        self::assertSame(0, $this->ticketCount());
    }

    /**
     * P14.T10: the picture's title and summary sit in the ScreenshotEditor's picture area, which stays hidden until there
     * is a picture; the fields are there all the same, so the form posts them.
     */
    #[Test]
    public function withoutAPictureThePictureAreaIsHiddenAndHoldsTheTitleAndSummary(): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::REPORT);

        self::assertResponseStatusCodeSame(200);
        $pictureArea = $page->filter('.screenshot-report__picture');
        self::assertCount(1, $pictureArea);
        self::assertSame('', $pictureArea->attr('hidden'));
        self::assertCount(1, $pictureArea->filter('#screenshot-editor input[name="screenshot_title"]'));
        self::assertCount(1, $pictureArea->filter('#screenshot-editor textarea[name="screenshot_summary"]'));
        self::assertCount(1, $page->filter('[name="screenshot_title"]'), 'No second title field outside the picture area.');
        self::assertCount(1, $page->filter('[name="screenshot_summary"]'), 'No second summary field outside the picture area.');
        self::assertNull($page->filter('.screenshot-report__empty')->attr('hidden'));
        self::assertCount(0, $page->filter('.screenshot-report__remove'), 'The editor\'s Remove screenshot is the only one.');
    }

    /**
     * @return iterable<string, array{array<string, string>, string, string}>
     */
    public static function pictureTextWithoutAPicture(): iterable
    {
        yield 'a title' => [['screenshot_title' => 'Cart page', 'screenshot_summary' => ''], 'Cart page', ''];
        yield 'a summary' => [['screenshot_title' => '', 'screenshot_summary' => 'The total doubles'], '', 'The total doubles'];
        yield 'a title for a picture that is not the reporter\'s' => [['screenshot_media_id' => '0197a3c0-0000-7000-8000-00000000beef', 'screenshot_title' => 'Cart page', 'screenshot_summary' => ''], 'Cart page', ''];
    }

    /**
     * The editor's Remove screenshot empties the title and summary, but a page without script, or a picture the server
     * does not keep, posts them without one: the refusal asks to clear them, so the picture area shows them again.
     *
     * @param array<string, string> $overrides
     */
    #[Test]
    #[DataProvider('pictureTextWithoutAPicture')]
    public function aPictureTitleOrSummaryWithoutAPictureIsRefusedInAPictureAreaThatShows(array $overrides, string $title, string $summary): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->postReport($overrides);

        self::assertResponseStatusCodeSame(422);
        $pictureArea = $page->filter('.screenshot-report__picture');
        self::assertCount(1, $pictureArea);
        self::assertNull($pictureArea->attr('hidden'));
        self::assertSame($title, $pictureArea->filter('#screenshot-editor input[name="screenshot_title"]')->attr('value'));
        self::assertSame($summary, $pictureArea->filter('#screenshot-editor textarea[name="screenshot_summary"]')->text());
        self::assertCount(0, $pictureArea->filter('.screenshot__placeholder'), 'There is no picture to be processing.');
        self::assertSame('', $page->filter('input[name="screenshot_media_id"]')->attr('value'));
        self::assertNull($page->filter('.screenshot-report__empty')->attr('hidden'), 'The way to add a picture still shows.');
        self::assertSame(0, $this->ticketCount());
        self::assertSame(0, $this->roundCount());
    }

    #[Test]
    public function aPictureTitleOrSummaryWithoutAPictureIsRefusedWithWhatToDo(): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->postReport(['screenshot_title' => 'Cart page', 'screenshot_summary' => 'The total doubles']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(ScreenshotReportOnNewTicket::CONTENT_WITHOUT_SCREENSHOT, $page->filter('#screenshot-error')->text());
        self::assertSame('screenshot-error', $page->filter('#screenshot-editor input[name="screenshot_title"]')->attr('aria-describedby'));
        self::assertSame(0, $this->ticketCount());
        self::assertSame(0, $this->roundCount());
    }

    /**
     * Blank picture text is no content, as the server reads it: a report refused for another field keeps the area hidden.
     */
    #[Test]
    public function aBlankPictureTitleAndSummaryKeepThePictureAreaHidden(): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->postReport(['title' => '   ', 'screenshot_title' => '  ', 'screenshot_summary' => "\n "]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(ReportController::TITLE_MISSING, $page->filter('#report-title-error')->text());
        self::assertSame('', $page->filter('.screenshot-report__picture')->attr('hidden'));
        self::assertSame(0, $this->ticketCount());
    }

    /**
     * A refused report with a ready picture comes back with the picture in the editor, its title as typed and the
     * editor's one thumbnail showing the same picture.
     */
    #[Test]
    public function aPostedReadyPictureShowsInTheScreenshotEditor(): void
    {
        $picture = $this->addReadyPicture('Omar');
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->postReport(['title' => '   ', 'screenshot_media_id' => $picture, 'screenshot_title' => 'Cart page']);

        self::assertResponseStatusCodeSame(422);
        $pictureArea = $page->filter('.screenshot-report__picture');
        self::assertCount(1, $pictureArea);
        self::assertNull($pictureArea->attr('hidden'));
        $editor = $pictureArea->filter('#screenshot-editor');
        self::assertCount(1, $editor);
        self::assertSame(['screenshot-editor-panel-1'], $editor->filter('[role="tabpanel"]')->each(static fn (Crawler $panel): string => (string) $panel->attr('id')));
        $imageUrl = $editor->filter('.screenshot__image')->attr('src');
        self::assertIsString($imageUrl);
        self::assertStringContainsString($picture, $imageUrl);
        self::assertSame($imageUrl, $editor->filter('[role="tab"] .screenshot-editor__thumb-image')->attr('src'));
        self::assertSame('Cart page', $editor->filter('input[name="screenshot_title"]')->attr('value'));
        self::assertNull($editor->filter('input[name="screenshot_title"]')->attr('aria-describedby'), 'The refusal is the title\'s, not the picture\'s.');
        self::assertSame($picture, $page->filter('input[name="screenshot_media_id"]')->attr('value'));
        self::assertNotNull($page->filter('.screenshot-report__empty')->attr('hidden'));
        self::assertSame(0, $this->ticketCount());
    }

    #[Test]
    public function moreDetailStartsAtFiveLinesAndTheEditorStylesFollowTheReviewStyles(): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::REPORT);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('5', $page->filter('textarea[name="description"]')->attr('rows'));
        $stylesheets = $page->filter('link[rel=stylesheet]')->each(static fn (Crawler $link): string => (string) $link->attr('href'));
        $review = self::positionOf('#/styles/review[-.]#', $stylesheets);
        $editor = self::positionOf('#/styles/screenshot-editor[-.]#', $stylesheets);
        self::assertSame($review + 1, $editor, 'The editor\'s styles come right after review.css, which they build on.');
    }

    /**
     * A summary past its cap comes back as typed, not cut to the field's maxlength, and the error describes it.
     */
    #[Test]
    public function aTooLongPictureSummaryIsKeptAsTypedAndDescribedByTheError(): void
    {
        $tooLong = str_repeat('Acme Foods ', 54).'SHOP-1x';
        self::assertSame(601, mb_strlen($tooLong));
        $picture = $this->addReadyPicture('Omar');
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->postReport(['screenshot_media_id' => $picture, 'screenshot_summary' => $tooLong]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('A picture summary holds at most 600 characters.', $page->filter('#screenshot-error')->text());
        $summary = $page->filter('#screenshot-editor textarea[name="screenshot_summary"]');
        self::assertSame($tooLong, $summary->text());
        self::assertSame('screenshot-error', $summary->attr('aria-describedby'));
        self::assertSame('screenshot-error', $page->filter('#screenshot-editor input[name="screenshot_title"]')->attr('aria-describedby'));
        self::assertSame(0, $this->ticketCount());
        self::assertSame(0, $this->roundCount());
    }

    /**
     * @return iterable<string, array{\DomainException}>
     */
    public static function projectRefusalsAfterTheChoicesWereRead(): iterable
    {
        yield 'the project moved to another team' => [new ProjectNotOfTeam('That project is not one of the team\'s.')];
        yield 'the project lost its team' => [new ProjectHasNoTeam('That project has no team, so it takes no tickets.')];
        yield 'the project was removed' => [new ProjectNotFound('That project does not exist.')];
    }

    #[Test]
    #[DataProvider('projectRefusalsAfterTheChoicesWereRead')]
    public function aProjectThatChangesBeforeTheSaveIsRefusedOnTheProjectField(\DomainException $refusal): void
    {
        self::getContainer()->set(UseCaseTicketCommands::class, new readonly class($refusal) implements TicketCommands {
            public function __construct(private \DomainException $refusal)
            {
            }

            public function report(ProblemReport $report): ReportedTicket
            {
                throw $this->refusal;
            }

            public function create(OrganizationId $organizationId, UserId $reporter, string $projectId, string $title, string $description, string $type, string $priority, ?UserId $assignee, \DateTimeImmutable $reportedAt): string
            {
                throw new \LogicException('Report a problem creates no ticket through create.');
            }

            public function move(OrganizationId $organizationId, UserId $actor, string $key, string $verb, int $expectedVersion, \DateTimeImmutable $at, ?DraftChoice $draftChoice = DraftChoice::Keep): MoveResult
            {
                throw new \LogicException('Report a problem moves no ticket.');
            }

            public function giveTo(OrganizationId $organizationId, UserId $actor, string $key, UserId $assignee, int $expectedVersion, \DateTimeImmutable $at): int
            {
                throw new \LogicException('Report a problem gives no ticket.');
            }

            public function moveTo(OrganizationId $organizationId, UserId $actor, string $key, string $stepId, int $expectedVersion, ?UserId $waitingOnUserId, ?string $waitingOnLabel, \DateTimeImmutable $at, ?DraftChoice $draftChoice = DraftChoice::Keep): MoveResult
            {
                throw new \LogicException('Report a problem moves no ticket.');
            }
        });
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->postReport(['description' => 'Kept as typed']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(ReportController::PROJECT_UNKNOWN, $page->filter('#report-project-error')->text());
        self::assertSame('Kept as typed', $page->filter('textarea[name="description"]')->text());
        self::assertSame(0, $this->ticketCount());
    }

    /**
     * @param array<string, mixed> $overrides "APP" stands for the APP project's id, "MOBILE" for the Mobile team's id,
     *                                        "LOOSE" for a new project no team owns, a first name for that person's id,
     *                                        "ready" for a ready picture of Omar's
     *
     * @return array<string, mixed>
     */
    private function resolved(array $overrides): array
    {
        return array_map(fn (mixed $value): mixed => match (true) {
            'APP' === $value => $this->appProject,
            'MOBILE' === $value => $this->mobile->toString(),
            'LOOSE' === $value => $this->addProject(null, 'LOOSE', 'Loose ends'),
            'ready' === $value => $this->addReadyPicture('Omar'),
            \is_string($value) && isset($this->people[$value]) => $this->people[$value]->id()->toString(),
            default => $value,
        }, $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function postReport(array $overrides = []): Crawler
    {
        return $this->client->request('POST', self::REPORT, [
            ReportController::CSRF_FIELD => $this->csrfTokenFor($this->client, ReportController::CSRF_ID),
            ...$this->reportFields($overrides),
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function reportFields(array $overrides = []): array
    {
        return [
            'title' => 'Total is counted twice',
            'description' => self::ENOUGH_DETAIL,
            'team_id' => $this->shop->toString(),
            'project_id' => $this->shopProject,
            'assignee_id' => '',
            'type' => 'bug',
            'priority' => 'normal',
            'screenshot_media_id' => '',
            ...$overrides,
        ];
    }

    /**
     * @return array<mixed>
     */
    private function jsonAnswer(): array
    {
        $answer = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);

        return $answer;
    }

    private function answerText(string $key): string
    {
        $text = $this->jsonAnswer()[$key] ?? null;
        self::assertIsString($text);

        return $text;
    }

    private function addOpenTicket(string $projectId, string $key, string $title, string $visibility, string $teamId): void
    {
        [$projectKey, $number] = explode('-', $key);
        $parameters = [$this->acmeFoods->toString(), Uuid::v7()->toRfc4122(), $projectId, $teamId, (int) $number, $key, $title, $this->backlogStepId, $this->people['Lena']->id()->toString(), $visibility];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($parameters, $projectId, $number): int {
            $migrator->executeStatement(
                "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank, step_id, reporter_id, visibility, version, components, check_reasons, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, '', 'bug', 'normal', 3, ?, ?, ?, 1, '{}', '{}', '2026-11-05 10:00:00+00', '2026-11-05 10:00:00+00')",
                $parameters,
            );
            $migrator->executeStatement('UPDATE tkt_project SET next_number = GREATEST(next_number, ?) WHERE id = ?', [(int) $number + 1, $projectId]);

            return 0;
        });
    }

    private function toastAfterTheRedirect(string $location): string
    {
        self::assertResponseStatusCodeSame(302);
        self::assertSame($location, $this->client->getResponse()->headers->get('Location'));
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);

        return (string) $page->filter('.toast-region [data-toast-message-value]')->attr('data-toast-message-value');
    }

    /**
     * @return list<string>
     */
    private function chipsOf(Crawler $page, string $name): array
    {
        return $page->filter('input[name="'.$name.'"]')->each(static fn (Crawler $radio): string => trim($radio->closest('label')?->text() ?? ''));
    }

    private function checkedChipOf(Crawler $page, string $name): string
    {
        return trim($page->filter('input[name="'.$name.'"][checked]')->closest('label')?->text() ?? '');
    }

    /**
     * @return list<mixed> title, team id, visibility, check reasons, assignee id, the step's core code, reporter id
     */
    private function ticketRow(string $key): array
    {
        $row = null;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($key, &$row): int {
            $row = $migrator->fetchNumeric(
                'SELECT t.title, t.team_id, t.visibility, t.check_reasons::text, t.assignee_id, s.core_code, t.reporter_id FROM tkt_ticket t JOIN wf_step s ON s.organization_id = t.organization_id AND s.id = t.step_id WHERE t.key = ?',
                [$key],
            );

            return 0;
        });
        self::assertIsArray($row, 'no ticket '.$key);

        return $row;
    }

    /**
     * @return list<mixed> the turn user id, the kind and the check user id of the ticket's turn row
     */
    private function turnRow(string $key): array
    {
        $row = null;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($key, &$row): int {
            $row = $migrator->fetchNumeric(
                'SELECT turn.turn_user_id, turn.kind, turn.check_user_id FROM ins_ticket_turn turn JOIN tkt_ticket t ON t.organization_id = turn.organization_id AND t.id = turn.ticket_id WHERE t.key = ?',
                [$key],
            );

            return 0;
        });
        self::assertIsArray($row, 'no turn row for '.$key);

        return $row;
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

    private function roundCount(): int
    {
        $count = null;
        $organizationId = $this->acmeFoods->toString();
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$count): int {
            $count = $migrator->fetchOne('SELECT count(*) FROM rvw_round WHERE organization_id = ?', [$organizationId]);

            return 0;
        });
        self::assertIsInt($count);

        return $count;
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

    private function addReadyPicture(string $uploader): string
    {
        $mediaId = Uuid::v7()->toRfc4122();
        $parameters = [$this->acmeFoods->toString(), $mediaId, 'uploads/'.$mediaId, $this->people[$uploader]->id()->toString(), hash('sha256', $mediaId), json_encode(['view' => 'media/'.$mediaId.'/view.webp', 'thumb' => 'media/'.$mediaId.'/thumb.webp'], \JSON_THROW_ON_ERROR)];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO med_object (organization_id, id, storage_key, mime, bytes, uploaded_by, created_at, status, reject_reason, sha256, width, height, variants)
             VALUES (?, ?, ?, 'image/png', 2048, ?, '2026-11-05 08:00:00+00', 'ready', NULL, ?, 1280, 800, CAST(? AS jsonb))",
            $parameters,
        ));

        return $mediaId;
    }
}
