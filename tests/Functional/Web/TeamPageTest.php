<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Identity\Domain\User;
use App\Tests\Fixtures\Tracing\ExportedSpans;
use App\Tests\Functional\Identity\LogsInUsers;
use OpenTelemetry\SDK\Trace\SpanDataInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
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

    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    protected function tearDown(): void
    {
        $this->removeCommittedProjects();
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

    #[Test]
    public function seeTicketsOpensTheTicketsListOfThatProject(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Omar']);
        $this->addProject($this->shop, 'SHOP', 'Web shop');

        $page = $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('/_test/menu/tickets?project=SHOP', $page->filter('.team__project-link')->attr('href'));
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
    public function thePeopleSectionIsAShellWithItsHeading(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);

        $page = $this->client->request('GET', '/teams/shop');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('People waiting on them', $page->filter('#team-people')->text());
        self::assertCount(1, $page->filter('.team__column--aside .empty-state'));
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
     * @return list<string> the names of the spans exported after the first $skipped ones
     */
    private function spanNamesAfter(InMemoryExporter $exporter, int $skipped): array
    {
        return array_map(static fn (SpanDataInterface $span): string => $span->getName(), \array_slice(ExportedSpans::of($exporter), $skipped));
    }
}
