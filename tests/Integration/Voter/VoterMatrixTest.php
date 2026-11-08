<?php

declare(strict_types=1);

namespace App\Tests\Integration\Voter;

use App\Identity\Domain\Credential;
use App\Identity\Domain\Email;
use App\Identity\Domain\PasswordHash;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Organization\Api\TeamPermission;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\OrganizationRows;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
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
 * Every token is a password session, as the login form leaves it, with no confirmed two-step login.
 *
 * Acme Foods: Lena is an Admin and leads Shop and Mobile (as the demo seed has it); Omar is a member of both; Mia is a
 * member (tester) of Shop and a viewer of Support; Raj is a Manager and a viewer of Shop; Sara is an Admin with no team
 * role; Noor has no org role and leads Support. Beta Bakery: Ben is its Admin and leads its one team, Checkout.
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

    private const array TEAMS = ['Shop' => self::SHOP, 'Mobile' => self::MOBILE, 'Support' => self::SUPPORT, 'Checkout' => self::CHECKOUT];

    private const string ROLES_DOC = __DIR__.'/../../../docs/00-product/personas-and-roles.md';

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
     * @return iterable<string, array{source: string, attribute: string, actor: string, subject: string, expected: string, skipAdminMfa?: bool}>
     */
    public static function matrixRows(): iterable
    {
        /** @var list<array{source: string, attribute: string, actor: string, subject: string, expected: string, skipAdminMfa?: bool}> $rows */
        $rows = require __DIR__.'/voter_matrix_rows.php';
        foreach ($rows as $row) {
            $flag = ($row['skipAdminMfa'] ?? false) ? ' (admin MFA skipped)' : '';
            yield \sprintf('%s %s %s%s', $row['actor'], $row['attribute'], $row['subject'], $flag) => $row;
        }
    }

    /**
     * The hackathon flag is read when the kernel boots, so it is set before this test boots one and put back after.
     */
    #[Test]
    #[DataProvider('matrixRows')]
    public function theAppDecidesTheRowAsTheRolesDocSays(string $source, string $attribute, string $actor, string $subject, string $expected, bool $skipAdminMfa = false): void
    {
        self::assertContains($expected, ['granted', 'denied'], 'A row expects granted or denied.');
        self::assertArrayHasKey($actor, self::PEOPLE, 'The actor is one of VoterMatrixTest::PEOPLE.');
        [, $organizationId] = self::PEOPLE[$actor];

        self::ensureKernelShutdown();
        $flagBefore = HackathonFlagEnvironment::set($skipAdminMfa ? '1' : null);
        try {
            $accessDecision = new AccessDecision();
            $isGranted = self::transactions()->rolledBackAs(
                $organizationId,
                fn (): bool => $this->accessDecisionManager()->decide(self::tokenOf($actor), [$attribute], self::subjectNamed($subject), $accessDecision),
            );
        } finally {
            $flagBefore->restore();
        }

        self::assertNotSame([], self::votersThatDecided($accessDecision), \sprintf('No voter decides %s on %s; is the attribute or subject wrong?', $attribute, $subject));
        self::assertSame($expected, $isGranted ? 'granted' : 'denied', \sprintf('%s %s %s, from "%s"', $actor, $attribute, $subject, $source));
    }

    #[Test]
    #[DataProvider('matrixRows')]
    public function theRolesDocHasTheTextTheRowComesFrom(string $source, string $attribute, string $actor, string $subject, string $expected, bool $skipAdminMfa = false): void
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

    private static function subjectNamed(string $name): OrganizationId|TeamId
    {
        if (isset(self::ORGANIZATIONS[$name])) {
            return new OrganizationId(self::ORGANIZATIONS[$name]);
        }
        self::assertArrayHasKey($name, self::TEAMS, 'The subject is an organization or a team of VoterMatrixTest.');

        return new TeamId(self::TEAMS[$name]);
    }

    /**
     * A token as the login form leaves it; the person needs no idn_user row, as no voter reads one.
     */
    private static function tokenOf(string $person): TokenInterface
    {
        $user = new User(
            new UserId(self::PEOPLE[$person][0]),
            new Email(strtolower($person).'@acme-foods.example'),
            $person,
            'en',
            'Europe/Berlin',
            new \DateTimeImmutable('2026-11-02 09:30:00 UTC'),
        );
        $hash = new PasswordHash('$argon2id$v=19$m=65536,t=4,p=1$NUkxU2sxQlFxbXpDaUZUQQ$suVp+0jzKZuqeONGVmUghGQxxI2vd02CMokdMtaQcfA');
        $securityUser = SecurityUser::of($user, new Credential($user, $hash, new \DateTimeImmutable('2026-11-02 09:30:00 UTC')));

        return new UsernamePasswordToken($securityUser, 'main', $securityUser->getRoles());
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
            "INSERT INTO org_team (organization_id, id, name, lead_user_id, created_at) VALUES (?, ?, ?, ?, '2027-03-15 10:00:00+00')",
            [$organizationId, $teamId, $name, self::PEOPLE[$lead][0]],
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
