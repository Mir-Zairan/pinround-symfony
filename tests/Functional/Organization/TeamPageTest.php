<?php

declare(strict_types=1);

namespace App\Tests\Functional\Organization;

use App\Identity\Domain\User;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesTeams;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The team page, /teams/{slug}, of Acme Foods' team Shop (slug "shop"): who TeamPermission::VIEW lets in, and that a
 * slug is looked up in the request's organization only.
 */
final class TeamPageTest extends WebTestCase
{
    use CreatesTeams;
    use Factories;
    use LogsInUsers;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
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
     * @param 'member'|'viewer'|null $miaTeamRole Mia's role in Shop, null for none
     *
     * @return array<string, User> by first name
     */
    private function createAcmeFoodsPeople(?string $miaTeamRole = 'viewer'): array
    {
        $acmeFoods = $this->createOrganization('Acme Foods');
        $people = [];
        foreach (['Lena' => '{}', 'Omar' => '{}', 'Mia' => '{}', 'Raj' => '{manager}', 'Sara' => '{admin}'] as $name => $orgRoles) {
            $people[$name] = $this->createUserWithoutOrganization($name);
            $this->addMembership($people[$name], $acmeFoods);
            $this->setOrgRoles($people[$name], $acmeFoods, $orgRoles);
        }

        $shop = $this->createTeam($acmeFoods, 'Shop', $people['Lena']);
        $this->addTeamRole($people['Omar'], $acmeFoods, $shop, 'member');
        if (null !== $miaTeamRole) {
            $this->addTeamRole($people['Mia'], $acmeFoods, $shop, $miaTeamRole);
        }

        return $people;
    }
}
