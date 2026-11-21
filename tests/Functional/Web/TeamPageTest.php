<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Identity\Domain\User;
use App\Organization\Api\SettingsQueries;
use App\Organization\Api\StepPolicy;
use App\Organization\Infrastructure\Cache\TeamPeopleCache;
use App\Organization\Infrastructure\Doctrine\DbalSettingsQueries;
use App\Organization\Infrastructure\Doctrine\StoredSettings;
use App\Organization\Infrastructure\Doctrine\StoredSettingsQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Fixtures\Tracing\ExportedSpans;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Workflow\Infrastructure\Cache\WorkflowCache;
use Doctrine\DBAL\Connection;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The team page, /teams/{slug}, of Acme Foods' team Shop (slug "shop"): who TeamPermission::VIEW lets in, that a
 * slug is looked up in the request's organization only, what the page lists and what it says to whom.
 */
final class TeamPageTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string LEAD_ONLY_NOTE = 'Only the team lead can change projects and steps.';

    private KernelBrowser $client;

    /** addTeamOnlyTicket() takes number 1. */
    private int $nextTicketNumber = 2;

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    protected function tearDown(): void
    {
        $this->removeCommittedTickets();
        $this->removeCommittedProjects();
        $this->removeCommittedWorkflow();
        $this->removeUsersAndOrganizations();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function peopleWhoMayViewShop(): iterable
    {
        yield 'Lena, lead of Shop with no org role' => ['Lena'];
        yield 'Omar, member of Shop with no org role' => ['Omar'];
        yield 'Mia, viewer of Shop with no org role' => ['Mia'];
        yield 'Raj, Manager with no team' => ['Raj'];
        yield 'Sara, Admin with no team' => ['Sara'];
    }

    #[Test]
    #[DataProvider('peopleWhoMayViewShop')]
    public function aPersonWithATeamRoleOrAnAdminOrManagerSeesTheTeamPage(string $person): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()[$person]);

        $page = $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Shop', $page->filter('main h1')->text());
    }

    #[Test]
    public function theLeadIsToldSheLeadsTheTeamAndGetsNoLeadOnlyNote(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);

        $page = $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('You lead this team. You link its projects and add its own steps.', $page->filter('main .team__subline')->text());
        self::assertStringNotContainsString('Only the team lead', $page->filter('main')->text());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function peopleWhoCanChangeNothingOnShop(): iterable
    {
        yield 'Omar, a member' => ['Omar'];
        yield 'Mia, a viewer' => ['Mia'];
        yield 'Raj, a Manager' => ['Raj'];
    }

    #[Test]
    #[DataProvider('peopleWhoCanChangeNothingOnShop')]
    public function everyoneWhoCanChangeNothingIsToldOnlyTheLeadCanChangeProjectsAndSteps(string $person): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()[$person]);

        $page = $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(self::LEAD_ONLY_NOTE, $page->filter('main .team__subline')->text());
        self::assertStringNotContainsString('You lead this team.', $page->filter('main')->text());
    }

    #[Test]
    public function anAdminWhoIsNotTheLeadIsToldOnlyTheLeadCanRemoveProjectsAndChangeSteps(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Sara']);

        $page = $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Only the team lead can remove projects and change steps.', $page->filter('main .team__subline')->text());
        self::assertStringNotContainsString('You lead this team.', $page->filter('main')->text());
    }

    #[Test]
    #[DataProvider('peopleWhoMayViewShop')]
    public function everyoneWhoSeesThePageSeesTheTeamsProjectsByKeyAndNoOtherTeamsProject(string $person): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()[$person]);
        $this->addProject($this->shop, 'SHOP', 'Web shop');
        $this->addProject($this->shop, 'CART', 'Checkout');
        $this->addProject($this->mobile, 'APP', 'Mobile app');

        $page = $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Projects 2', $page->filter('#team-projects')->text());
        $removeLabel = 'Lena' === $person ? ' Remove' : '';
        self::assertSame(
            ['CART Checkout See tickets of CART'.$removeLabel.' 0 open tickets', 'SHOP Web shop See tickets of SHOP'.$removeLabel.' 0 open tickets'],
            $page->filter('.team__project')->each(static fn ($project): string => $project->text()),
        );
        self::assertStringNotContainsString('Mobile app', $page->filter('main')->text());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function viewersOfOneTeamOnlyTicket(): iterable
    {
        yield 'Lena, lead of Shop, sees it' => ['Lena', '1 open ticket'];
        yield 'Raj, a Manager with no team role, does not' => ['Raj', '0 open tickets'];
    }

    #[Test]
    #[DataProvider('viewersOfOneTeamOnlyTicket')]
    public function theOpenTicketCountHoldsATeamOnlyTicketOnlyForThoseWhoMaySeeIt(string $person, string $openTickets): void
    {
        $people = $this->createAcmeFoodsPeople();
        $projectId = $this->addProject($this->shop, 'SHOP', 'Web shop');
        $this->addTeamOnlyTicket($projectId, $this->shop, $people['Omar']);
        $this->logIn($this->client, $people[$person]);

        $page = $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(200);
        self::assertSame($openTickets, $page->filter('.team__project-tickets')->text());
    }

    #[Test]
    public function seeTicketsOpensTheTicketsListOfThatProject(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Omar']);
        $this->addProject($this->shop, 'SHOP', 'Web shop');

        $page = $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('/tickets?project=SHOP', $page->filter('.team__project-link')->attr('href'));
    }

    #[Test]
    public function aTeamWithNoProjectsShowsAnEmptyStateAndTheNote(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Omar']);
        $this->addProject($this->mobile, 'APP', 'Mobile app');

        $page = $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Projects 0', $page->filter('#team-projects')->text());
        self::assertSame('No projects yet.', $page->filter('.team__column--main .empty-state')->text());
        self::assertCount(0, $page->filter('.team__project'));
    }

    #[Test]
    public function thePeopleSectionListsTheLeadAndMembersByLoadHighestFirstThenByNameAndLeavesTheViewerOut(): void
    {
        $people = $this->createAcmeFoodsPeople();
        $members = $this->addShopMembers('Zoe', 'Tom', 'Ben', 'Noor', 'Pia');
        $this->setShopJob($people['Lena'], 'developer');
        $this->setShopJob($people['Omar'], 'tester');
        $this->setShopJob($members['Ben'], 'developer');
        $projectId = $this->addProject($this->shop, 'SHOP', 'Web shop');
        foreach (['Lena' => 7, 'Omar' => 8, 'Ben' => 19, 'Noor' => 20, 'Tom' => 3, 'Zoe' => 3] as $name => $load) {
            $this->addTurnsWaitingOn($people[$name] ?? $members[$name], $load, $projectId, $this->shop, $people['Omar']);
        }
        $this->logIn($this->client, $people['Lena']);

        $page = $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('People 7 people, 60 open tickets waiting on them', $page->filter('#team-people')->text());
        self::assertSame(
            ['Noor 20 Too much', 'Ben 19 Busy', 'Omar 8 Busy', 'Lena (you) 7', 'Tom 3', 'Zoe 3', 'Pia 0'],
            $page->filter('.team__person-head')->each(static fn (Crawler $head): string => $head->text()),
        );
        self::assertSame(
            ['Member', 'Developer', 'Tester', 'Team lead · developer', 'Member', 'Member', 'Member'],
            $page->filter('.team__person-job')->each(static fn (Crawler $job): string => $job->text()),
        );
        self::assertSame(
            [['20', '20'], ['19', '20'], ['8', '20'], ['7', '20'], ['3', '20'], ['3', '20'], ['0', '20']],
            $page->filter('.team__meter')->each(static fn (Crawler $meter): array => [$meter->attr('value'), $meter->attr('max')]),
        );
        self::assertSame(
            'Counts are open tickets where it is this person’s turn. Busy from 8 tickets, too much from 20.',
            $page->filter('.team__people-note')->text(),
        );
        self::assertCount(0, $page->filter('.team__column--aside .empty-state'));
    }

    #[Test]
    public function aBarTurnsAmberAndReadsBusyAtEightNotSevenAndRedAndTooMuchAtTwentyNotNineteen(): void
    {
        $people = $this->createAcmeFoodsPeople();
        $members = $this->addShopMembers('Ben', 'Noor');
        $projectId = $this->addProject($this->shop, 'SHOP', 'Web shop');
        foreach (['Lena' => 7, 'Omar' => 8, 'Ben' => 19, 'Noor' => 20] as $name => $load) {
            $this->addTurnsWaitingOn($people[$name] ?? $members[$name], $load, $projectId, $this->shop, $people['Omar']);
        }
        $this->logIn($this->client, $people['Omar']);

        $page = $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [
                ['Noor 20', ['Too much'], 'team__meter team__meter--too-much'],
                ['Ben 19', ['Busy'], 'team__meter team__meter--busy'],
                ['Omar (you) 8', ['Busy'], 'team__meter team__meter--busy'],
                ['Lena 7', [], 'team__meter team__meter--normal'],
            ],
            $page->filter('.team__person')->each(static fn (Crawler $person): array => [
                $person->filter('.team__person-name')->text().' '.$person->filter('.team__person-load')->text(),
                $person->filter('.team__person-level')->each(static fn (Crawler $level): string => $level->text()),
                $person->filter('.team__meter')->attr('class'),
            ]),
        );
    }

    #[Test]
    public function sixPeopleShowAndShowAllNAndTheSearchHoldTheRestWhenTheTeamHasMore(): void
    {
        $people = $this->createAcmeFoodsPeople();
        $this->addShopMembers('Zoe', 'Tom', 'Ben', 'Noor', 'Pia');
        $this->logIn($this->client, $people['Lena']);

        $page = $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(200);
        $rows = $page->filter('.team__person');
        self::assertCount(7, $rows);
        self::assertSame(
            [false, false, false, false, false, false, true],
            $rows->each(static fn (Crawler $row): bool => null !== $row->attr('hidden')),
        );
        self::assertSame(
            ['ben', 'lena', 'noor', 'omar', 'pia', 'tom', 'zoe'],
            $rows->each(static fn (Crawler $row): ?string => $row->attr('data-search')),
        );
        $toggle = $page->filter('.team__people-toggle');
        self::assertSame('Show all 7', $toggle->text());
        self::assertSame('Show fewer', $toggle->attr('data-show-fewer-label'));
        self::assertSame('false', $toggle->attr('aria-expanded'));
        self::assertSame('Find a person', $page->filter('.team__people-search')->attr('aria-label'));
        self::assertSame('No one in this team matches.', $page->filter('.team__people-none')->text());
        self::assertSame('6', $page->filter('#team-people')->closest('section')?->attr('data-team-people-shown-at-first-value'));
    }

    #[Test]
    public function aTeamOfSixShowsEveryoneWithNoShowAllAndNoSearch(): void
    {
        $people = $this->createAcmeFoodsPeople();
        $this->addShopMembers('Ben', 'Noor', 'Pia', 'Tom');
        $this->logIn($this->client, $people['Lena']);

        $page = $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(200);
        $rows = $page->filter('.team__person');
        self::assertCount(6, $rows);
        self::assertSame([false, false, false, false, false, false], $rows->each(static fn (Crawler $row): bool => null !== $row->attr('hidden')));
        self::assertCount(0, $page->filter('.team__people-toggle'));
        self::assertCount(0, $page->filter('.team__people-search'));
    }

    #[Test]
    public function aTeamOfOneSaysPersonNotPeopleInThePeopleTitle(): void
    {
        $people = $this->createAcmeFoodsPeople();
        $this->logIn($this->client, $people['Lena']);

        $page = $this->client->request('GET', '/teams/mobile');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('People 1 person, No open tickets waiting on them', $page->filter('#team-people')->text());
    }

    #[Test]
    public function aTeamWithNoLoadDrawsEmptyBarsAgainstAMaximumOfOne(): void
    {
        $people = $this->createAcmeFoodsPeople();
        $this->logIn($this->client, $people['Omar']);

        $page = $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('People 2 people, No open tickets waiting on them', $page->filter('#team-people')->text());
        self::assertSame(
            [['Lena 0', '0', '1'], ['Omar (you) 0', '0', '1']],
            $page->filter('.team__person')->each(static fn (Crawler $person): array => [
                $person->filter('.team__person-head')->text(),
                $person->filter('.team__meter')->attr('value'),
                $person->filter('.team__meter')->attr('max'),
            ]),
        );
    }

    /**
     * @return iterable<string, array{string, string, array{string, string}, string}> the viewer, Omar's row, his bar's value and max, and the People title
     */
    public static function viewersOfOmarsLoad(): iterable
    {
        yield 'Lena, lead of Shop, counts the team-only ticket' => ['Lena', 'Omar 2', ['2', '2'], 'People 2 people, 2 open tickets waiting on them'];
        yield 'Raj, a Manager with no team role, does not' => ['Raj', 'Omar 1', ['1', '1'], 'People 2 people, 1 open ticket waiting on them'];
    }

    /**
     * @param array{string, string} $omarsMeter
     */
    #[Test]
    #[DataProvider('viewersOfOmarsLoad')]
    public function aBarAndThePeopleTitleCountOnlyTheTeamsTicketsTheViewerMaySee(string $viewer, string $omarsLoad, array $omarsMeter, string $peopleTitle): void
    {
        $people = $this->createAcmeFoodsPeople();
        $shopProjectId = $this->addProject($this->shop, 'SHOP', 'Web shop');
        $mobileProjectId = $this->addProject($this->mobile, 'APP', 'Mobile app');
        $this->addTurnsWaitingOn($people['Omar'], 1, $shopProjectId, $this->shop, $people['Omar']);
        $this->addTurnsWaitingOn($people['Omar'], 1, $shopProjectId, $this->shop, $people['Omar'], 'team');
        $this->addTurnsWaitingOn($people['Omar'], 1, $mobileProjectId, $this->mobile, $people['Omar']);
        $this->logIn($this->client, $people[$viewer]);

        $page = $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(200);
        self::assertSame([$omarsLoad, 'Lena 0'], array_map(
            static fn (string $head): string => str_replace(' (you)', '', $head),
            $page->filter('.team__person-head')->each(static fn (Crawler $head): string => $head->text()),
        ));
        self::assertSame($omarsMeter, [$page->filter('.team__meter')->first()->attr('value'), $page->filter('.team__meter')->first()->attr('max')]);
        self::assertSame($peopleTitle, $page->filter('#team-people')->text());
    }

    #[Test]
    public function theLeadSeesRemoveOnEveryProjectAndAddProjectWithBothDialogParts(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);
        $this->addProject($this->shop, 'SHOP', 'Web shop');
        $this->addProject($this->shop, 'CART', 'Checkout');
        $this->addProject(null, 'LOOSE', 'Not in a team');
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addProject(null, 'OTHER', 'Another organization', $betaBakery);

        $page = $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['Remove CART from the team', 'Remove SHOP from the team'],
            $page->filter('.team__remove button')->each(static fn ($button): ?string => $button->attr('aria-label')),
        );
        self::assertSame('Add project', $page->filter('#team-projects ~ div button[data-action="dialog#open"]')->text());
        self::assertSame('Add a project', $page->filter('#add-project-dialog-title')->text());
        self::assertSame(['LOOSE Not in a team'], $page->filter('#add-project-form .team__picker-option')->each(static fn ($option): string => $option->text()));
        self::assertSame('Add project', $page->filter('button[form="add-project-form"]')->text());
        self::assertSame('[A-Za-z][A-Za-z0-9]{1,9}', $page->filter('form.team__new-project input[name="key"]')->attr('pattern'));
        self::assertCount(1, $page->filter('form.team__new-project input[name="name"]'));
        self::assertSame('Create project', $page->filter('form.team__new-project button')->text());
    }

    #[Test]
    public function theDialogSaysEveryProjectHasATeamWhenNoneIsLeftToAdd(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);
        $this->addProject($this->shop, 'SHOP', 'Web shop');

        $page = $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Every project already has a team.', $page->filter('.team__dialog-empty')->text());
        self::assertCount(0, $page->filter('#add-project-form'));
        self::assertCount(0, $page->filter('button[form="add-project-form"]'));
    }

    #[Test]
    public function anAdminCanAddProjectsButNotRemoveThem(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Sara']);
        $this->addProject($this->shop, 'SHOP', 'Web shop');
        $this->addProject(null, 'LOOSE', 'Not in a team');

        $page = $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('.team__remove'));
        self::assertCount(1, $page->filter('#add-project-form'));
        self::assertCount(1, $page->filter('form.team__new-project'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function peopleWhoCannotChangeProjects(): iterable
    {
        yield 'Omar, a member' => ['Omar'];
        yield 'Mia, a viewer' => ['Mia'];
        yield 'Raj, a Manager' => ['Raj'];
    }

    #[Test]
    #[DataProvider('peopleWhoCannotChangeProjects')]
    public function everyoneElseSeesNoRemoveNoAddProjectAndNoDialog(string $person): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()[$person]);
        $this->addProject($this->shop, 'SHOP', 'Web shop');
        $this->addProject(null, 'LOOSE', 'Not in a team');

        $page = $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('.team__remove'));
        self::assertCount(0, $page->filter('#add-project-dialog'));
        self::assertStringNotContainsString('Add project', $page->filter('main')->text());
        self::assertStringNotContainsString('Not in a team', $page->filter('main')->text());
    }

    #[Test]
    public function theLeadRemovesAProjectWithTheRenderedFormAndSeesTheToastAndOneRowLess(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);
        $this->addProject($this->shop, 'SHOP', 'Web shop');
        $this->addProject($this->shop, 'CART', 'Checkout');
        $page = $this->client->request('GET', '/teams/shop');
        self::assertResponseStatusCodeSame(200);

        $this->client->submit($page->filter('.team__remove button')->first()->form());
        self::assertResponseRedirects('/teams/shop', 302);
        $page = $this->client->followRedirect();

        self::assertResponseStatusCodeSame(200);
        self::assertSame('CART removed from the team', $page->filter('[data-toast-message-value]')->attr('data-toast-message-value'));
        self::assertSame('Projects 1', $page->filter('#team-projects')->text());
        self::assertStringNotContainsString('Checkout', $page->filter('.team__projects')->text());
    }

    #[Test]
    public function theQueriesStayTheSameWhateverTheNumberOfProjects(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);
        $this->client->disableReboot();
        $exporter = self::getContainer()->get('tracing.in_memory_exporter');
        self::assertInstanceOf(InMemoryExporter::class, $exporter);

        $this->addProject($this->shop, 'SHOP', 'Web shop');
        $this->addProject(null, 'L1', 'Loose one');
        // The first request of a session also loads the logged-in user's own state, so it is no yardstick.
        $this->client->request('GET', '/teams/shop');
        self::assertResponseStatusCodeSame(200);

        $spansBeforeOneProject = \count(ExportedSpans::of($exporter));
        $page = $this->client->request('GET', '/teams/shop');
        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $page->filter('.team__project'));
        $statementsWithOneProject = $this->spanNamesAfter($exporter, $spansBeforeOneProject);

        foreach (['CART', 'PAY', 'SEARCH', 'STOCK'] as $key) {
            $this->addProject($this->shop, $key, 'Project '.$key);
            $this->addProject(null, 'L'.$key, 'Loose '.$key);
        }
        $spansBeforeFiveProjects = \count(ExportedSpans::of($exporter));
        $page = $this->client->request('GET', '/teams/shop');
        self::assertResponseStatusCodeSame(200);
        self::assertCount(5, $page->filter('.team__project'));
        $statementsWithFiveProjects = $this->spanNamesAfter($exporter, $spansBeforeFiveProjects);

        self::assertSame($statementsWithOneProject, $statementsWithFiveProjects);
        self::assertContains('db SELECT', $statementsWithOneProject);
    }

    #[Test]
    public function theQueriesStayTheSameWhateverTheNumberOfPeopleAndTheirLoads(): void
    {
        $people = $this->createAcmeFoodsPeople();
        $this->logIn($this->client, $people['Lena']);
        $this->client->disableReboot();
        $exporter = self::getContainer()->get('tracing.in_memory_exporter');
        self::assertInstanceOf(InMemoryExporter::class, $exporter);
        $projectId = $this->addProject($this->shop, 'SHOP', 'Web shop');
        $this->addTurnsWaitingOn($people['Omar'], 1, $projectId, $this->shop, $people['Omar']);
        // The first request of a session also loads the logged-in user's own state and fills the team people cache.
        $this->client->request('GET', '/teams/shop');
        self::assertResponseStatusCodeSame(200);

        $spansBeforeTwoPeople = \count(ExportedSpans::of($exporter));
        $page = $this->client->request('GET', '/teams/shop');
        self::assertResponseStatusCodeSame(200);
        self::assertCount(2, $page->filter('.team__person'));
        $statementsWithTwoPeople = $this->spanNamesAfter($exporter, $spansBeforeTwoPeople);

        foreach ($this->addShopMembers('Ben', 'Noor', 'Pia', 'Tom', 'Zoe') as $member) {
            $this->addTurnsWaitingOn($member, 3, $projectId, $this->shop, $people['Omar']);
        }
        self::getContainer()->get(TeamPeopleCache::class)->invalidateTeamNow($this->shop);
        $this->client->request('GET', '/teams/shop');
        self::assertResponseStatusCodeSame(200);
        $spansBeforeSevenPeople = \count(ExportedSpans::of($exporter));
        $page = $this->client->request('GET', '/teams/shop');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Ben 3', 'Noor 3', 'Pia 3', 'Tom 3', 'Zoe 3', 'Omar 1'], \array_slice($page->filter('.team__person-head')->each(static fn (Crawler $head): string => $head->text()), 0, 6));
        $statementsWithSevenPeople = $this->spanNamesAfter($exporter, $spansBeforeSevenPeople);

        self::assertSame($statementsWithTwoPeople, $statementsWithSevenPeople);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function peopleWhoCannotChangeSteps(): iterable
    {
        yield 'Omar, a member' => ['Omar'];
        yield 'Mia, a viewer' => ['Mia'];
        yield 'Raj, a Manager' => ['Raj'];
        yield 'Sara, an Admin who is not the lead' => ['Sara'];
    }

    #[Test]
    public function underExtendTheLeadSeesTheAddStepPanelWithGroupChipsButNoDoneAndARemoveOnHerTeamsStep(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);
        $this->setStepPolicy(StepPolicy::Extend);
        $this->insertStep($this->shop, 'Ready to merge', 'team');
        $this->insertStep(null, 'Code review', 'company');

        $page = $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('How work moves here', $page->filter('#team-flow')->text());
        self::assertSame(
            'Your admin set the company steps. As lead, you can add team steps inside the five groups.',
            $page->filter('.team__policy')->text(),
        );
        self::assertSame('Add a team step', $page->filter('.team__add-step h3')->text());
        self::assertSame(['To do', 'Doing', 'Checking', 'Waiting'], $page->filter('.team__add-step .chip')->each(static fn ($chip): string => $chip->text()));
        self::assertSame('check', $page->filter('.team__add-step input[type="radio"][checked]')->attr('value'));
        self::assertSame(['Remove step Ready to merge'], $page->filter('.team__step-remove')->each(static fn ($button): ?string => $button->attr('aria-label')));
        self::assertSame(['Ready to merge'], $page->filter('.team__step--team')->each(static fn ($step): string => trim($step->text(), " \u{00D7}")));
    }

    #[Test]
    #[DataProvider('peopleWhoCannotChangeSteps')]
    public function everyoneButTheLeadSeesNoAddStepPanelAndNoRemoveOnSteps(string $person): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()[$person]);
        $this->setStepPolicy(StepPolicy::Team);
        $this->insertStep($this->shop, 'Ready to merge', 'team');

        $page = $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('.team__add-step'));
        self::assertCount(0, $page->filter('.team__step-remove'));
        self::assertStringNotContainsString('Add a team step', $page->filter('main')->text());
        self::assertSame(['Ready to merge'], $page->filter('.team__step--team')->each(static fn ($step): string => $step->text()));
    }

    #[Test]
    public function underLockedNobodyNotEvenTheLeadSeesTheAddPanelOrARemoveAndThePolicySentenceIsTheLockedOne(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);
        $this->setStepPolicy(StepPolicy::Locked);
        $this->insertStep($this->shop, 'Ready to merge', 'team');
        $this->insertStep(null, 'Code review', 'company');

        $page = $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Your admin set these steps for the whole company. Teams cannot change them.', $page->filter('.team__policy')->text());
        self::assertCount(0, $page->filter('.team__add-step'));
        self::assertCount(0, $page->filter('.team__step-remove'));
        self::assertCount(0, $page->filter('.team__step--team'));
        self::assertStringContainsString('Code review', $page->filter('.team__groups')->text());
    }

    #[Test]
    public function underExtendAPersonWhoIsNotTheLeadIsToldTheTeamLeadCanAddSteps(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Omar']);

        $page = $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Your admin set the company steps. The team lead can add team steps inside the five groups.', $page->filter('.team__policy')->text());
    }

    #[Test]
    public function underTeamTheLeadSeesTheTeamDecidesSentenceAndASwitchForEachRule(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);
        $this->setStepPolicy(StepPolicy::Team);

        $page = $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Your admin lets each lead shape the team’s steps and rules. Every step still sits in one of the five groups.', $page->filter('.team__policy')->text());
        self::assertSame(
            ['Only the tester can move a ticket to Done', 'Nobody approves their own code review', 'Code review must happen before testing'],
            $page->filter('.team__rule-form button[role="switch"]')->each(static fn ($switch): ?string => $switch->attr('aria-label')),
        );
        self::assertSame(['true', 'true', 'true'], $page->filter('.team__rule-form button[role="switch"]')->each(static fn ($switch): ?string => $switch->attr('aria-checked')));
        self::assertSame(['0', '0', '0'], $page->filter('.team__rule-form input[name="is_on"]')->each(static fn ($field): ?string => $field->attr('value')));
        self::assertCount(0, $page->filter('.team__rule-mark'));
    }

    #[Test]
    public function underTeamTheLeadTurnsARuleOffWithItsSwitchAndThePageShowsItOff(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);
        $this->setStepPolicy(StepPolicy::Team);
        $page = $this->client->request('GET', '/teams/shop');
        self::assertResponseStatusCodeSame(200);

        $this->client->submit($page->filter('.team__rule-form')->eq(1)->form());
        self::assertResponseRedirects('/teams/shop', 302);
        $page = $this->client->followRedirect();

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['true', 'false', 'true'], $page->filter('.team__rule-form button[role="switch"]')->each(static fn ($switch): ?string => $switch->attr('aria-checked')));
        self::assertSame(['0', '1', '0'], $page->filter('.team__rule-form input[name="is_on"]')->each(static fn ($field): ?string => $field->attr('value')));
    }

    /**
     * @return iterable<string, array{string, StepPolicy, string}>
     */
    public static function peopleAndPoliciesWithNoRuleSwitch(): iterable
    {
        yield 'Lena under extend' => ['Lena', StepPolicy::Extend, 'admin'];
        yield 'Lena under locked' => ['Lena', StepPolicy::Locked, 'admin'];
        yield 'Omar under extend' => ['Omar', StepPolicy::Extend, 'admin'];
        yield 'Omar under locked' => ['Omar', StepPolicy::Locked, 'admin'];
        yield 'Omar under team' => ['Omar', StepPolicy::Team, 'team lead'];
        yield 'Mia under team' => ['Mia', StepPolicy::Team, 'team lead'];
        yield 'Raj under team' => ['Raj', StepPolicy::Team, 'team lead'];
        yield 'Sara under team' => ['Sara', StepPolicy::Team, 'team lead'];
    }

    #[Test]
    #[DataProvider('peopleAndPoliciesWithNoRuleSwitch')]
    public function whoeverMayNotChangeRulesSeesOnMarksSetByWhoeverSetThem(string $person, StepPolicy $stepPolicy, string $setter): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()[$person]);
        $this->setStepPolicy($stepPolicy);

        $page = $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('.team__rule-form'));
        self::assertSame(
            [
                'On Only the tester can move a ticket to Done · set by the '.$setter,
                'On Nobody approves their own code review · set by the '.$setter,
                'On Code review must happen before testing · set by the '.$setter,
            ],
            $page->filter('.team__rule')->each(static fn ($rule): string => $rule->text()),
        );
        self::assertCount(3, $page->filter('.team__rule-mark--on'));
    }

    /**
     * @return iterable<string, array{StepPolicy}>
     */
    public static function policiesThatLetTheLeadChangeSteps(): iterable
    {
        yield 'extend' => [StepPolicy::Extend];
        yield 'team' => [StepPolicy::Team];
    }

    #[Test]
    #[DataProvider('policiesThatLetTheLeadChangeSteps')]
    public function theLeadSeesTheAddStepPanelAndARemoveOnHerTeamsStepUnderExtendAndTeam(StepPolicy $stepPolicy): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);
        $this->setStepPolicy($stepPolicy);
        $this->insertStep($this->shop, 'Ready to merge', 'team');

        $page = $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Add a team step', $page->filter('.team__add-step h3')->text());
        self::assertSame(['Remove step Ready to merge'], $page->filter('.team__step-remove')->each(static fn ($button): ?string => $button->attr('aria-label')));
    }

    #[Test]
    public function aRuleThatIsOffShowsAnOffMarkWithoutTheGreen(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Omar']);
        $this->committedAs($this->acmeFoods, fn (Connection $migrator): int|string => $migrator->executeStatement(
            'INSERT INTO wf_rule_set (organization_id, team_id, tester_moves_done, no_self_review, review_before_test) VALUES (?, NULL, true, false, true)',
            [$this->acmeFoods->toString()],
        ));

        $page = $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['On', 'Off', 'On'], $page->filter('.team__rule-mark')->each(static fn ($mark): string => $mark->text()));
        self::assertCount(1, $page->filter('.team__rule-mark:not(.team__rule-mark--on)'));
    }

    #[Test]
    public function theLeadAddsDesignCheckUnderCheckingAndTheOtherPeopleNeverSawTheForm(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);
        $page = $this->client->request('GET', '/teams/shop');
        self::assertResponseStatusCodeSame(200);
        $form = $page->filter('.team__add-step')->form(['name' => 'Design check', 'group' => 'check']);

        $this->client->submit($form);
        self::assertResponseRedirects('/teams/shop', 302);
        $page = $this->client->followRedirect();

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Added “Design check” to Checking. Managers still see it as Checking.', $page->filter('[data-toast-message-value]')->attr('data-toast-message-value'));
        $checking = $page->filter('.team__group')->eq(2);
        self::assertStringStartsWith('Checking', $checking->text());
        self::assertSame(['Design check'], $checking->filter('.team__step--team')->each(static fn ($step): string => trim($step->text(), " \u{00D7}")));
    }

    #[Test]
    public function theTeamsStepsAreShownInTheFiveGroupsInOrderWithCoreAndCompanyStepsBeforeTeamSteps(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Omar']);
        $this->insertStep($this->shop, 'Ready to merge', 'team', 'doing');
        $this->insertStep(null, 'In progress', 'core', 'doing', 'progress');
        $this->insertStep(null, 'Code review', 'company', 'doing');
        $this->insertStep(null, 'Backlog', 'core', 'todo', 'backlog');
        $this->insertStep(null, 'Done', 'core', 'done', 'done');
        $this->insertStep($this->mobile, 'Device check', 'team', 'doing');

        $page = $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['To do Backlog', 'Doing In progress Code review Ready to merge', 'Checking', 'Waiting', 'Done Done'],
            $page->filter('.team__group')->each(static fn ($group): string => $group->text()),
        );
    }

    #[Test]
    public function theQueriesStayTheSameWhateverTheNumberOfSteps(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);
        $this->client->disableReboot();
        $exporter = self::getContainer()->get('tracing.in_memory_exporter');
        self::assertInstanceOf(InMemoryExporter::class, $exporter);
        $this->insertStep($this->shop, 'Step 0', 'team');
        // The first request of a session also loads the logged-in user's own state, so it is no yardstick.
        $this->client->request('GET', '/teams/shop');
        self::assertResponseStatusCodeSame(200);

        $spansBefore = \count(ExportedSpans::of($exporter));
        $page = $this->client->request('GET', '/teams/shop');
        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $page->filter('.team__step--team'));
        $statementsWithOneStep = $this->spanNamesAfter($exporter, $spansBefore);

        foreach ([1, 2, 3, 4] as $number) {
            $this->insertStep($this->shop, 'Step '.$number, 'team', ['todo', 'doing', 'check', 'wait'][$number - 1]);
        }
        // Refills the workflow cache the inserts dropped, so both counted loads read it warm.
        $this->client->request('GET', '/teams/shop');
        self::assertResponseStatusCodeSame(200);
        $spansBeforeFive = \count(ExportedSpans::of($exporter));
        $page = $this->client->request('GET', '/teams/shop');
        self::assertResponseStatusCodeSame(200);
        self::assertCount(5, $page->filter('.team__step--team'));
        $statementsWithFiveSteps = $this->spanNamesAfter($exporter, $spansBeforeFive);

        self::assertSame($statementsWithOneStep, $statementsWithFiveSteps);
    }

    /**
     * The page, the move-target and rule caches and the voter for its two attributes each ask for the step policy.
     */
    #[Test]
    public function theTeamPageReadsTheStepPolicyOnce(): void
    {
        $this->client->disableReboot();
        $settings = new class implements SettingsQueries, StoredSettingsQueries {
            public int $reads = 0;

            public function stepPolicyOf(OrganizationId $organizationId): StepPolicy
            {
                ++$this->reads;

                return StepPolicy::Team;
            }

            public function triagerOf(OrganizationId $organizationId): ?UserId
            {
                return null;
            }

            public function settingsOf(OrganizationId $organizationId): StoredSettings
            {
                return new StoredSettings($this->stepPolicyOf($organizationId), $this->triagerOf($organizationId), 4);
            }
        };
        self::getContainer()->set(DbalSettingsQueries::class, $settings);
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);

        $page = $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(3, $page->filter('.team__rule-form button[role="switch"]'), 'Under team the voter and the rule cache asked too.');
        self::assertSame(1, $settings->reads);
    }

    #[Test]
    public function aPersonOfTheOrganizationWithNoTeamAndNoOrgRoleIsRefused(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople(miaTeamRole: null)['Mia']);

        $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function anAdminOfAnotherOrganizationDoesNotFindTheTeam(): void
    {
        $this->createAcmeFoodsPeople();
        $betaBakery = $this->createOrganization('Beta Bakery');
        $otherAdmin = $this->createUserWithoutOrganization('Sara');
        $this->addMembership($otherAdmin, $betaBakery);
        $this->setOrgRoles($otherAdmin, $betaBakery, '{admin}');
        $this->logIn($this->client, $otherAdmin);

        $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function addressesOfNoTeam(): iterable
    {
        yield 'a slug no team has' => ['/teams/web'];
        yield 'the name in its own case' => ['/teams/Shop'];
        yield 'a slug with a trailing hyphen' => ['/teams/shop-'];
    }

    #[Test]
    #[DataProvider('addressesOfNoTeam')]
    public function anAddressThatIsNoTeamsSlugIsNotFound(string $address): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Sara']);

        $this->client->request('GET', $address);

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function anAnonymousVisitorIsSentToLogIn(): void
    {
        $this->createAcmeFoodsPeople();

        $this->client->request('GET', '/teams/shop');

        self::assertResponseRedirects('/login', 302);
    }

    /**
     * An open bug SHOP-1 only its team may see, written straight into tkt_ticket: the count reads no other table.
     */
    private function addTeamOnlyTicket(string $projectId, TeamId $teamId, User $reporter): void
    {
        $organizationId = $this->acmeFoods->toString();
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                 step_id, reporter_id, visibility, components, check_reasons, created_at, updated_at)
             VALUES (?, ?, ?, ?, 1, 'SHOP-1', 'Checkout button is hidden', '', 'bug', 'major', 2, ?, ?, 'team', '{}', '{}', '2027-03-15 10:00:00+00', '2027-03-15 10:00:00+00')",
            [$organizationId, Uuid::v7()->toRfc4122(), $projectId, $teamId->toString(), Uuid::v7()->toRfc4122(), $reporter->id()->toString()],
        ));
    }

    /**
     * @return array<string, User> the new members of Shop by first name, each with the job "other"
     */
    private function addShopMembers(string ...$names): array
    {
        $members = [];
        foreach ($names as $name) {
            $members[$name] = $this->createUserWithoutOrganization($name);
            $this->addMembership($members[$name], $this->acmeFoods);
            $this->addTeamRole($members[$name], $this->acmeFoods, $this->shop, 'member');
        }

        return $members;
    }

    /**
     * @param 'developer'|'tester'|'other' $job
     */
    private function setShopJob(User $person, string $job): void
    {
        $this->committedAs($this->acmeFoods, fn (Connection $migrator): int|string => $migrator->executeStatement(
            'UPDATE org_team_member SET job = ? WHERE organization_id = ? AND team_id = ? AND user_id = ?',
            [$job, $this->acmeFoods->toString(), $this->shop->toString(), $person->id()->toString()],
        ));
    }

    /**
     * $count open tickets whose turn is $person's, written straight into tkt_ticket and ins_ticket_turn, numbered on
     * from the last ones added; the counts read only ins_ticket_turn, whose rows need their ticket.
     *
     * @param 'all'|'team' $audience
     */
    private function addTurnsWaitingOn(User $person, int $count, string $projectId, TeamId $teamId, User $reporter, string $audience = 'all'): void
    {
        $firstNumber = $this->nextTicketNumber;
        $this->nextTicketNumber += $count;
        $this->committedAs($this->acmeFoods, fn (Connection $migrator): int|string => $migrator->executeStatement(
            "WITH ticket AS (
                 INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                     step_id, reporter_id, visibility, components, check_reasons, created_at, updated_at)
                 SELECT CAST(? AS uuid), gen_random_uuid(), CAST(? AS uuid), CAST(? AS uuid), number, 'SHOP-' || number, 'Checkout button is hidden', '', 'bug', 'major', 2,
                     gen_random_uuid(), CAST(? AS uuid), ?, '{}', '{}', '2027-03-15 10:00:00+00', '2027-03-15 10:00:00+00'
                 FROM generate_series(CAST(? AS int), CAST(? AS int)) AS number
                 RETURNING organization_id, id, team_id, project_id, visibility, reporter_id
             )
             INSERT INTO ins_ticket_turn (organization_id, ticket_id, team_id, project_id, grp, turn_user_id, kind, base_score, waiting_since, visibility, reporter_id)
             SELECT organization_id, id, team_id, project_id, 'doing', CAST(? AS uuid), 'build', 10, '2027-03-15 10:00:00+00', visibility, reporter_id FROM ticket",
            [
                $this->acmeFoods->toString(), $projectId, $teamId->toString(), $reporter->id()->toString(), $audience,
                $firstNumber, $firstNumber + $count - 1, $person->id()->toString(),
            ],
        ));
    }

    private function removeCommittedTickets(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                $migrator->executeStatement('DELETE FROM ins_ticket_turn WHERE organization_id = ?', [$organizationId]);
                $migrator->executeStatement('DELETE FROM tkt_ticket WHERE organization_id = ?', [$organizationId]);

                return 0;
            });
        }
    }

    private function setStepPolicy(StepPolicy $stepPolicy): void
    {
        $this->committedAs($this->acmeFoods, fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO org_settings (organization_id, data, created_at) VALUES (?, ?, '2026-11-08 09:00:00+00')",
            [$this->acmeFoods->toString(), json_encode(['step_policy' => $stepPolicy->value], \JSON_THROW_ON_ERROR)],
        ));
    }

    /**
     * Test organizations have no core steps, so a test inserts the ones it needs.
     *
     * @param ?TeamId $teamId null for a company or core step
     */
    private function insertStep(?TeamId $teamId, string $name, string $origin, string $groupCode = 'check', ?string $coreCode = null): void
    {
        $this->committedAs($this->acmeFoods, fn (Connection $migrator): int|string => $migrator->executeStatement(
            'INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position, archived_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, NULL)',
            [$this->acmeFoods->toString(), Uuid::v7()->toRfc4122(), $teamId?->toString(), $name, $groupCode, $coreCode, $origin],
        ));
        // The cache.workflow pool outlives the request, so the direct write drops the entries as AddStep's event would.
        self::getContainer()->get(WorkflowCache::class)->invalidateNow($this->acmeFoods, $teamId);
    }

    /**
     * Through the migrator, as the app may not delete steps or rule sets; must run before LogsInUsers' tearDown deletes
     * the organizations. The outbox rows are the events the add-step action published.
     */
    private function removeCommittedWorkflow(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                $migrator->executeStatement('DELETE FROM wf_step WHERE organization_id = ?', [$organizationId]);
                $migrator->executeStatement('DELETE FROM wf_rule_set WHERE organization_id = ?', [$organizationId]);
                $migrator->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox' AND position(? in body) > 0", [$organizationId]);

                return 0;
            });
        }
    }

    /**
     * @return list<string> the names of the spans exported after the first $skipped ones
     */
    private function spanNamesAfter(InMemoryExporter $exporter, int $skipped): array
    {
        return array_map(static fn (SpanDataInterface $span): string => $span->getName(), \array_slice(ExportedSpans::of($exporter), $skipped));
    }
}
