<?php

declare(strict_types=1);

namespace App\Tests\Functional\Organization;

use App\Identity\Domain\User;
use App\Organization\Domain\Invitation;
use App\Organization\Domain\InvitationId;
use App\Organization\Domain\Invitations;
use App\Organization\Infrastructure\Doctrine\DoctrineInvitations;
use App\Organization\UI\Admin\PeopleController;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Identity\TurnsOnTwoStepLogin;
use App\Tests\Functional\Web\CreatesTeams;
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
 * The admin page of people, /admin/people, through the real firewall, login form and code page over HTTPS. The client
 * keeps its kernel, so the clock set in the container is the one every request reads; it runs 7 minutes ahead of the
 * wall clock, so an invitation made with the wall clock's time shows up as wrong.
 */
final class AdminPeopleTest extends WebTestCase
{
    use CreatesTeams;
    use Factories;
    use LogsInAdmins;
    use LogsInUsers;
    use TurnsOnTwoStepLogin;

    private const string PEOPLE_PAGE = '/admin/people';
    private const string INVITE = '/admin/people/invite';
    private const string NOT_JOINED_YET = 'Invited · not joined yet';

    private KernelBrowser $client;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $this->client->disableReboot();
        $this->clock = new MockClock();
        $this->clock->modify('+7 minutes');
        self::getContainer()->set('clock', $this->clock);
    }

    #[Test]
    public function anAdminWithTwoStepProofSeesTheInviteFormAndOnlyTheOpenInvites(): void
    {
        $acmeFoods = $this->adminLoggedInWithACode();
        $this->storeInvitation($acmeFoods, 'omar@acme.test', '1 hour', false);
        $this->storeInvitation($acmeFoods, 'lena@acme.test', '2 hours', false);
        $this->storeInvitation($acmeFoods, 'raj@acme.test', '3 hours', true);
        $this->storeInvitation($acmeFoods, 'mia@acme.test', '73 hours', false);

        $page = $this->client->request('GET', self::PEOPLE_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('People and roles', $page->filter('main h1')->text());
        self::assertSame('name@company.com', $page->filter('main form input[name="email"][type="email"]')->attr('placeholder'));
        self::assertSame('Invite', $page->filter('main form button[type="submit"]')->text());
        self::assertSame(['omar@acme.test', 'lena@acme.test'], $this->pendingEmailsOn($page));
        self::assertSame([self::NOT_JOINED_YET, self::NOT_JOINED_YET], $page->filter('main .people__row .people__sub')->each(static fn (Crawler $row): string => $row->text()));
        self::assertCount(0, $page->filter('.toast-region .toast, noscript'));
    }

    #[Test]
    public function withNoOpenInvitesThePageSaysSo(): void
    {
        $this->adminLoggedInWithACode();

        $page = $this->client->request('GET', self::PEOPLE_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertSame('No open invites.', $page->filter('main .empty-state')->text());
        self::assertCount(0, $page->filter('main .people__row'));
    }

    /**
     * The 20th and 21st invites share their time, so only the id in the cursor tells the second page where to start.
     */
    #[Test]
    public function theOpenInvitesShowTwentyAtATimeWithShowMoreForTheNext(): void
    {
        $acmeFoods = $this->adminLoggedInWithACode();
        $newestFirst = [];
        for ($minutes = 1; $minutes <= 19; ++$minutes) {
            $newestFirst[] = $email = \sprintf('mia+%d@acme.test', $minutes);
            $this->storeInvitation($acmeFoods, $email, $minutes.' minutes', false);
        }
        $this->storeInvitation($acmeFoods, 'mia+21@acme.test', '20 minutes', false, '0198f000-0000-7000-8000-000000000001');
        $this->storeInvitation($acmeFoods, 'mia+20@acme.test', '20 minutes', false, '0198f000-0000-7000-8000-000000000002');
        $newestFirst[] = 'mia+20@acme.test';

        $page = $this->client->request('GET', self::PEOPLE_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($newestFirst, $this->pendingEmailsOn($page));
        $showMore = $page->filter('main .people__pending a.btn')->reduce(static fn (Crawler $link): bool => 'Show more' === $link->text());
        self::assertCount(1, $showMore);

        $page = $this->client->click($showMore->link());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['mia+21@acme.test'], $this->pendingEmailsOn($page));
        self::assertCount(0, $page->filter('main .people__pending a'));
    }

    #[Test]
    public function exactlyTwentyOpenInvitesShowNoShowMore(): void
    {
        $acmeFoods = $this->adminLoggedInWithACode();
        for ($minutes = 1; $minutes <= PeopleController::PAGE_SIZE; ++$minutes) {
            $this->storeInvitation($acmeFoods, \sprintf('mia+%d@acme.test', $minutes), $minutes.' minutes', false);
        }

        $page = $this->client->request('GET', self::PEOPLE_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(PeopleController::PAGE_SIZE, $page->filter('main .people__row'));
        self::assertCount(0, $page->filter('main .people__pending a'));
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function notAPageOfInvites(): iterable
    {
        $at = '2026-11-10T09:30:00.250000+00:00';
        $id = '0198f000-0000-7000-8000-000000000001';
        yield 'the time only' => [[PeopleController::AFTER_INVITED_AT => $at]];
        yield 'the id only' => [[PeopleController::AFTER_ID => $id]];
        yield 'both empty' => [[PeopleController::AFTER_INVITED_AT => '', PeopleController::AFTER_ID => '']];
        yield 'an id that is no uuid' => [[PeopleController::AFTER_INVITED_AT => $at, PeopleController::AFTER_ID => 'not-a-uuid']];
        yield 'a time without microseconds' => [[PeopleController::AFTER_INVITED_AT => '2026-11-10T09:30:00+00:00', PeopleController::AFTER_ID => $id]];
        yield 'a day the month does not have' => [[PeopleController::AFTER_INVITED_AT => '2026-11-31T09:30:00.250000+00:00', PeopleController::AFTER_ID => $id]];
    }

    /**
     * @param array<string, string> $query
     */
    #[Test]
    #[DataProvider('notAPageOfInvites')]
    public function aPartOrBadPageCursorIsRefused(array $query): void
    {
        $this->adminLoggedInWithACode();

        $this->client->request('GET', self::PEOPLE_PAGE, $query);

        self::assertResponseStatusCodeSame(400);
    }

    #[Test]
    public function anInviteIsStoredWithNoRolesAndNoTeamAndShowsAToastOnce(): void
    {
        [$sara, $acmeFoods] = $this->memberWithOrgRoles('Sara Holm', '{admin}');
        $this->logInWithACode($this->client, $this->clock, $sara);
        $this->client->request('GET', self::PEOPLE_PAGE);
        self::assertResponseStatusCodeSame(200);

        $this->client->submitForm('Invite', ['email' => ' Mia@Acme.test ']);

        self::assertResponseRedirects(self::PEOPLE_PAGE, 302);
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(\sprintf(PeopleController::INVITE_SENT, 'Mia@Acme.test'), $this->toastMessageOn($page));
        self::assertSame(['mia@acme.test'], $this->pendingEmailsOn($page));
        self::assertSame(
            [['email' => 'mia@acme.test', 'org_roles' => '{}', 'team_id' => null, 'invited_by' => $sara->id()->toString(), 'created_at' => $this->clock->now()->getTimestamp()]],
            $this->storedInvitationsOf($acmeFoods),
        );

        $page = $this->client->request('GET', self::PEOPLE_PAGE);
        self::assertResponseStatusCodeSame(200);
        self::assertCount(0, $page->filter('.toast-region .toast, noscript'));
    }

    /**
     * Two invites of Mia while an older one is open: the other request's DELETE of the older one commits before this
     * request's DELETE runs, so this one deletes nothing, and the other request's INSERT commits before this one's
     * INSERT, which the real unique index then refuses. Committed in that order on the migrator connection, it leaves
     * what the real race leaves: the other request's invite only. Staging the other request's DELETE after this one's
     * would wait on this request's row lock, in this same thread, for ever.
     */
    #[Test]
    public function anInviteThatLostTheRaceToTheSameInviteShowsTheToastAndLeavesTheOtherRequestsInvite(): void
    {
        [$sara, $acmeFoods] = $this->memberWithOrgRoles('Sara Holm', '{admin}');
        $this->storeInvitation($acmeFoods, 'mia@acme.test', '1 hour', false);
        $otherRequestDeletes = fn () => $this->duringTheRequest(fn () => $this->committedAs($acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'DELETE FROM org_invitation WHERE organization_id = ? AND email = ? AND accepted_at IS NULL',
            [$acmeFoods->toString(), 'mia@acme.test'],
        )));
        $otherRequestInserts = fn () => $this->duringTheRequest(fn () => $this->storeInvitation($acmeFoods, 'mia@acme.test', '1 minute', false));
        // Set before the first request builds InviteMember, which keeps the repository it is given.
        self::getContainer()->set(DoctrineInvitations::class, new readonly class(new DoctrineInvitations(self::getContainer()->get('doctrine.orm.entity_manager')), $otherRequestDeletes, $otherRequestInserts) implements Invitations {
            public function __construct(private Invitations $invitations, private \Closure $otherRequestDeletes, private \Closure $otherRequestInserts)
            {
            }

            public function nextIdentity(): InvitationId
            {
                return $this->invitations->nextIdentity();
            }

            public function add(Invitation $invitation): void
            {
                ($this->otherRequestInserts)();
                $this->invitations->add($invitation);
            }

            public function save(Invitation $invitation): void
            {
                $this->invitations->save($invitation);
            }

            public function byId(InvitationId $id): ?Invitation
            {
                return $this->invitations->byId($id);
            }

            public function lockById(InvitationId $id): ?Invitation
            {
                return $this->invitations->lockById($id);
            }

            public function removeNotAcceptedFor(OrganizationId $organizationId, string $email): void
            {
                ($this->otherRequestDeletes)();
                $this->invitations->removeNotAcceptedFor($organizationId, $email);
            }
        });
        $this->logInWithACode($this->client, $this->clock, $sara);
        $this->client->request('GET', self::PEOPLE_PAGE);
        self::assertResponseStatusCodeSame(200);

        $this->client->submitForm('Invite', ['email' => 'mia@acme.test']);

        self::assertResponseRedirects(self::PEOPLE_PAGE, 302);
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(\sprintf(PeopleController::INVITE_SENT, 'mia@acme.test'), $this->toastMessageOn($page));
        self::assertSame(['mia@acme.test'], $this->pendingEmailsOn($page));
        self::assertSame(
            [['email' => 'mia@acme.test', 'created_at' => $this->clock->now()->modify('-1 minute')->getTimestamp()]],
            array_map(static fn (array $stored): array => ['email' => $stored['email'], 'created_at' => $stored['created_at']], $this->storedInvitationsOf($acmeFoods)),
        );

        $page = $this->client->request('GET', self::PEOPLE_PAGE);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['mia@acme.test'], $this->pendingEmailsOn($page));
    }

    #[Test]
    public function aSecondInviteOfTheSameEmailReplacesTheOpenOne(): void
    {
        $acmeFoods = $this->adminLoggedInWithACode();
        $this->client->request('GET', self::PEOPLE_PAGE);
        self::assertResponseStatusCodeSame(200);
        $this->client->submitForm('Invite', ['email' => 'mia@acme.test']);
        self::assertResponseRedirects(self::PEOPLE_PAGE, 302);
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        $this->clock->sleep(60);

        $this->client->submitForm('Invite', ['email' => 'MIA@acme.test']);

        self::assertResponseRedirects(self::PEOPLE_PAGE, 302);
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['mia@acme.test'], $this->pendingEmailsOn($page));
        $stored = $this->storedInvitationsOf($acmeFoods);
        self::assertCount(1, $stored);
        self::assertSame($this->clock->now()->getTimestamp(), $stored[0]['created_at']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAnEmailAddress(): iterable
    {
        yield 'blank' => ['  '];
        yield 'no at sign' => ['mia.acme.test'];
        yield 'no domain' => ['mia@'];
    }

    #[Test]
    #[DataProvider('notAnEmailAddress')]
    public function aValueThatIsNoEmailAddressShowsTheFieldErrorAndStoresNothing(string $typed): void
    {
        $acmeFoods = $this->adminLoggedInWithACode();
        $this->client->request('GET', self::PEOPLE_PAGE);
        self::assertResponseStatusCodeSame(200);

        $page = $this->client->submitForm('Invite', ['email' => $typed]);

        self::assertResponseStatusCodeSame(422);
        $this->assertEmailFieldError($page, PeopleController::EMAIL_INVALID, $typed);
        self::assertSame([], $this->storedInvitationsOf($acmeFoods));
    }

    #[Test]
    public function theEmailOfAMemberShowsTheFieldErrorAndStoresNothing(): void
    {
        $acmeFoods = $this->adminLoggedInWithACode();
        $omarsEmail = 'omar.'.bin2hex(random_bytes(4)).'@acme.test';
        $omar = $this->createUserWithoutOrganization('Omar Haddad', email: $omarsEmail);
        $this->addMembership($omar, $acmeFoods);
        $this->storeInvitation($acmeFoods, 'lena@acme.test', '1 hour', false);
        $this->client->request('GET', self::PEOPLE_PAGE);
        self::assertResponseStatusCodeSame(200);

        $page = $this->client->submitForm('Invite', ['email' => strtoupper($omarsEmail)]);

        self::assertResponseStatusCodeSame(422);
        $this->assertEmailFieldError($page, PeopleController::ALREADY_IN, strtoupper($omarsEmail));
        self::assertSame(['lena@acme.test'], $this->pendingEmailsOn($page));
        self::assertSame(['lena@acme.test'], array_column($this->storedInvitationsOf($acmeFoods), 'email'));
    }

    #[Test]
    public function anInviteWithAWrongCsrfTokenIsRefusedAndStoresNothing(): void
    {
        $acmeFoods = $this->adminLoggedInWithACode();
        $this->client->request('GET', self::PEOPLE_PAGE);
        self::assertResponseStatusCodeSame(200);

        $page = $this->client->submitForm('Invite', ['email' => 'mia@acme.test', '_csrf_token' => 'not-the-token']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(PeopleController::FORM_EXPIRED, $page->filter('main [role="alert"]')->text());
        self::assertSame([], $this->storedInvitationsOf($acmeFoods));
    }

    #[Test]
    public function anAdminWithoutTwoStepLoginIsSentToTheSetupPageAndInvitesNobody(): void
    {
        [$sara, $acmeFoods] = $this->memberWithOrgRoles('Sara Holm', '{admin}');
        $this->logInWithThePassword($this->client, $sara);

        $this->client->request('GET', self::PEOPLE_PAGE);
        self::assertResponseRedirects('/me/security/two-step', 302);

        $this->client->request('POST', self::INVITE, ['email' => 'mia@acme.test', '_csrf_token' => 'any']);
        self::assertResponseRedirects('/me/security/two-step', 302);
        self::assertSame([], $this->storedInvitationsOf($acmeFoods));
    }

    /**
     * @return iterable<string, array{string, 'lead'|'member'|'viewer'|null}>
     */
    public static function rolesBelowAdmin(): iterable
    {
        yield 'manager' => ['{manager}', null];
        yield 'lead' => ['{}', 'lead'];
        yield 'member' => ['{}', 'member'];
        yield 'viewer' => ['{}', 'viewer'];
    }

    /**
     * With a fresh code, so only the role refuses; the POST is refused before its CSRF token is checked, which would
     * answer 422.
     *
     * @param 'lead'|'member'|'viewer'|null $teamRole
     */
    #[Test]
    #[DataProvider('rolesBelowAdmin')]
    public function aPersonBelowAdminIsRefusedThePageAndTheInvite(string $orgRoles, ?string $teamRole): void
    {
        [$person, $acmeFoods] = $this->memberWithOrgRoles('Omar Haddad', $orgRoles);
        if (null !== $teamRole) {
            $this->giveTeamRole($person, $acmeFoods, $teamRole);
        }
        $this->logInWithACode($this->client, $this->clock, $person);

        $this->client->request('GET', self::PEOPLE_PAGE);
        self::assertResponseStatusCodeSame(403);

        // Refused before the cursor is read, so a bad one tells nothing either.
        $this->client->request('GET', self::PEOPLE_PAGE, [PeopleController::AFTER_ID => 'not-a-uuid']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', self::INVITE, ['email' => 'mia@acme.test', '_csrf_token' => 'any']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->storedInvitationsOf($acmeFoods));
    }

    #[Test]
    public function aVisitorWhoIsNotLoggedInIsSentToTheLoginPage(): void
    {
        $this->client->request('GET', self::PEOPLE_PAGE);
        self::assertResponseRedirects('/login', 302);

        $this->client->request('POST', self::INVITE, ['email' => 'mia@acme.test', '_csrf_token' => 'any']);
        self::assertResponseRedirects('/login', 302);
    }

    /**
     * @return OrganizationId the organization Sara is the Admin of
     */
    private function adminLoggedInWithACode(): OrganizationId
    {
        [$sara, $acmeFoods] = $this->memberWithOrgRoles('Sara Holm', '{admin}');
        $this->logInWithACode($this->client, $this->clock, $sara);

        return $acmeFoods;
    }

    /**
     * @param 'lead'|'member'|'viewer' $teamRole
     */
    private function giveTeamRole(User $person, OrganizationId $organizationId, string $teamRole): void
    {
        if ('lead' === $teamRole) {
            $this->createTeam($organizationId, 'Shop', $person);

            return;
        }
        $lena = $this->createUserWithoutOrganization('Lena Berg');
        $this->addMembership($lena, $organizationId);
        $this->addTeamRole($person, $organizationId, $this->createTeam($organizationId, 'Shop', $lena), $teamRole);
    }

    private function assertEmailFieldError(Crawler $page, string $message, string $typed): void
    {
        $field = $page->filter('main input[name="email"]');
        self::assertSame('true', $field->attr('aria-invalid'));
        self::assertSame('people-email-error', $field->attr('aria-describedby'));
        self::assertSame($message, $page->filter('main #people-email-error')->text());
        self::assertSame($typed, $field->attr('value'));
    }

    /**
     * Runs $work in the middle of a request and gives the request its organization back: committedAs leaves none.
     */
    private function duringTheRequest(\Closure $work): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $requestOrganization = $tenantContext->get();
        try {
            $work();
        } finally {
            if (null !== $requestOrganization) {
                $tenantContext->reset();
                $tenantContext->set($requestOrganization);
            }
        }
    }

    /**
     * The toast arrives empty and the toast controller writes this message in, so screen readers announce it.
     */
    private function toastMessageOn(Crawler $page): string
    {
        $toast = $page->filter('.toast-region .toast[data-controller="toast"]');
        self::assertCount(1, $toast);
        self::assertSame('', $toast->text());
        self::assertNotNull($toast->attr('data-turbo-temporary'));
        self::assertSame($toast->attr('data-toast-message-value'), $page->filter('noscript .toast')->text());

        return $toast->attr('data-toast-message-value');
    }

    /**
     * @return list<string>
     */
    private function pendingEmailsOn(Crawler $page): array
    {
        return $page->filter('main .people__row .people__email-address')->each(static fn (Crawler $row): string => $row->text());
    }

    /**
     * An invitation made $age before the clock's now, such as '2 hours', open for 72 hours from then, and accepted an
     * hour after it was made when $isAccepted; with a new id when $id is null.
     */
    private function storeInvitation(OrganizationId $organizationId, string $email, string $age, bool $isAccepted, ?string $id = null): void
    {
        $createdAt = $this->clock->now()->modify('-'.$age);
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'INSERT INTO org_invitation (organization_id, id, email, org_roles, invited_by, created_at, expires_at, accepted_at)'
            ." VALUES (?, ?, ?, '{}', ?, ?, ?, ?)",
            [
                $organizationId->toString(), $id ?? Uuid::v7()->toRfc4122(), $email, Uuid::v7()->toRfc4122(),
                $createdAt->format(\DATE_ATOM), $createdAt->modify('+72 hours')->format(\DATE_ATOM),
                $isAccepted ? $createdAt->modify('+1 hour')->format(\DATE_ATOM) : null,
            ],
        ));
    }

    /**
     * @return list<array{email: string, org_roles: string, team_id: string|null, invited_by: string, created_at: int}>
     */
    private function storedInvitationsOf(OrganizationId $organizationId): array
    {
        $invitations = [];
        $this->committedAs($organizationId, static function (Connection $migrator) use ($organizationId, &$invitations): int {
            $rows = $migrator->fetchAllAssociative(
                'SELECT email, org_roles, team_id, invited_by, extract(epoch FROM created_at)::int AS created_at FROM org_invitation WHERE organization_id = ? ORDER BY created_at',
                [$organizationId->toString()],
            );
            foreach ($rows as $row) {
                \assert(\is_string($row['email']) && \is_string($row['org_roles']) && (null === $row['team_id'] || \is_string($row['team_id'])) && \is_string($row['invited_by']) && \is_int($row['created_at']));
                $invitations[] = ['email' => $row['email'], 'org_roles' => $row['org_roles'], 'team_id' => $row['team_id'], 'invited_by' => $row['invited_by'], 'created_at' => $row['created_at']];
            }

            return 0;
        });

        return $invitations;
    }
}
