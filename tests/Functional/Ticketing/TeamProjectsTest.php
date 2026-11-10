<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ticketing;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use App\Ticketing\Application\OpenTicketCounts;
use App\Ticketing\Domain\ProjectId;
use App\Ticketing\Infrastructure\Doctrine\TicketOpenTicketCounts;
use App\Ticketing\UI\TeamProjectsController;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The team page's project actions, POST /teams/{slug}/projects/unlink, /link and /teams/{slug}/projects, on Acme
 * Foods' team Shop through the real firewall, voters, session and database: who may do each, what is changed and
 * which toast the team page then shows.
 */
final class TeamProjectsTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesCsrfTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string UNLINK = '/teams/shop/projects/unlink';
    private const string LINK = '/teams/shop/projects/link';
    private const string CREATE = '/teams/shop/projects';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
    }

    protected function tearDown(): void
    {
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function theLeadUnlinksAProjectOfHerTeam(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);
        $shopProject = $this->addProject($this->shop, 'SHOP', 'Web shop');
        $this->addProject($this->shop, 'CART', 'Checkout');

        $this->client->request('POST', self::UNLINK, $this->unlinkForm($shopProject));

        self::assertSame('SHOP removed from the team', $this->toastOfTheTeamPage());
        self::assertSame(['CART' => $this->shop->toString(), 'SHOP' => null], $this->owningTeamsByKey());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function peopleWhoAreNotShopsLead(): iterable
    {
        yield 'Omar, a member' => ['Omar'];
        yield 'Mia, a viewer' => ['Mia'];
        yield 'Raj, a Manager' => ['Raj'];
        yield 'Sara, an Admin' => ['Sara'];
    }

    #[Test]
    #[DataProvider('peopleWhoAreNotShopsLead')]
    public function onlyTheLeadMayUnlink(string $person): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()[$person]);
        $shopProject = $this->addProject($this->shop, 'SHOP', 'Web shop');

        $this->client->request('POST', self::UNLINK, $this->unlinkForm($shopProject));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['SHOP' => $this->shop->toString()], $this->owningTeamsByKey());
    }

    #[Test]
    public function theLeadOfAnotherTeamMayNotUnlinkLinkOrCreate(): void
    {
        $people = $this->createAcmeFoodsPeople(miaTeamRole: null);
        $this->createTeam($this->acmeFoods, 'Stock', $people['Mia']);
        $this->logIn($this->client, $people['Mia']);
        $shopProject = $this->addProject($this->shop, 'SHOP', 'Web shop');
        $unlinkedProject = $this->addProject(null, 'APP', 'Mobile app');

        $this->client->request('POST', self::UNLINK, $this->unlinkForm($shopProject));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', self::LINK, $this->linkForm($unlinkedProject));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', self::CREATE, $this->createForm('PAY', 'Payments'));
        self::assertResponseStatusCodeSame(403);

        self::assertSame(['APP' => null, 'SHOP' => $this->shop->toString()], $this->owningTeamsByKey());
    }

    #[Test]
    public function aLeadMayNotUnlinkAnotherTeamsProjectThroughHerOtherTeam(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);
        $shopProject = $this->addProject($this->shop, 'SHOP', 'Web shop');

        $this->client->request('POST', '/teams/mobile/projects/unlink', $this->unlinkForm($shopProject));

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['SHOP' => $this->shop->toString()], $this->owningTeamsByKey());
    }

    #[Test]
    public function aProjectWithOpenTicketsIsNotUnlinked(): void
    {
        $this->client->disableReboot();
        self::getContainer()->set(TicketOpenTicketCounts::class, new readonly class implements OpenTicketCounts {
            public function openTicketsIn(ProjectId ...$projectIds): array
            {
                return array_fill_keys(array_map(static fn (ProjectId $projectId): string => $projectId->toString(), $projectIds), 2);
            }
        });
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);
        $shopProject = $this->addProject($this->shop, 'SHOP', 'Web shop');

        $this->client->request('POST', self::UNLINK, $this->unlinkForm($shopProject));

        self::assertSame('2 open tickets are still in this project. Close them first.', $this->toastOfTheTeamPage());
        self::assertSame(['SHOP' => $this->shop->toString()], $this->owningTeamsByKey());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function peopleWhoMayLinkAndCreate(): iterable
    {
        yield 'Lena, the lead' => ['Lena'];
        yield 'Sara, an Admin of no team' => ['Sara'];
    }

    #[Test]
    #[DataProvider('peopleWhoMayLinkAndCreate')]
    public function theLeadOrAnAdminLinksProjectsWithNoTeamInTheOrderChosen(string $person): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()[$person]);
        $app = $this->addProject(null, 'APP', 'Mobile app');
        $cart = $this->addProject(null, 'CART', 'Checkout');
        $this->addProject(null, 'STOCK', 'Stock');

        $this->client->request('POST', self::LINK, $this->linkForm($cart, $app));

        self::assertSame('Added CART, APP', $this->toastOfTheTeamPage());
        self::assertSame(['APP' => $this->shop->toString(), 'CART' => $this->shop->toString(), 'STOCK' => null], $this->owningTeamsByKey());
    }

    #[Test]
    #[DataProvider('peopleWhoMayLinkAndCreate')]
    public function theLeadOrAnAdminCreatesAProjectOfTheTeam(string $person): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()[$person]);

        $this->client->request('POST', self::CREATE, $this->createForm('PAY', 'Payments'));

        self::assertSame('Added PAY', $this->toastOfTheTeamPage());
        self::assertSame(['PAY' => $this->shop->toString()], $this->owningTeamsByKey());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function peopleWhoMayNotLinkOrCreate(): iterable
    {
        yield 'Omar, a member' => ['Omar'];
        yield 'Mia, a viewer' => ['Mia'];
        yield 'Raj, a Manager' => ['Raj'];
    }

    #[Test]
    #[DataProvider('peopleWhoMayNotLinkOrCreate')]
    public function nobodyButTheLeadOrAnAdminLinksOrCreates(string $person): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()[$person]);
        $app = $this->addProject(null, 'APP', 'Mobile app');

        $this->client->request('POST', self::LINK, $this->linkForm($app));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', self::CREATE, $this->createForm('PAY', 'Payments'));
        self::assertResponseStatusCodeSame(403);

        self::assertSame(['APP' => null], $this->owningTeamsByKey());
    }

    #[Test]
    public function linkingIsAllOrNothingWhenOneProjectIsLinkedAlready(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);
        $app = $this->addProject(null, 'APP', 'Mobile app');
        $cart = $this->addProject($this->mobile, 'CART', 'Checkout');

        $this->client->request('POST', self::LINK, $this->linkForm($app, $cart));

        self::assertSame(TeamProjectsController::ALREADY_LINKED, $this->toastOfTheTeamPage());
        self::assertSame(['APP' => null, 'CART' => $this->mobile->toString()], $this->owningTeamsByKey());
    }

    #[Test]
    public function linkingNoProjectAsksForOne(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);
        $this->addProject(null, 'APP', 'Mobile app');

        $this->client->request('POST', self::LINK, ['_csrf_token' => $this->csrfTokenFor($this->client, TeamProjectsController::LINK_CSRF_ID)]);

        self::assertSame(TeamProjectsController::NO_PROJECT_CHOSEN, $this->toastOfTheTeamPage());
        self::assertSame(['APP' => null], $this->owningTeamsByKey());
    }

    #[Test]
    public function projectIdsThatAreNoListOfTextAreABadRequest(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);
        $app = $this->addProject(null, 'APP', 'Mobile app');

        $this->client->request('POST', self::LINK, [
            'project_ids' => [[$app]],
            '_csrf_token' => $this->csrfTokenFor($this->client, TeamProjectsController::LINK_CSRF_ID),
        ]);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(['APP' => null], $this->owningTeamsByKey());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function refusedNewProjects(): iterable
    {
        yield 'a key in lowercase' => ['pay', 'Payments', TeamProjectsController::KEY_NOT_VALID];
        yield 'a key with a space' => ['PAY ', 'Payments', TeamProjectsController::KEY_NOT_VALID];
        yield 'a blank name' => ['PAY', '   ', TeamProjectsController::NAME_MISSING];
        yield 'the key of a project of another team' => ['APP', 'Payments', 'Another project already uses the key APP.'];
    }

    #[Test]
    #[DataProvider('refusedNewProjects')]
    public function aRefusedNewProjectIsToldAndNothingIsCreated(string $key, string $name, string $toast): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);
        $this->addProject($this->mobile, 'APP', 'Mobile app');

        $this->client->request('POST', self::CREATE, $this->createForm($key, $name));

        self::assertSame($toast, $this->toastOfTheTeamPage());
        self::assertSame(['APP' => $this->mobile->toString()], $this->owningTeamsByKey());
    }

    #[Test]
    public function aFormTokenThatIsNotValidChangesNothing(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);
        $shopProject = $this->addProject($this->shop, 'SHOP', 'Web shop');
        $app = $this->addProject(null, 'APP', 'Mobile app');
        // Each action has a token id of its own, so the token of another action is refused too.
        $unlinkToken = $this->csrfTokenFor($this->client, TeamProjectsController::UNLINK_CSRF_ID);

        $this->client->request('POST', self::UNLINK, ['project_id' => $shopProject, '_csrf_token' => 'not-the-token']);
        self::assertSame(TeamProjectsController::FORM_EXPIRED, $this->toastOfTheTeamPage());
        $this->client->request('POST', self::LINK, ['project_ids' => [$app], '_csrf_token' => $unlinkToken]);
        self::assertSame(TeamProjectsController::FORM_EXPIRED, $this->toastOfTheTeamPage());
        $this->client->request('POST', self::CREATE, ['key' => 'PAY', 'name' => 'Payments', '_csrf_token' => $unlinkToken]);
        self::assertSame(TeamProjectsController::FORM_EXPIRED, $this->toastOfTheTeamPage());

        self::assertSame(['APP' => null, 'SHOP' => $this->shop->toString()], $this->owningTeamsByKey());
    }

    #[Test]
    public function aProjectOfAnotherOrganizationIsNotFound(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);
        $betaBakery = $this->createOrganization('Beta Bakery');
        $bakeryProject = $this->addProject(null, 'BREAD', 'Bread', $betaBakery);

        $this->client->request('POST', self::UNLINK, $this->unlinkForm($bakeryProject));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', self::LINK, $this->linkForm($bakeryProject));
        self::assertResponseStatusCodeSame(404);

        self::assertSame(['BREAD' => null], $this->owningTeamsByKey($betaBakery));
    }

    #[Test]
    public function anIdThatIsNoProjectIdIsNotFound(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Lena']);

        $this->client->request('POST', self::UNLINK, $this->unlinkForm('shop'));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', self::LINK, $this->linkForm('shop'));
        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function aSlugOfNoTeamIsNotFound(): void
    {
        $this->logIn($this->client, $this->createAcmeFoodsPeople()['Sara']);
        $app = $this->addProject(null, 'APP', 'Mobile app');

        $this->client->request('POST', '/teams/web/projects/link', $this->linkForm($app));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/teams/web/projects', $this->createForm('PAY', 'Payments'));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/teams/Shop/projects/unlink', $this->unlinkForm($app));
        self::assertResponseStatusCodeSame(404);

        self::assertSame(['APP' => null], $this->owningTeamsByKey());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function actions(): iterable
    {
        yield 'unlink' => [self::UNLINK];
        yield 'link' => [self::LINK];
        yield 'create' => [self::CREATE];
    }

    #[Test]
    #[DataProvider('actions')]
    public function anAnonymousVisitorIsSentToLogIn(string $action): void
    {
        $this->createAcmeFoodsPeople();
        $shopProject = $this->addProject($this->shop, 'SHOP', 'Web shop');

        $this->client->request('POST', $action, ['project_id' => $shopProject, 'project_ids' => [$shopProject], 'key' => 'PAY', 'name' => 'Payments']);

        self::assertResponseRedirects('/login', 302);
        self::assertSame(['SHOP' => $this->shop->toString()], $this->owningTeamsByKey());
    }

    /**
     * @return array<string, string>
     */
    private function unlinkForm(string $projectId): array
    {
        return ['project_id' => $projectId, '_csrf_token' => $this->csrfTokenFor($this->client, TeamProjectsController::UNLINK_CSRF_ID)];
    }

    /**
     * @return array<string, string|list<string>>
     */
    private function linkForm(string ...$projectIds): array
    {
        return ['project_ids' => array_values($projectIds), '_csrf_token' => $this->csrfTokenFor($this->client, TeamProjectsController::LINK_CSRF_ID)];
    }

    /**
     * @return array<string, string>
     */
    private function createForm(string $key, string $name): array
    {
        return ['key' => $key, 'name' => $name, '_csrf_token' => $this->csrfTokenFor($this->client, TeamProjectsController::CREATE_CSRF_ID)];
    }

    /**
     * Follows the action's redirect to the team page and reads the toast it shows.
     */
    private function toastOfTheTeamPage(): string
    {
        self::assertResponseRedirects('/teams/shop', 302);
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);

        return (string) $page->filter('.toast-region [data-toast-message-value]')->attr('data-toast-message-value');
    }

    /**
     * Through the migrator, as committed, under the organization as tenant.
     *
     * @return array<string, ?string> the owning team's id by project key, null for no team
     */
    private function owningTeamsByKey(?OrganizationId $organizationId = null): array
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->reset();
        $tenantContext->set($organizationId ?? $this->acmeFoods);
        try {
            /** @var array<string, ?string> $owningTeams */
            $owningTeams = $this->migrator()->transactional(static fn (Connection $migrator): array => $migrator->fetchAllKeyValue('SELECT key, team_id FROM tkt_project ORDER BY key'));
        } finally {
            $tenantContext->reset();
        }

        return $owningTeams;
    }
}
