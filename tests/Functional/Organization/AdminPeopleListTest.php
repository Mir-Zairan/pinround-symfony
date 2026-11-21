<?php

declare(strict_types=1);

namespace App\Tests\Functional\Organization;

use App\Organization\Application\PeoplePageCursor;
use App\Organization\Domain\TeamSlug;
use App\Organization\UI\Admin\PeopleController;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Identity\TurnsOnTwoStepLogin;
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
 * The list of people on Admin › People and roles (P14.T3), read only, through the real firewall and code login over
 * HTTPS. Sara is the Admin who logs in; the other people are rows in idn_user and org_membership, as they never log in.
 * The clock is fixed on 16 Nov 2026, before the day this was written: a clock ahead of the wall clock would end the
 * session, whose idle check reads the session's own time.
 */
final class AdminPeopleListTest extends WebTestCase
{
    use Factories;
    use LogsInAdmins;
    use LogsInUsers;
    use TurnsOnTwoStepLogin;

    private const string PEOPLE_PAGE = '/admin/people';
    private const string NOW = '2026-11-16 14:00:00 UTC';

    private KernelBrowser $client;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $this->client->disableReboot();
        $this->clock = new MockClock(self::NOW);
        self::getContainer()->set('clock', $this->clock);
    }

    /**
     * Added out of name order. Omar is an Admin of Beta Bakery too, which must not give him the tag here, and Beta
     * Bakery's Anna would come first were she to leak in.
     */
    #[Test]
    public function saraSeesEveryoneOfHerOrganizationByNameWithTheirRolesAndTeams(): void
    {
        [$sara, $acmeFoods] = $this->memberWithOrgRoles('Sara Holm', '{admin}');
        $raj = $this->addPerson($acmeFoods, 'Raj Patel', '{manager}');
        $omar = $this->addPerson($acmeFoods, 'Omar Haddad');
        $this->addPerson($acmeFoods, 'Mia Lund');
        $lena = $this->addPerson($acmeFoods, 'Lena Berg');
        $shop = $this->addTeam($acmeFoods, 'Shop', $lena, 'developer');
        $payments = $this->addTeam($acmeFoods, 'Payments', $raj, 'other');
        $this->addTeamMember($acmeFoods, $shop, $omar, 'member', 'tester');
        $this->addTeamMember($acmeFoods, $payments, $omar, 'viewer', 'other');
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addPerson($betaBakery, 'Anna Baker', '{admin}');
        $this->addExistingPerson($betaBakery, $omar, '{admin}');
        $this->logInWithACode($this->client, $this->clock, $sara);

        $page = $this->client->request('GET', self::PEOPLE_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('People', $page->filter('main #people-members-title')->text());
        self::assertSame([
            ['Lena Berg', [], ['Developer · Shop · Team lead']],
            ['Mia Lund', [], ['No team yet']],
            ['Omar Haddad', [], ['Payments · Team viewer', 'Tester · Shop · Team member']],
            ['Raj Patel', ['Manager'], ['Payments · Team lead']],
            ['Sara Holm', ['Admin'], ['No team yet']],
        ], $this->peopleOn($page));
        self::assertCount(0, $page->filter('main .people__members a'));
    }

    /**
     * Added from the last name to the first, so their ids run against the name order.
     */
    #[Test]
    public function showMoreGivesTheNextPeopleWithNoGapOrRepeatAndKeepsThePagesOfTheOtherLists(): void
    {
        [$sara, $acmeFoods] = $this->memberWithOrgRoles('Sara Holm', '{admin}');
        for ($number = PeopleController::PAGE_SIZE + 1; $number >= 1; --$number) {
            $this->addPerson($acmeFoods, \sprintf('Omar %02d', $number));
        }
        $this->logInWithACode($this->client, $this->clock, $sara);
        $otherListsPages = [
            PeopleController::AFTER_INVITED_AT => '2026-11-16T13:59:00.000000+00:00',
            PeopleController::AFTER_ID => '0198f000-0000-7000-8000-0000000001ff',
            PeopleController::TOKENS_AFTER_EXPIRES_AT => '2099-01-01T00:00:00+00:00',
            PeopleController::TOKENS_AFTER_ID => '0198f000-0000-7000-8000-0000000002ff',
            PeopleController::CONNECTIONS_AFTER_CONNECTED_AT => '2099-01-01T00:00:00+00:00',
            PeopleController::CONNECTIONS_AFTER_ID => '0198f000-0000-7000-8000-0000000003ff',
        ];

        $page = $this->client->request('GET', self::PEOPLE_PAGE, $otherListsPages);

        self::assertResponseStatusCodeSame(200);
        $firstPage = array_column($this->peopleOn($page), 0);
        self::assertSame(array_map(static fn (int $number): string => \sprintf('Omar %02d', $number), range(1, PeopleController::PAGE_SIZE)), $firstPage);
        $showMore = $page->filter('main .people__members a.btn')->reduce(static fn (Crawler $link): bool => 'Show more' === $link->text());
        self::assertCount(1, $showMore);
        $lastShownId = $page->filter('main .people__person')->last()->attr('data-user-id');
        self::assertIsString($lastShownId);
        self::assertSame(
            '/admin/people?after_invited_at=2026-11-16T13:59:00.000000%2B00:00&after_id=0198f000-0000-7000-8000-0000000001ff'
            .'&tokens_after_expires_at=2099-01-01T00:00:00%2B00:00&tokens_after_id=0198f000-0000-7000-8000-0000000002ff'
            .'&connections_after_connected_at=2099-01-01T00:00:00%2B00:00&connections_after_id=0198f000-0000-7000-8000-0000000003ff'
            .\sprintf('&people_after_name=omar%%20%02d&people_after_id=%s', PeopleController::PAGE_SIZE, $lastShownId),
            $showMore->attr('href'),
        );

        $page = $this->client->click($showMore->link());

        self::assertResponseStatusCodeSame(200);
        self::assertSame([\sprintf('Omar %02d', PeopleController::PAGE_SIZE + 1), 'Sara Holm'], array_column($this->peopleOn($page), 0));
        self::assertCount(0, $page->filter('main .people__members a'));
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function notAPageOfPeople(): iterable
    {
        $id = '0198f000-0000-7000-8000-000000000001';
        yield 'the name only' => [[PeopleController::PEOPLE_AFTER_NAME => 'mia lund']];
        yield 'the id only' => [[PeopleController::PEOPLE_AFTER_ID => $id]];
        yield 'an id that is no uuid' => [[PeopleController::PEOPLE_AFTER_NAME => 'mia lund', PeopleController::PEOPLE_AFTER_ID => 'not-a-uuid']];
        yield 'an empty id' => [[PeopleController::PEOPLE_AFTER_NAME => 'mia lund', PeopleController::PEOPLE_AFTER_ID => '']];
        yield 'an id with a trailing newline' => [[PeopleController::PEOPLE_AFTER_NAME => 'mia lund', PeopleController::PEOPLE_AFTER_ID => $id."\n"]];
        yield 'a name past the cap' => [[PeopleController::PEOPLE_AFTER_NAME => str_repeat('ä', PeoplePageCursor::NAME_KEY_MAX_LENGTH + 1), PeopleController::PEOPLE_AFTER_ID => $id]];
        yield 'a name that is no UTF-8' => [[PeopleController::PEOPLE_AFTER_NAME => "mia \xFF", PeopleController::PEOPLE_AFTER_ID => $id]];
    }

    /**
     * @param array<string, string> $query
     */
    #[Test]
    #[DataProvider('notAPageOfPeople')]
    public function aPartOrBadPeopleCursorIsRefused(array $query): void
    {
        [$sara] = $this->memberWithOrgRoles('Sara Holm', '{admin}');
        $this->logInWithACode($this->client, $this->clock, $sara);

        $page = $this->client->request('GET', self::PEOPLE_PAGE, $query);

        self::assertResponseStatusCodeSame(400);
        self::assertCount(0, $page->filter('.people__person'));
    }

    /**
     * Exactly the cap, in characters that take two bytes each, so a cap counted in bytes would refuse it.
     */
    #[Test]
    public function aNameAtTheCapIsAPlaceInTheList(): void
    {
        [$sara] = $this->memberWithOrgRoles('Sara Holm', '{admin}');
        $this->logInWithACode($this->client, $this->clock, $sara);

        $page = $this->client->request('GET', self::PEOPLE_PAGE, [
            PeopleController::PEOPLE_AFTER_NAME => str_repeat('ä', PeoplePageCursor::NAME_KEY_MAX_LENGTH),
            PeopleController::PEOPLE_AFTER_ID => '0198f000-0000-7000-8000-000000000001',
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('main .people__person'));
    }

    /**
     * Mia leaves after the page that ended on her was shown: the next page goes on from her place.
     */
    #[Test]
    public function aPageAfterSomeoneWhoLeftGoesOnFromTheirPlace(): void
    {
        [$sara, $acmeFoods] = $this->memberWithOrgRoles('Sara Holm', '{admin}');
        $this->addPerson($acmeFoods, 'Lena Berg');
        $mia = $this->addPerson($acmeFoods, 'Mia Lund');
        $this->addPerson($acmeFoods, 'Omar Haddad');
        $this->committedAs($acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'DELETE FROM org_membership WHERE organization_id = ? AND user_id = ?',
            [$acmeFoods->toString(), $mia],
        ));
        $this->logInWithACode($this->client, $this->clock, $sara);

        $page = $this->client->request('GET', self::PEOPLE_PAGE, [PeopleController::PEOPLE_AFTER_NAME => 'mia lund', PeopleController::PEOPLE_AFTER_ID => $mia]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Omar Haddad', 'Sara Holm'], array_column($this->peopleOn($page), 0));
    }

    /**
     * Mia is renamed after the page that ended on her was shown: the next page goes on from her old place, so Omar is
     * not skipped, and Mia shows at her new place.
     */
    #[Test]
    public function aPageAfterSomeoneRenamedGoesOnFromTheirOldPlace(): void
    {
        [$sara, $acmeFoods] = $this->memberWithOrgRoles('Sara Holm', '{admin}');
        $this->addPerson($acmeFoods, 'Lena Berg');
        $mia = $this->addPerson($acmeFoods, 'Mia Lund');
        $this->addPerson($acmeFoods, 'Omar Haddad');
        $this->migrator()->executeStatement('UPDATE idn_user SET name = ? WHERE id = ?', ['Raj Lund', $mia]);
        $this->logInWithACode($this->client, $this->clock, $sara);

        $page = $this->client->request('GET', self::PEOPLE_PAGE, [PeopleController::PEOPLE_AFTER_NAME => 'mia lund', PeopleController::PEOPLE_AFTER_ID => $mia]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Omar Haddad', 'Raj Lund', 'Sara Holm'], array_column($this->peopleOn($page), 0));
    }

    /**
     * Past the last person, a later page says there is nobody more and links back to the first page of people, with
     * the invitations' page kept; "No one has joined yet." would be wrong there.
     */
    #[Test]
    public function aLaterPageWithNobodyLeftLinksBackToTheFirstPage(): void
    {
        [$sara] = $this->memberWithOrgRoles('Sara Holm', '{admin}');
        $this->logInWithACode($this->client, $this->clock, $sara);

        $page = $this->client->request('GET', self::PEOPLE_PAGE, [
            PeopleController::PEOPLE_AFTER_NAME => 'sara holm',
            PeopleController::PEOPLE_AFTER_ID => $sara->id()->toString(),
            PeopleController::AFTER_INVITED_AT => '2026-11-16T13:59:00.000000+00:00',
            PeopleController::AFTER_ID => '0198f000-0000-7000-8000-0000000001ff',
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('main .people__person'));
        self::assertCount(0, $page->filter('main .people__members .empty-state'));
        self::assertStringNotContainsString('No one has joined yet.', $page->filter('main .people__members')->text());
        $firstPageLink = $page->filter('main .people__members a');
        self::assertCount(1, $firstPageLink);
        self::assertSame('Back to the first page', $firstPageLink->text());
        self::assertSame('/admin/people?after_invited_at=2026-11-16T13:59:00.000000%2B00:00&after_id=0198f000-0000-7000-8000-0000000001ff', $firstPageLink->attr('href'));

        $page = $this->client->click($firstPageLink->link());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Sara Holm'], array_column($this->peopleOn($page), 0));
    }

    /**
     * Refused before the cursor is read, with one that would show the second page to an Admin.
     */
    #[Test]
    public function aManagerGetsNotFoundAndSeesNobody(): void
    {
        [$raj, $acmeFoods] = $this->memberWithOrgRoles('Raj Patel', '{manager}');
        $lena = $this->addPerson($acmeFoods, 'Lena Berg');
        $this->logInWithACode($this->client, $this->clock, $raj);

        $page = $this->client->request('GET', self::PEOPLE_PAGE, [PeopleController::PEOPLE_AFTER_NAME => 'lena berg', PeopleController::PEOPLE_AFTER_ID => $lena]);

        self::assertResponseStatusCodeSame(404);
        self::assertCount(0, $page->filter('.people__person'));
    }

    /**
     * Every person on the page is in a team, so a read per person or per team would show as more queries.
     */
    #[Test]
    public function thePageReadsTheSameNumberOfQueriesForTwoPeopleAndForThirty(): void
    {
        [$sara, $acmeFoods] = $this->memberWithOrgRoles('Sara Holm', '{admin}');
        $lena = $this->addPerson($acmeFoods, 'Lena Berg');
        $shop = $this->addTeam($acmeFoods, 'Shop', $lena, 'developer');
        $this->addTeamMember($acmeFoods, $shop, $sara->id()->toString(), 'viewer', 'other');
        $this->logInWithACode($this->client, $this->clock, $sara);
        // The first request after the login pays for loading the session's user once.
        $this->queryCountOfThePage();
        $twoPeopleQueryCount = $this->queryCountOfThePage();
        self::assertCount(2, $this->client->getCrawler()->filter('main .people__person'));
        for ($number = 1; $number <= 28; ++$number) {
            $person = $this->addPerson($acmeFoods, \sprintf('Omar %02d', $number));
            $this->addTeamMember($acmeFoods, $this->addTeam($acmeFoods, \sprintf('Team %02d', $number), $person, 'tester'), $lena, 'member', 'developer');
        }

        $thirtyPeopleQueryCount = $this->queryCountOfThePage();

        self::assertCount(PeopleController::PAGE_SIZE, $this->client->getCrawler()->filter('main .people__person'));
        self::assertSame($twoPeopleQueryCount, $thirtyPeopleQueryCount);
    }

    private function queryCountOfThePage(): int
    {
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();
        $this->client->request('GET', self::PEOPLE_PAGE);
        self::assertResponseStatusCodeSame(200);

        $queryCount = 0;
        foreach ($debugData->getData() as $queriesOfOneConnection) {
            $queryCount += \count($queriesOfOneConnection);
        }

        return $queryCount;
    }

    /**
     * @return list<array{string, list<string>, list<string>}> each person's name, role tags and team lines, as shown
     */
    private function peopleOn(Crawler $page): array
    {
        return $page->filter('main .people__person')->each(static fn (Crawler $person): array => [
            $person->filter('.people__name')->text(),
            $person->filter('.tag')->each(static fn (Crawler $tag): string => $tag->text()),
            $person->filter('.people__sub')->each(static fn (Crawler $line): string => $line->text()),
        ]);
    }

    /**
     * A person who never logs in: a user row and a membership, no password.
     *
     * @param string $orgRoles a PostgreSQL text[] literal, such as '{admin}'
     *
     * @return string the user id
     */
    private function addPerson(OrganizationId $organizationId, string $name, string $orgRoles = '{}'): string
    {
        $userId = Uuid::v7()->toRfc4122();
        $this->migrator()->executeStatement(
            "INSERT INTO idn_user (id, email, name, status, locale, time_zone, created_at, session_stamp) VALUES (?, ?, ?, 'active', 'en', 'UTC', '2026-11-08 09:00:00+00', 'a-stamp')",
            [$userId, 'person.'.$userId.'@acme.test', $name],
        );
        $this->createdUserIds[] = $userId;
        $this->addExistingPerson($organizationId, $userId, $orgRoles);

        return $userId;
    }

    private function addExistingPerson(OrganizationId $organizationId, string $userId, string $orgRoles): void
    {
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO org_membership (organization_id, user_id, org_roles, joined_at) VALUES (?, ?, ?, '2026-11-08 09:00:00+00')",
            [$organizationId->toString(), $userId, $orgRoles],
        ));
    }

    /**
     * @return string the team id
     */
    private function addTeam(OrganizationId $organizationId, string $name, string $leadUserId, string $leadJob): string
    {
        $teamId = Uuid::v7()->toRfc4122();
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO org_team (organization_id, id, name, slug, lead_user_id, created_at) VALUES (?, ?, ?, ?, ?, '2026-11-08 09:00:00+00')",
            [$organizationId->toString(), $teamId, $name, TeamSlug::fromName($name)->toString(), $leadUserId],
        ));
        $this->addTeamMember($organizationId, $teamId, $leadUserId, 'lead', $leadJob);

        return $teamId;
    }

    private function addTeamMember(OrganizationId $organizationId, string $teamId, string $userId, string $teamRole, string $job): void
    {
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO org_team_member (organization_id, team_id, user_id, team_role, job, created_at) VALUES (?, ?, ?, ?, ?, '2026-11-08 09:00:00+00')",
            [$organizationId->toString(), $teamId, $userId, $teamRole, $job],
        ));
    }
}
