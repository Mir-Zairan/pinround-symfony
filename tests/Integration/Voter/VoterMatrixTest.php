<?php

declare(strict_types=1);

namespace App\Tests\Integration\Voter;

use App\Identity\Application\SessionConfirmations;
use App\Identity\Domain\Credential;
use App\Identity\Domain\Email;
use App\Identity\Domain\PasswordHash;
use App\Identity\Domain\TwoStepLogin;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Organization\Api\TeamPermission;
use App\Organization\Application\OpenInvitationLink;
use App\Organization\Domain\TeamSlug;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\OrganizationRows;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Ticketing\Api\TicketVisibilityFacts;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Decides every row of voter_matrix_rows.php through the app's access decision manager, with all its voters, for a
 * logged-in person in their own organization as tenant and inside a transaction, as a request would. Each row names
 * the text of personas-and-roles.md it comes from, and the doc must still have it.
 *
 * Every token is a password session, as the login form leaves it. A row with twoStepProof also has, in its rolled-back
 * transaction, the person's user and two-step login rows, and a request whose session holds their code proof (see
 * voter_matrix_rows.php for its ages); other
 * rows have neither, so no session.
 *
 * Acme Foods: Lena is an Admin and leads Shop and Mobile (as the demo seed has it); Omar is a member of both; Mia is a
 * member (tester) of Shop and a viewer of Support; Raj is a Manager and a viewer of Shop; Sara is an Admin with no team
 * role; Noor has no org role, leads Support and is the organization's triager. Beta Bakery: Ben is its Admin and leads
 * its one team, Checkout.
 */
final class VoterMatrixTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000009a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000009b1';
    private const string SHOP = '0197a3c0-0000-7000-8000-0000000009d1';
    private const string MOBILE = '0197a3c0-0000-7000-8000-0000000009d2';
    private const string CHECKOUT = '0197a3c0-0000-7000-8000-0000000009d3';
    private const string LATER_TEAM = '0197a3c0-0000-7000-8000-0000000009d4';
    private const string SUPPORT = '0197a3c0-0000-7000-8000-0000000009d5';

    /** Each person's user id and the organization they decide in. */
    private const array PEOPLE = [
        'Lena' => ['0197a3c0-0000-7000-8000-0000000009c1', self::ACME_FOODS],
        'Omar' => ['0197a3c0-0000-7000-8000-0000000009c2', self::ACME_FOODS],
        'Mia' => ['0197a3c0-0000-7000-8000-0000000009c3', self::ACME_FOODS],
        'Raj' => ['0197a3c0-0000-7000-8000-0000000009c4', self::ACME_FOODS],
        'Sara' => ['0197a3c0-0000-7000-8000-0000000009c5', self::ACME_FOODS],
        'Noor' => ['0197a3c0-0000-7000-8000-0000000009c6', self::ACME_FOODS],
        'Ben' => ['0197a3c0-0000-7000-8000-0000000009c7', self::BETA_BAKERY],
    ];

    private const array ORGANIZATIONS = ['Acme Foods' => self::ACME_FOODS, 'Beta Bakery' => self::BETA_BAKERY];

    private const string INVITATION = '0197a3c0-0000-7000-8000-0000000009e1';

    /** Each open invitation link to Acme Foods by the person who has an account with its email, null for nobody. */
    private const array INVITATIONS = ['Invitation of Omar' => 'Omar', 'Invitation of a new email' => null];

    private const array TEAMS = ['Shop' => self::SHOP, 'Mobile' => self::MOBILE, 'Support' => self::SUPPORT, 'Checkout' => self::CHECKOUT];

    /**
     * Each ticket of Acme Foods by its visibility, its team, null for a ticket with no team yet, and the person of
     * PEOPLE who reported it, null for REPORTER.
     */
    private const array TICKETS = [
        'Team-only Shop ticket' => [TicketVisibilityFacts::TEAM_ONLY, self::SHOP, null],
        'Team-only ticket with no team' => [TicketVisibilityFacts::TEAM_ONLY, null, null],
        'Team-only Support ticket reported by Mia' => [TicketVisibilityFacts::TEAM_ONLY, self::SUPPORT, 'Mia'],
        'Team-only Support ticket reported by Omar' => [TicketVisibilityFacts::TEAM_ONLY, self::SUPPORT, 'Omar'],
        'For-everyone Shop ticket' => [TicketVisibilityFacts::FOR_EVERYONE, self::SHOP, null],
        'For-everyone Shop ticket reported by Noor' => [TicketVisibilityFacts::FOR_EVERYONE, self::SHOP, 'Noor'],
    ];

    /** Reported the tickets no person of PEOPLE reported, so no row's actor sees those as their reporter. */
    private const string REPORTER = '0197a3c0-0000-7000-8000-0000000009c9';

    private const string ROLES_DOC = __DIR__.'/../../../docs/00-product/personas-and-roles.md';

    /** Not the wall clock's time, so a check that ignores the app clock decides differently. */
    private const string NOW = '2027-03-15 10:00:00 UTC';

    public static function setUpBeforeClass(): void
    {
        self::rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        self::transactions()->committedAs(self::ACME_FOODS, static function (): void {
            self::addMembership(self::ACME_FOODS, 'Lena', '{admin}');
            self::addMembership(self::ACME_FOODS, 'Omar', '{}');
            self::addMembership(self::ACME_FOODS, 'Mia', '{}');
            self::addMembership(self::ACME_FOODS, 'Raj', '{manager}');
            self::addMembership(self::ACME_FOODS, 'Sara', '{admin}');
            self::addMembership(self::ACME_FOODS, 'Noor', '{}');
            self::addTeam(self::ACME_FOODS, self::SHOP, 'Shop', 'Lena');
            self::addTeam(self::ACME_FOODS, self::MOBILE, 'Mobile', 'Lena');
            self::addTeam(self::ACME_FOODS, self::SUPPORT, 'Support', 'Noor');
            self::addTeamMember(self::ACME_FOODS, self::SHOP, 'Lena', 'lead', 'developer');
            self::addTeamMember(self::ACME_FOODS, self::SHOP, 'Omar', 'member', 'developer');
            self::addTeamMember(self::ACME_FOODS, self::SHOP, 'Mia', 'member', 'tester');
            self::addTeamMember(self::ACME_FOODS, self::SHOP, 'Raj', 'viewer', 'other');
            self::addTeamMember(self::ACME_FOODS, self::MOBILE, 'Lena', 'lead', 'developer');
            self::addTeamMember(self::ACME_FOODS, self::MOBILE, 'Omar', 'member', 'developer');
            self::addTeamMember(self::ACME_FOODS, self::SUPPORT, 'Noor', 'lead', 'developer');
            self::addTeamMember(self::ACME_FOODS, self::SUPPORT, 'Mia', 'viewer', 'tester');
            self::connection()->executeStatement(
                "INSERT INTO org_settings (organization_id, data, created_at) VALUES (?, jsonb_build_object('triager_id', ?::text), '2027-03-15 10:00:00+00')",
                [self::ACME_FOODS, self::PEOPLE['Noor'][0]],
            );
        });
        self::transactions()->committedAs(self::BETA_BAKERY, static function (): void {
            self::addMembership(self::BETA_BAKERY, 'Ben', '{admin}');
            self::addTeam(self::BETA_BAKERY, self::CHECKOUT, 'Checkout', 'Ben');
            self::addTeamMember(self::BETA_BAKERY, self::CHECKOUT, 'Ben', 'lead', 'developer');
        });
        self::ensureKernelShutdown();
    }

    public static function tearDownAfterClass(): void
    {
        self::rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        self::ensureKernelShutdown();
        parent::tearDownAfterClass();
    }

    /**
     * @return iterable<string, array{source: string, attribute: string, actor: string, subject: string, expected: string, twoStepProof?: bool|int|string}>
     */
    public static function matrixRows(): iterable
    {
        /** @var list<array{source: string, attribute: string, actor: string, subject: string, expected: string, twoStepProof?: bool|int|string}> $rows */
        $rows = require __DIR__.'/voter_matrix_rows.php';
        foreach ($rows as $row) {
            $twoStepProof = $row['twoStepProof'] ?? false;
            $proof = match (true) {
                false === $twoStepProof => '',
                true === $twoStepProof => ' (with a code proof)',
                'noCode' === $twoStepProof => ' (two-step login on, no code in the session)',
                \is_int($twoStepProof) => \sprintf(' (code proof %d seconds old)', $twoStepProof),
                default => throw new \InvalidArgumentException(\sprintf('A row\'s twoStepProof is true, a number of seconds or "noCode", not %s.', var_export($twoStepProof, true))),
            };
            yield \sprintf('%s %s %s%s', $row['actor'], $row['attribute'], $row['subject'], $proof) => $row;
        }
    }

    #[Test]
    #[DataProvider('matrixRows')]
    public function theAppDecidesTheRowAsTheRolesDocSays(string $source, string $attribute, string $actor, string $subject, string $expected, bool|int|string $twoStepProof = false): void
    {
        self::assertContains($expected, ['granted', 'denied'], 'A row expects granted or denied.');
        self::assertArrayHasKey($actor, self::PEOPLE, 'The actor is one of VoterMatrixTest::PEOPLE.');
        [, $organizationId] = self::PEOPLE[$actor];

        self::ensureKernelShutdown();
        $clock = new MockClock(self::NOW);
        self::getContainer()->set('clock', $clock);
        $accessDecision = new AccessDecision();
        $isGranted = self::transactions()->rolledBackAs($organizationId, function () use ($actor, $attribute, $subject, $accessDecision, $twoStepProof, $clock): bool {
            $requestStack = false !== $twoStepProof ? self::requestWithCodeProofOf($actor, $clock, $twoStepProof) : null;
            try {
                return $this->accessDecisionManager()->decide(self::tokenOf($actor), [$attribute], self::subjectNamed($subject), $accessDecision);
            } finally {
                $requestStack?->pop();
            }
        });

        self::assertNotSame([], self::votersThatDecided($accessDecision), \sprintf('No voter decides %s on %s; is the attribute or subject wrong?', $attribute, $subject));
        self::assertSame($expected, $isGranted ? 'granted' : 'denied', \sprintf('%s %s %s, from "%s"', $actor, $attribute, $subject, $source));
    }

    #[Test]
    #[DataProvider('matrixRows')]
    public function theRolesDocHasTheTextTheRowComesFrom(string $source, string $attribute, string $actor, string $subject, string $expected, bool|int|string $twoStepProof = false): void
    {
        self::assertStringContainsString($source, (string) file_get_contents(self::ROLES_DOC), \sprintf('The row "%s %s %s" names text the roles doc does not have.', $actor, $attribute, $subject));
    }

    #[Test]
    public function theAppDecidesUnanimouslyAndDeniesWhenEveryVoterAbstains(): void
    {
        $accessDecision = new AccessDecision();

        $isGranted = self::transactions()->rolledBackAs(
            self::ACME_FOODS,
            fn (): bool => $this->accessDecisionManager()->decide(self::tokenOf('Sara'), ['NO_VOTER_DECIDES_THIS'], new OrganizationId(self::ACME_FOODS), $accessDecision),
        );

        self::assertSame('unanimous', (string) $accessDecision->strategy);
        self::assertFalse($isGranted);
        self::assertSame([], self::votersThatDecided($accessDecision));
    }

    #[Test]
    public function aTeamAddedDuringARequestIsSeenByAnAdminFromTheNextRequestOn(): void
    {
        $isGranted = fn (): bool => self::transactions()->rolledBackAs(
            self::ACME_FOODS,
            fn (): bool => $this->accessDecisionManager()->decide(self::tokenOf('Sara'), [TeamPermission::VIEW], new TeamId(self::LATER_TEAM)),
        );
        self::assertFalse($isGranted());

        try {
            self::transactions()->committedAs(self::ACME_FOODS, static function (): void {
                self::addTeam(self::ACME_FOODS, self::LATER_TEAM, 'Later', 'Lena');
            });
            self::assertFalse($isGranted(), 'Within one request the teams are read once.');

            self::getContainer()->get('services_resetter')->reset();

            self::assertTrue($isGranted());
        } finally {
            self::removeTeam(self::ACME_FOODS, self::LATER_TEAM);
        }
    }

    /**
     * @return list<string> the class of each voter that granted or denied
     */
    private static function votersThatDecided(AccessDecision $accessDecision): array
    {
        return array_values(array_map(
            static fn (Vote $vote): string => $vote->voter,
            array_filter($accessDecision->votes, static fn (Vote $vote): bool => VoterInterface::ACCESS_ABSTAIN !== $vote->result),
        ));
    }

    private static function subjectNamed(string $name): OrganizationId|TeamId|OpenInvitationLink|TicketVisibilityFacts
    {
        if (isset(self::ORGANIZATIONS[$name])) {
            return new OrganizationId(self::ORGANIZATIONS[$name]);
        }
        if (\array_key_exists($name, self::TICKETS)) {
            [$visibility, $teamId, $reporter] = self::TICKETS[$name];

            return new TicketVisibilityFacts(
                new OrganizationId(self::ACME_FOODS),
                $visibility,
                null === $teamId ? null : new TeamId($teamId),
                new UserId(null === $reporter ? self::REPORTER : self::PEOPLE[$reporter][0]),
            );
        }
        if (\array_key_exists($name, self::INVITATIONS)) {
            $invitee = self::INVITATIONS[$name];

            return new OpenInvitationLink(
                self::INVITATION,
                new OrganizationId(self::ACME_FOODS),
                null === $invitee ? 'new-joiner@acme-foods.example' : 'voter-matrix-'.strtolower($invitee).'@acme-foods.example',
                'Acme Foods',
                null === $invitee ? null : new UserId(self::PEOPLE[$invitee][0]),
            );
        }
        self::assertArrayHasKey($name, self::TEAMS, 'The subject is an organization, a team, an invitation or a ticket of VoterMatrixTest.');

        return new TeamId(self::TEAMS[$name]);
    }

    /**
     * A token as the login form leaves it; the person needs no idn_user row unless the row asks for a code proof.
     */
    private static function tokenOf(string $person): TokenInterface
    {
        $user = self::userOf($person);
        $hash = new PasswordHash('$argon2id$v=19$m=65536,t=4,p=1$NUkxU2sxQlFxbXpDaUZUQQ$suVp+0jzKZuqeONGVmUghGQxxI2vd02CMokdMtaQcfA');
        $securityUser = SecurityUser::of($user, new Credential($user, $hash, new \DateTimeImmutable('2026-11-02 09:30:00 UTC')));

        return new UsernamePasswordToken($securityUser, 'main', $securityUser->getRoles());
    }

    private static function userOf(string $person): User
    {
        return new User(
            new UserId(self::PEOPLE[$person][0]),
            new Email('voter-matrix-'.strtolower($person).'@acme-foods.example'),
            $person,
            'en',
            'Europe/Berlin',
            new \DateTimeImmutable('2026-11-02 09:30:00 UTC'),
        );
    }

    /**
     * In the row's transaction: the person's user row and their two-step login, turned on 2 hours before the clock's
     * now; then a request whose session holds their code proof: of 30 minutes ago for true, of that many seconds for an
     * int, none for 'noCode'.
     *
     * @return RequestStack the stack the request is pushed on, for the caller to pop it
     */
    private static function requestWithCodeProofOf(string $person, MockClock $clock, bool|int|string $proof): RequestStack
    {
        $user = self::userOf($person);
        $twoStepLogin = new TwoStepLogin($user, 'never decrypted here', $clock->now()->modify('-2 hours'));
        $twoStepLogin->confirm(1, $clock->now()->modify('-2 hours'), ['$argon2id$never-checked-here']);
        $entityManager = self::entityManager();
        $entityManager->persist($user);
        $entityManager->persist($twoStepLogin);
        $entityManager->flush();

        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);
        $proofAgeSeconds = match (true) {
            'noCode' === $proof => null,
            true === $proof => 1800,
            \is_int($proof) => $proof,
            default => throw new \InvalidArgumentException(\sprintf('A row\'s twoStepProof is true, a number of seconds or "noCode", not %s.', var_export($proof, true))),
        };
        if (null !== $proofAgeSeconds) {
            self::getContainer()->get(SessionConfirmations::class)->recordTwoStepConfirmation($user->id(), $clock->now()->modify('-'.$proofAgeSeconds.' seconds'));
        }

        return $requestStack;
    }

    private static function addMembership(string $organizationId, string $person, string $orgRoles): void
    {
        self::connection()->executeStatement(
            "INSERT INTO org_membership (organization_id, user_id, org_roles, joined_at) VALUES (?, ?, ?, '2027-03-15 10:00:00+00')",
            [$organizationId, self::PEOPLE[$person][0], $orgRoles],
        );
    }

    private static function addTeam(string $organizationId, string $teamId, string $name, string $lead): void
    {
        self::connection()->executeStatement(
            "INSERT INTO org_team (organization_id, id, name, slug, lead_user_id, created_at) VALUES (?, ?, ?, ?, ?, '2027-03-15 10:00:00+00')",
            [$organizationId, $teamId, $name, TeamSlug::fromName($name)->toString(), self::PEOPLE[$lead][0]],
        );
    }

    private static function addTeamMember(string $organizationId, string $teamId, string $person, string $teamRole, string $job): void
    {
        self::connection()->executeStatement(
            "INSERT INTO org_team_member (organization_id, team_id, user_id, team_role, job, created_at) VALUES (?, ?, ?, ?, ?, '2027-03-15 10:00:00+00')",
            [$organizationId, $teamId, self::PEOPLE[$person][0], $teamRole, $job],
        );
    }

    /**
     * Through the migrator, which owns org_team; pinround_app may not delete from it.
     */
    private static function removeTeam(string $organizationId, string $teamId): void
    {
        $tenantContext = self::tenantContext();
        $tenantContext->set(new OrganizationId($organizationId));
        try {
            self::migrator()->transactional(static fn (Connection $migrator): int|string => $migrator->executeStatement(
                'DELETE FROM org_team WHERE organization_id = ? AND id = ?',
                [$organizationId, $teamId],
            ));
        } finally {
            $tenantContext->clear();
        }
    }

    private function accessDecisionManager(): AccessDecisionManagerInterface
    {
        return self::getContainer()->get('security.access.decision_manager');
    }

    private static function rows(): OrganizationRows
    {
        return new OrganizationRows(self::migrator(), self::tenantContext());
    }

    private static function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::entityManager(), self::tenantContext());
    }

    private static function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private static function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private static function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private static function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
