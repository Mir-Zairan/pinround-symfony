<?php

declare(strict_types=1);

namespace App\Tests\Integration\Visibility;

use App\Identity\Domain\Credential;
use App\Identity\Domain\Email;
use App\Identity\Domain\PasswordHash;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Factory\Ticketing\TicketFactory;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\ShopTeamRows;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Api\TicketVisibilityFacts;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The cast every visibility leak test reads its path with (ADR-0013, see README.md): one team-only SHOP ticket whose
 * title carries LEAK_MARKER, one team-only ticket with no team, and a viewer for each way to see them or not.
 *
 * Acme Foods: Lena leads the Shop team, which owns SHOP; the no-team ticket is in OPS, which no team owns; Mia, in no
 * team, reported the SHOP ticket; Sara is an Admin
 * with no team role; Omar, in no team, is the triager; Raj is a Manager with no team role; Noor is a member with no
 * team role and no link to either ticket. Beta Bakery: Ben is its Admin. A person who is none of the cast reported the
 * no-team ticket, so nobody sees it as its reporter. The rows are committed once per class, as a page would read them.
 */
abstract class VisibilityLeakCast extends KernelTestCase
{
    public const string LEAK_MARKER = 'LEAK-MARKER team-only';

    protected const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000057a1';
    protected const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000057a2';
    protected const string SHOP_TEAM = '0197a3c0-0000-7000-8000-0000000057c1';
    protected const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000057d1';
    protected const string NO_TEAM_PROJECT = '0197a3c0-0000-7000-8000-0000000057d2';
    protected const string NO_TEAM_TICKET = '0197a3c0-0000-7000-8000-0000000057e2';
    protected const string NO_TEAM_REPORTER = '0197a3c0-0000-7000-8000-0000000057b9';

    /** Each viewer's user id, organization, org roles and why they are in the cast. */
    protected const array VIEWERS = [
        'Lena' => ['0197a3c0-0000-7000-8000-0000000057b1', self::ACME_FOODS, '{}', 'the lead of the ticket\'s team'],
        'Mia' => ['0197a3c0-0000-7000-8000-0000000057b2', self::ACME_FOODS, '{}', 'the outside reporter'],
        'Sara' => ['0197a3c0-0000-7000-8000-0000000057b3', self::ACME_FOODS, '{admin}', 'an admin with no team role'],
        'Omar' => ['0197a3c0-0000-7000-8000-0000000057b4', self::ACME_FOODS, '{}', 'the triager, in no team'],
        'Raj' => ['0197a3c0-0000-7000-8000-0000000057b5', self::ACME_FOODS, '{manager}', 'a manager with no team role'],
        'Noor' => ['0197a3c0-0000-7000-8000-0000000057b6', self::ACME_FOODS, '{}', 'an outsider with no link'],
        'Ben' => ['0197a3c0-0000-7000-8000-0000000057b7', self::BETA_BAKERY, '{admin}', 'an admin of another organization'],
    ];

    private static ?string $teamOnlyTicketId = null;

    public static function setUpBeforeClass(): void
    {
        self::removeCast();
        self::shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::VIEWERS['Lena'][0], [
            self::VIEWERS['Lena'][0] => ['Lena', 'lead', 'developer'],
        ]);
        foreach (self::VIEWERS as $name => [$userId, $organizationId, $orgRoles]) {
            if ('Lena' === $name) {
                continue;
            }
            self::migrator()->executeStatement(
                "INSERT INTO idn_user (id, email, name, status, locale, time_zone, created_at, session_stamp)
                 VALUES (?, ?, ?, 'active', 'en', 'UTC', '2027-03-15 10:00:00+00', 'a-stamp')",
                [$userId, strtolower($name).'.leak@acme-foods.example', $name],
            );
            self::transactions()->committedAs($organizationId, static fn (): int|string => self::connection()->executeStatement(
                "INSERT INTO org_membership (organization_id, user_id, org_roles, joined_at) VALUES (?, ?, ?, '2027-03-15 10:00:00+00')",
                [$organizationId, $userId, $orgRoles],
            ));
        }
        self::transactions()->committedAs(self::ACME_FOODS, static fn (): int|string => self::connection()->executeStatement(
            "INSERT INTO org_settings (organization_id, data, created_at) VALUES (?, jsonb_build_object('triager_id', ?::text), '2027-03-15 10:00:00+00')",
            [self::ACME_FOODS, self::VIEWERS['Omar'][0]],
        ));

        $ticketCommands = self::getContainer()->get('test.ticketing_ticket_commands');
        \assert($ticketCommands instanceof TicketCommands);
        $teamOnlyTicketId = new TicketFactory($ticketCommands, self::transactions(), self::connection())
            ->createOne(self::ACME_FOODS, self::SHOP_PROJECT, self::VIEWERS['Mia'][0], ['title' => self::LEAK_MARKER])
            ->toString();
        self::$teamOnlyTicketId = $teamOnlyTicketId;
        // A new ticket is for everyone, and no command restricts one yet; the turn row copies the column.
        self::asTenantMigrator(self::ACME_FOODS, static function (Connection $migrator) use ($teamOnlyTicketId): void {
            $migrator->executeStatement("UPDATE tkt_ticket SET visibility = 'team' WHERE id = ?", [$teamOnlyTicketId]);
            $migrator->executeStatement("UPDATE ins_ticket_turn SET visibility = 'team' WHERE ticket_id = ?", [$teamOnlyTicketId]);
        });
        $ticketRows = new TicketRows(self::migrator(), self::tenantContext());
        $ticketRows->addKeyedProjectOf(self::ACME_FOODS, self::NO_TEAM_PROJECT, null, 'OPS', 1);
        $ticketRows->addWithAudienceOf(self::ACME_FOODS, self::NO_TEAM_TICKET, self::NO_TEAM_PROJECT, 90, 'team', null, self::NO_TEAM_REPORTER);
        self::ensureKernelShutdown();
    }

    public static function tearDownAfterClass(): void
    {
        self::removeCast();
        self::$teamOnlyTicketId = null;
        self::ensureKernelShutdown();
        parent::tearDownAfterClass();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::tenantContext()->reset();
        parent::tearDown();
    }

    /**
     * Every viewer of the cast, whether they may see the team-only SHOP ticket, and whether the no-team ticket.
     *
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function viewers(): iterable
    {
        $expected = [
            'Lena' => [true, false],
            'Mia' => [true, false],
            'Sara' => [true, true],
            'Omar' => [false, true],
            'Raj' => [false, false],
            'Noor' => [false, false],
            'Ben' => [false, false],
        ];
        foreach ($expected as $viewer => [$seesTeamOnly, $seesNoTeamTicket]) {
            yield \sprintf('%s, %s', $viewer, self::VIEWERS[$viewer][3]) => [$viewer, $seesTeamOnly, $seesNoTeamTicket];
        }
    }

    protected static function teamOnlyTicketId(): string
    {
        return self::$teamOnlyTicketId ?? throw new \LogicException('The cast is set up before each class.');
    }

    protected static function teamOnlyTicket(): TicketVisibilityFacts
    {
        return new TicketVisibilityFacts(new OrganizationId(self::ACME_FOODS), TicketVisibilityFacts::TEAM_ONLY, new TeamId(self::SHOP_TEAM), new UserId(self::VIEWERS['Mia'][0]));
    }

    protected static function noTeamTicket(): TicketVisibilityFacts
    {
        return new TicketVisibilityFacts(new OrganizationId(self::ACME_FOODS), TicketVisibilityFacts::TEAM_ONLY, null, new UserId(self::NO_TEAM_REPORTER));
    }

    /**
     * Runs the work as the viewer would in a request: in a rolled-back transaction under their own organization.
     *
     * @template T
     *
     * @param \Closure(TokenInterface): T $work
     *
     * @return T
     */
    protected static function asViewer(string $viewer, \Closure $work): mixed
    {
        return self::transactions()->rolledBackAs(self::VIEWERS[$viewer][1], static fn (): mixed => $work(self::tokenOf($viewer)));
    }

    /**
     * Stores an open question as Conversation asks one, in the transaction under Acme Foods that is open: NotifyRecipients
     * writes a question row only while its question is open. cnv_message allows one open question per ticket.
     */
    protected static function addOpenQuestion(string $ticketId, string $questionId, string $askedViewer, string $askingViewer, string $askedAt): void
    {
        self::connection()->executeStatement(
            "INSERT INTO cnv_message (organization_id, id, ticket_id, author_id, kind, to_user_id, body, created_at) VALUES (?, ?, ?, ?, 'question', ?, ?, ?)",
            [self::ACME_FOODS, $questionId, $ticketId, self::VIEWERS[$askingViewer][0], self::VIEWERS[$askedViewer][0], 'Can you check this?', $askedAt],
        );
    }

    /**
     * Stores a comment as Conversation posts one, in the transaction under Acme Foods that is open: NotifyRecipients
     * writes a reply row only while the message replied to is present.
     */
    protected static function addComment(string $ticketId, string $commentId, string $authorViewer, string $postedAt): void
    {
        self::connection()->executeStatement(
            "INSERT INTO cnv_message (organization_id, id, ticket_id, author_id, kind, body, created_at) VALUES (?, ?, ?, ?, 'comment', ?, ?)",
            [self::ACME_FOODS, $commentId, $ticketId, self::VIEWERS[$authorViewer][0], 'The cart total looks off.', $postedAt],
        );
    }

    protected static function connection(): Connection
    {
        return self::entityManager()->getConnection();
    }

    protected static function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::entityManager(), self::tenantContext());
    }

    /**
     * A token as the login form leaves it.
     */
    private static function tokenOf(string $viewer): TokenInterface
    {
        $user = new User(new UserId(self::VIEWERS[$viewer][0]), new Email(strtolower($viewer).'.leak@acme-foods.example'), $viewer, 'en', 'UTC', new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));
        $hash = new PasswordHash('$argon2id$v=19$m=65536,t=4,p=1$NUkxU2sxQlFxbXpDaUZUQQ$suVp+0jzKZuqeONGVmUghGQxxI2vd02CMokdMtaQcfA');
        $securityUser = SecurityUser::of($user, new Credential($user, $hash, new \DateTimeImmutable('2027-03-15 10:00:00 UTC')));

        return new UsernamePasswordToken($securityUser, 'main', $securityUser->getRoles());
    }

    /**
     * @param \Closure(Connection): mixed $work
     */
    protected static function asTenantMigrator(string $organizationId, \Closure $work): void
    {
        self::tenantContext()->set(new OrganizationId($organizationId));
        try {
            self::migrator()->transactional($work);
        } finally {
            self::tenantContext()->clear();
        }
    }

    private static function removeCast(): void
    {
        self::shopTeamRows()->removeAllOf(array_column(self::VIEWERS, 0), self::ACME_FOODS, self::BETA_BAKERY);
    }

    private static function shopTeamRows(): ShopTeamRows
    {
        return new ShopTeamRows(self::migrator(), self::tenantContext());
    }

    private static function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    protected static function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    protected static function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
