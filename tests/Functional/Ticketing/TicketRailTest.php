<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ticketing;

use App\Identity\Domain\User;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Ticketing\UI\TicketListController;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The tickets page's project rail through the real firewall, voters and database. Lena leads Shop (projects SHOP and
 * CART) and Mobile (project APP); Omar leads Data, which Lena is not in; Raj is a manager in no team. The counts are
 * seeded in ins_project_counts, the rail's read model: SHOP has 3 open and 2 done tickets for everyone and 1 open
 * team-only one, CART only 1 done, APP 4 open.
 */
final class TicketRailTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string TICKETS = '/tickets';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private TeamId $data;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->people = $this->createAcmeFoodsPeople();
        $this->data = $this->createTeam($this->acmeFoods, 'Data', $this->people['Omar']);
        $shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $cartProject = $this->addProject($this->shop, 'CART', 'Checkout');
        $appProject = $this->addProject($this->mobile, 'APP', 'Mobile app');
        $this->addProject($this->data, 'DATA', 'Warehouse');
        $this->addCounts([
            [$shopProject, $this->shop, 'all', 3, 2],
            [$shopProject, $this->shop, 'team', 1, 0],
            [$cartProject, $this->shop, 'all', 0, 1],
            [$appProject, $this->mobile, 'all', 4, 0],
        ]);
        $this->addTickets([[$shopProject, 'SHOP-1', 'Totals counted twice'], [$cartProject, 'CART-1', 'Card form loses focus']]);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                $migrator->executeStatement('DELETE FROM tkt_ticket WHERE organization_id = ?', [$organizationId]);
                $migrator->executeStatement('DELETE FROM wf_step WHERE organization_id = ?', [$organizationId]);

                return 0;
            });
        }
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function theRailShowsTheTeamAllProjectsEachProjectWithItsOpenCountAndManageProjects(): void
    {
        $page = $this->pageAs('Lena', self::TICKETS);

        $rail = $page->filter('nav.project-rail[aria-label="Projects"]');
        self::assertCount(1, $rail);
        self::assertSame('Shop', $rail->filter('p.project-rail__team')->text());
        self::assertCount(0, $rail->filter('h1, h2, h3, h4, h5, h6'), 'The team is a label, so the page\'s first heading is its h1.');
        self::assertSame(['All projects 4 open tickets', 'CART Checkout 0 open tickets', 'SHOP Shop 4 open tickets'], self::railItemsOf($page));
        self::assertSame(['open tickets', 'open tickets', 'open tickets'], $rail->filter('.project-rail__count .visually-hidden')->each(static fn (Crawler $label): string => $label->text()));
        self::assertSame(['All projects 4 open tickets'], $rail->filter('.project-rail__item[aria-current="true"]')->each(static fn (Crawler $item): string => $item->text()));
        self::assertSame('/tickets?project=SHOP', $rail->filter('[data-rail-project="SHOP"]')->attr('href'));
        self::assertSame('Manage projects', $rail->filter('.project-rail__manage')->text());
        self::assertSame('/teams/shop', $rail->filter('.project-rail__manage')->attr('href'));
        self::assertCount(0, $page->filter('turbo-frame#ticket-list-frame nav.project-rail'), 'The rail sits outside the list frame.');
    }

    #[Test]
    public function showDoneCountsTheDoneTicketsToo(): void
    {
        $page = $this->pageAs('Lena', self::TICKETS.'?done=1');

        self::assertSame(['All projects 7 tickets', 'CART Checkout 1 ticket', 'SHOP Shop 6 tickets'], self::railItemsOf($page));
        self::assertSame('/tickets?project=SHOP&done=1', $page->filter('[data-rail-project="SHOP"]')->attr('href'));
    }

    #[Test]
    public function thePressedProjectIsCurrentFiltersTheListAndKeepsTheOtherFilters(): void
    {
        $omar = $this->people['Omar']->id()->toString();

        $page = $this->pageAs('Lena', self::TICKETS.'?project=CART&q=card');

        self::assertSame(['CART Checkout 0 open tickets'], $page->filter('.project-rail__item[aria-current="true"]')->each(static fn (Crawler $item): string => $item->text()));
        self::assertSame(['CART-1'], $page->filter('.tickets__row')->each(static fn (Crawler $row): string => (string) $row->attr('data-ticket-key')));
        self::assertSame('/tickets?project=SHOP&q=card', $page->filter('[data-rail-project="SHOP"]')->attr('href'));
        self::assertSame('/tickets?team='.$this->shop->toString().'&q=card', $page->filter('[data-rail-project=""]')->attr('href'));

        $withPerson = $this->pageAs('Lena', self::TICKETS.'?project=CART&person='.$omar);
        self::assertSame('/tickets?project=SHOP&person='.$omar, $withPerson->filter('[data-rail-project="SHOP"]')->attr('href'));
    }

    /**
     * Lena is in Shop and Mobile, Shop first by id: a project of Mobile shows Mobile's rail and people, "All projects"
     * shows Shop's, and the switch offers her two teams only, not Data.
     */
    #[Test]
    public function aViewerInTwoTeamsSeesTheRailAndPeopleOfThePickedProjectsTeam(): void
    {
        $mobilePage = $this->pageAs('Lena', self::TICKETS.'?project=APP');

        self::assertSame('Mobile', $mobilePage->filter('.project-rail__team')->text());
        self::assertSame(['All projects 4 open tickets', 'APP Mobile app 4 open tickets'], self::railItemsOf($mobilePage));
        self::assertSame(['Everyone', 'Me'], self::peopleChipsOf($mobilePage));
        self::assertSame('/tickets?team='.$this->mobile->toString(), $mobilePage->filter('[data-rail-project=""]')->attr('href'));
        $pickedShopPage = $this->pageAs('Lena', self::TICKETS.'?project=APP&team='.$this->shop->toString());
        self::assertSame('Mobile', $pickedShopPage->filter('.project-rail__team')->text());
        self::assertSame('/tickets?project=APP&done=1', $pickedShopPage->filter('[data-done-chip]')->attr('href'), 'The project\'s own team decides, so the toolbar drops the pick.');

        $allPage = $this->pageAs('Lena', self::TICKETS);

        self::assertSame('Shop', $allPage->filter('.project-rail__team')->text());
        self::assertSame(['Everyone', 'Me', 'Omar'], self::peopleChipsOf($allPage));
        self::assertSame(['Mobile', 'Shop'], $allPage->filter('.project-rail__switch a')->each(static fn (Crawler $team): string => $team->text()));
        self::assertSame(['Shop'], $allPage->filter('.project-rail__switch a[aria-current="true"]')->each(static fn (Crawler $team): string => $team->text()));
        self::assertSame('/tickets?team='.$this->mobile->toString(), $allPage->filter('.project-rail__switch a')->first()->attr('href'));
    }

    #[Test]
    public function aPickedTeamOfTheViewerShowsItsRailAndPeopleAndTheToolbarKeepsIt(): void
    {
        $mobile = $this->mobile->toString();

        $page = $this->pageAs('Lena', self::TICKETS.'?team='.$mobile);

        self::assertSame('Mobile', $page->filter('.project-rail__team')->text());
        self::assertSame(['All projects 4 open tickets'], $page->filter('.project-rail__item[aria-current="true"]')->each(static fn (Crawler $item): string => $item->text()));
        self::assertSame(['Everyone', 'Me'], self::peopleChipsOf($page));
        self::assertSame('/tickets?team='.$mobile.'&done=1', $page->filter('[data-done-chip]')->attr('href'));
        self::assertSame([$mobile], $page->filter('.ticket-filters__search input[name="team"]')->each(static fn (Crawler $field): string => (string) $field->attr('value')));
        self::assertSame(['CART-1', 'SHOP-1'], $page->filter('.tickets__row')->each(static fn (Crawler $row): string => (string) $row->attr('data-ticket-key')), 'The team picks the rail, not the list.');
    }

    /**
     * A project no team owns gives the rail no team, so it shows the team the viewer would see on "All projects".
     */
    #[Test]
    public function aProjectOfNoTeamShowsTheViewersOwnTeamWithNoProjectPressed(): void
    {
        $this->addProject(null, 'OLD', 'Archive');

        $mobile = $this->mobile->toString();

        $page = $this->pageAs('Lena', self::TICKETS.'?project=OLD&team='.$mobile);

        self::assertSame('OLD · Archive', $page->filter('.tickets__title')->text());
        self::assertSame('Mobile', $page->filter('.project-rail__team')->text());
        self::assertCount(0, $page->filter('.project-rail__item[aria-current="true"]'));
        self::assertSame('/tickets?team='.$mobile.'&project=OLD&done=1', $page->filter('[data-done-chip]')->attr('href'), 'The picked team still decides the toolbar, so its links keep it.');
        self::assertSame([$mobile], $page->filter('.ticket-filters__search input[name="team"]')->each(static fn (Crawler $field): string => (string) $field->attr('value')));
        self::assertSame('Shop', $this->pageAs('Lena', self::TICKETS.'?project=OLD')->filter('.project-rail__team')->text());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function teamsLenaIsNotIn(): iterable
    {
        yield 'a team of her organization she is not in' => ['data'];
        yield 'a team of another organization' => ['otherOrganization'];
        yield 'no team at all' => ['unknown'];
        yield 'not a uuid' => ['malformed'];
    }

    #[Test]
    #[DataProvider('teamsLenaIsNotIn')]
    public function aTeamTheViewerIsNotInIsNotFound(string $team): void
    {
        $teamId = match ($team) {
            'data' => $this->data->toString(),
            'otherOrganization' => $this->createTeam($this->createOrganization('Beta Bakery'), 'Bakery', $this->createUserWithoutOrganization('Sara'))->toString(),
            'unknown' => Uuid::v7()->toRfc4122(),
            default => 'shop',
        };
        $this->logIn($this->client, $this->people['Lena']);

        $page = $this->client->request('GET', self::TICKETS.'?team='.rawurlencode($teamId));

        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $page->filter('nav.project-rail'));
    }

    /**
     * Raj, a manager in no team, has no team on "All projects", so the rail is "All projects" alone. On a project of
     * Shop he gets Shop's rail and counts only its tickets for everyone; Sara, an admin, counts the team-only one too.
     */
    #[Test]
    public function anOutsiderCountsOnlyTheTicketsOpenToAll(): void
    {
        $allPage = $this->pageAs('Raj', self::TICKETS);
        self::assertSame(['All projects'], self::railItemsOf($allPage));
        self::assertCount(0, $allPage->filter('.project-rail__team, .project-rail__switch'));

        $shopPage = $this->pageAs('Raj', self::TICKETS.'?project=SHOP');
        self::assertSame(['All projects 3 open tickets', 'CART Checkout 0 open tickets', 'SHOP Shop 3 open tickets'], self::railItemsOf($shopPage));
        self::assertSame('/tickets', $shopPage->filter('[data-rail-project=""]')->attr('href'), 'Raj is not in Shop, so "All projects" does not pick it.');
        self::assertCount(0, $shopPage->filter('.project-rail__switch'));
        self::assertSame('/teams/shop', $shopPage->filter('.project-rail__manage')->attr('href'), 'A manager may open the Team page.');

        self::assertSame(['All projects 4 open tickets', 'CART Checkout 0 open tickets', 'SHOP Shop 4 open tickets'], self::railItemsOf($this->pageAs('Sara', self::TICKETS.'?project=SHOP')));
    }

    /**
     * Ben, a reporter of Acme Foods in no team and with no organization role, may not see Shop (TeamVoter's VIEW): on
     * one of its projects the rail and toolbar show neither Shop's name, projects, Team page nor people, and the list
     * keeps its own visibility rules.
     */
    #[Test]
    public function aReporterOutsideTheTeamSeesNeitherTheProjectsTeamNorItsPeople(): void
    {
        $ben = $this->createUserWithoutOrganization('Ben');
        $this->addMembership($ben, $this->acmeFoods);
        $this->setOrgRoles($ben, $this->acmeFoods, '{}');
        $this->people['Ben'] = $ben;

        $page = $this->pageAs('Ben', self::TICKETS.'?project=SHOP');

        self::assertSame('SHOP · Shop', $page->filter('.tickets__title')->text());
        self::assertSame(['SHOP-1'], $page->filter('.tickets__row')->each(static fn (Crawler $row): string => (string) $row->attr('data-ticket-key')));
        self::assertSame(['All projects'], self::railItemsOf($page));
        self::assertCount(0, $page->filter('.project-rail__team, .project-rail__manage, .project-rail__switch'));
        self::assertStringNotContainsString('Shop', $page->filter('nav.project-rail')->text());
        self::assertSame(['Everyone'], self::peopleChipsOf($page));
        self::assertStringNotContainsString('Lena', $page->filter('.ticket-filters')->text());
    }

    /**
     * Omar is in Shop (member) and leads Data, but is not in Mobile: on Mobile's project he gets his own team, Shop
     * first by id, or the one he picked, and never Mobile's name or projects.
     */
    #[Test]
    public function aMemberOfOtherTeamsGetsTheirOwnTeamOnAProjectOfATeamTheyAreNotIn(): void
    {
        $data = $this->data->toString();

        $appPage = $this->pageAs('Omar', self::TICKETS.'?project=APP');

        self::assertSame('APP · Mobile app', $appPage->filter('.tickets__title')->text());
        self::assertSame('Shop', $appPage->filter('.project-rail__team')->text());
        self::assertSame(['All projects 4 open tickets', 'CART Checkout 0 open tickets', 'SHOP Shop 4 open tickets'], self::railItemsOf($appPage));
        self::assertSame(['Everyone', 'Me', 'Lena'], self::peopleChipsOf($appPage));
        self::assertStringNotContainsString('Mobile', $appPage->filter('nav.project-rail')->text());

        $pickedPage = $this->pageAs('Omar', self::TICKETS.'?project=APP&team='.$data);

        self::assertSame('Data', $pickedPage->filter('.project-rail__team')->text());
        self::assertSame('/tickets?team='.$data.'&project=APP&done=1', $pickedPage->filter('[data-done-chip]')->attr('href'));
    }

    /**
     * After a filter change the page reloads the rail's frame with the list's URL; that request gets the rail alone,
     * with the new counts, and runs none of the list's queries.
     */
    #[Test]
    public function theRailsFrameRequestGetsTheRailAloneWithTheNewCounts(): void
    {
        $this->logIn($this->client, $this->people['Lena']);

        $frame = $this->client->request('GET', self::TICKETS.'?done=1', server: ['HTTP_TURBO_FRAME' => TicketListController::RAIL_FRAME_ID]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Turbo-Frame', $this->client->getResponse()->headers->get('Vary'));
        self::assertCount(1, $frame->filter('turbo-frame#project-rail-frame[target="_top"] nav.project-rail'));
        self::assertCount(0, $frame->filter('turbo-frame#ticket-list-frame, .ticket-filters, header, title'));
        self::assertSame(['All projects 7 tickets', 'CART Checkout 1 ticket', 'SHOP Shop 6 tickets'], self::railItemsOf($frame));
    }

    private function pageAs(string $viewer, string $uri): Crawler
    {
        $this->logIn($this->client, $this->people[$viewer]);
        $page = $this->client->request('GET', $uri);
        self::assertResponseStatusCodeSame(200);

        return $page;
    }

    /**
     * @return list<string>
     */
    private static function railItemsOf(Crawler $page): array
    {
        return $page->filter('.project-rail__item')->each(static fn (Crawler $item): string => $item->text());
    }

    /**
     * @return list<string>
     */
    private static function peopleChipsOf(Crawler $page): array
    {
        return $page->filter('.ticket-filters__people .chip')->each(static fn (Crawler $chip): string => $chip->text());
    }

    /**
     * @param list<array{string, TeamId, string, int, int}> $rows project, ticket team, visibility, open and done counts
     */
    private function addCounts(array $rows): void
    {
        $organizationId = $this->acmeFoods->toString();
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, $rows): int {
            foreach ($rows as [$projectId, $teamId, $visibility, $openCount, $doneCount]) {
                $migrator->executeStatement(
                    'INSERT INTO ins_project_counts (organization_id, project_id, team_id, visibility, open_count, done_count) VALUES (?, ?, ?, ?, ?, ?)',
                    [$organizationId, $projectId, $teamId->toString(), $visibility, $openCount, $doneCount],
                );
            }

            return 0;
        });
    }

    /**
     * Open tickets of Shop's team for everyone, in a To do step, each updated a minute after the one before.
     *
     * @param list<array{string, string, string}> $tickets project id, key and title
     */
    private function addTickets(array $tickets): void
    {
        $organizationId = $this->acmeFoods->toString();
        $stepId = Uuid::v7()->toRfc4122();
        $teamId = $this->shop->toString();
        $reporterId = $this->people['Lena']->id()->toString();
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, $tickets, $teamId, $reporterId, $stepId): int {
            $migrator->executeStatement(
                "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'To do', 'todo', 'todo', 'core', 0)",
                [$organizationId, $stepId],
            );
            foreach ($tickets as $index => [$projectId, $key, $title]) {
                $migrator->executeStatement(
                    "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority,
                         priority_rank, step_id, reporter_id, visibility, components, check_reasons, created_at, updated_at)
                     VALUES (?, ?, ?, ?, 1, ?, ?, '', 'bug', 'normal', 3, ?, ?, 'all', '{}', '{}', TIMESTAMPTZ '2027-03-15 09:00:00+00',
                         TIMESTAMPTZ '2027-03-15 10:00:00+00' + ? * INTERVAL '1 minute')",
                    [$organizationId, Uuid::v7()->toRfc4122(), $projectId, $teamId, $key, $title, $stepId, $reporterId, $index],
                );
            }

            return 0;
        });
    }
}
