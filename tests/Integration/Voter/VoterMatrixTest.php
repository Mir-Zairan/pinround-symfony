<?php

declare(strict_types=1);

namespace App\Tests\Integration\Voter;

use App\Conversation\UI\Voter\MessageOnTicket;
use App\Identity\Api\ApiTokenAccess;
use App\Identity\Application\AccountPermission;
use App\Identity\Application\SessionConfirmations;
use App\Identity\Domain\Credential;
use App\Identity\Domain\Email;
use App\Identity\Domain\PasswordHash;
use App\Identity\Domain\TwoStepLogin;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\ApiTokenAuthentication;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Identity\UI\Voter\OwnAccountVoter;
use App\Media\Api\UploadTarget;
use App\Organization\Api\TeamPermission;
use App\Organization\Application\OpenInvitationLink;
use App\Organization\Domain\TeamSlug;
use App\Review\Api\RoundAccessFacts;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\OrganizationRows;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Tests\Integration\Workflow\WorkflowRows;
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
 * Every token is a password session, as the login form leaves it, unless the row's login is a personal access token:
 * then it is as TokenAuthenticator leaves it, for a token of the person's organization. A row with twoStepProof also
 * has, in its rolled-back transaction, the person's user and two-step login rows, and a request whose session holds
 * their code proof (see voter_matrix_rows.php for its ages); other rows have neither, so no session.
 *
 * Acme Foods: Lena is an Admin and leads Shop and Mobile (as the demo seed has it); Omar is a member of both; Mia is a
 * member (tester) of Shop and a viewer of Support; Raj is a Manager and a viewer of Shop; Sara is an Admin with no team
 * role; Noor has no org role, leads Support and is the organization's triager. Beta Bakery: Ben is its Admin and leads
 * its one team, Checkout.
 *
 * The upload subjects name tickets of Acme Foods that are rows (the Media voter asks Ticketing, which reads them): a
 * team-only ticket of Shop and one of Support. For Ben, in Beta Bakery as tenant, they are another organization's.
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

    /** The person of PEOPLE who leads each team, as setUpBeforeClass adds them; a comment's subject carries its lead. */
    private const array TEAM_LEADS = [self::SHOP => 'Lena', self::MOBILE => 'Lena', self::SUPPORT => 'Noor', self::CHECKOUT => 'Ben'];

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

    /** The step id TicketRows gives every ticket it adds. */
    private const string TO_DO_STEP = '0197a3c0-0000-7000-8000-00000000f0d0';
    private const string PROJECT = '0197a3c0-0000-7000-8000-0000000009f2';
    private const string SHOP_TICKET = '0197a3c0-0000-7000-8000-0000000009f3';
    private const string SUPPORT_TICKET = '0197a3c0-0000-7000-8000-0000000009f4';
    private const string NO_TICKET = '0197a3c0-0000-7000-8000-0000000009f5';

    /** Each upload target by the subject's name: the kind and the id of the target in its module. */
    private const array UPLOAD_TARGETS = [
        'Upload to a team-only Shop ticket' => ['ticket', self::SHOP_TICKET],
        'Upload to a team-only Support ticket' => ['ticket', self::SUPPORT_TICKET],
        'Upload to a ticket nobody has' => ['ticket', self::NO_TICKET],
        'Upload to a target of an unknown kind' => ['screenshot-wall', self::SHOP_TICKET],
    ];

    private const string DONE_STEP = '0197a3c0-0000-7000-8000-00000000f0d1';
    private const string ASSIGNED_TICKET = '0197a3c0-0000-7000-8000-0000000009f6';
    private const string DONE_TICKET = '0197a3c0-0000-7000-8000-0000000009f7';
    private const string ROUND = '0197a3c0-0000-7000-8000-0000000009f8';

    /** Each ticket id subject of DRAFT_CREATE by name; Omar is the assignee of the two Shop tickets of Acme Foods that have one. */
    private const array TICKET_IDS = [
        'Unassigned Shop ticket' => self::SHOP_TICKET,
        'Shop ticket assigned to Omar' => self::ASSIGNED_TICKET,
        'Done Shop ticket assigned to Omar' => self::DONE_TICKET,
        'Ticket nobody has, for a draft round' => self::NO_TICKET,
    ];

    /** Each round by name: the organization, the ticket, its owner (always Omar), whether it is an unshared draft and whether it is deleted. */
    private const array ROUNDS = [
        'Unshared draft round of Omar' => [self::ACME_FOODS, self::SHOP_TICKET, true, false],
        'Deleted draft round of Omar' => [self::ACME_FOODS, self::SHOP_TICKET, true, true],
        'Shared round of Omar on a team-only Shop ticket' => [self::ACME_FOODS, self::SHOP_TICKET, false, false],
        'Shared round of Omar on a team-only Support ticket' => [self::ACME_FOODS, self::SUPPORT_TICKET, false, false],
        'Shared round of Omar on a ticket nobody has' => [self::ACME_FOODS, self::NO_TICKET, false, false],
        'Unshared draft round of Omar in another organization' => [self::BETA_BAKERY, self::SHOP_TICKET, true, false],
        'Shared round of another organization' => [self::BETA_BAKERY, self::SHOP_TICKET, false, false],
    ];

    private const string PASSWORD_SESSION = 'password session';
    private const string PERSONAL_ACCESS_TOKEN = 'personal access token';
    private const string API_TOKEN = '0197a3c0-0000-7000-8000-0000000009e2';

    private const string ROLES_DOC = __DIR__.'/../../../docs/00-product/personas-and-roles.md';

    /** Not the wall clock's time, so a check that ignores the app clock decides differently. */
    private const string NOW = '2027-03-15 10:00:00 UTC';

    public static function setUpBeforeClass(): void
    {
        self::removeSeededRows();
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
        self::addTicketsOfAcmeFoods();
        self::ensureKernelShutdown();
    }

    public static function tearDownAfterClass(): void
    {
        self::removeSeededRows();
        self::ensureKernelShutdown();
        parent::tearDownAfterClass();
    }

    /**
     * @return iterable<string, array{source: string, attribute: string, actor: string, subject: string, expected: string, twoStepProof?: bool|int|string, login?: string, refusalReason?: string}>
     */
    public static function matrixRows(): iterable
    {
        /** @var list<array{source: string, attribute: string, actor: string, subject: string, expected: string, twoStepProof?: bool|int|string, login?: string, refusalReason?: string}> $rows */
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
            $login = $row['login'] ?? self::PASSWORD_SESSION;
            yield \sprintf('%s %s %s%s%s', $row['actor'], $row['attribute'], $row['subject'], $proof, self::PASSWORD_SESSION === $login ? '' : ' by '.$login) => $row;
        }
    }

    #[Test]
    #[DataProvider('matrixRows')]
    public function theAppDecidesTheRowAsTheRolesDocSays(string $source, string $attribute, string $actor, string $subject, string $expected, bool|int|string $twoStepProof = false, string $login = self::PASSWORD_SESSION, ?string $refusalReason = null): void
    {
        self::assertContains($expected, ['granted', 'denied'], 'A row expects granted or denied.');
        self::assertContains($login, [self::PASSWORD_SESSION, self::PERSONAL_ACCESS_TOKEN], 'A row\'s login is a password session or a personal access token.');
        self::assertTrue(null === $refusalReason || 'denied' === $expected, 'Only a denied row names a refusal reason.');
        self::assertArrayHasKey($actor, self::PEOPLE, 'The actor is one of VoterMatrixTest::PEOPLE.');
        [, $organizationId] = self::PEOPLE[$actor];

        self::ensureKernelShutdown();
        $clock = new MockClock(self::NOW);
        self::getContainer()->set('clock', $clock);
        $accessDecision = new AccessDecision();
        $isGranted = self::transactions()->rolledBackAs($organizationId, function () use ($actor, $attribute, $subject, $accessDecision, $twoStepProof, $login, $clock): bool {
            if (isset(self::UPLOAD_TARGETS[$subject]) || isset(self::TICKET_IDS[$subject]) || isset(self::ROUNDS[$subject])) {
                self::persistUsersOfEveryone();
            }
            $requestStack = false !== $twoStepProof ? self::requestWithCodeProofOf($actor, $clock, $twoStepProof) : null;
            // MediaTargetAuthorizer decides for the current request actor, so voters that delegate to it read TokenStorage.
            $tokenStorage = self::getContainer()->get('security.token_storage');
            $previousToken = $tokenStorage->getToken();
            $token = self::tokenOf($actor, $login);
            $tokenStorage->setToken($token);
            try {
                return $this->accessDecisionManager()->decide($token, [$attribute], self::subjectNamed($subject), $accessDecision);
            } finally {
                $tokenStorage->setToken($previousToken);
                $requestStack?->pop();
            }
        });

        self::assertNotSame([], self::votersThatDecided($accessDecision), \sprintf('No voter decides %s on %s; is the attribute or subject wrong?', $attribute, $subject));
        self::assertSame($expected, $isGranted ? 'granted' : 'denied', \sprintf('%s %s %s, from "%s"', $actor, $attribute, $subject, $source));
        if (null !== $refusalReason) {
            self::assertStringContainsString($refusalReason, $accessDecision->getMessage(), 'The row is denied, but not for the reason it names.');
        }
    }

    #[Test]
    #[DataProvider('matrixRows')]
    public function theRolesDocHasTheTextTheRowComesFrom(string $source, string $attribute, string $actor, string $subject, string $expected, bool|int|string $twoStepProof = false, string $login = self::PASSWORD_SESSION, ?string $refusalReason = null): void
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

    /**
     * Not a matrix row: the roles doc has no row for a person's own account (FR-ID-05).
     */
    #[Test]
    public function anAdminsPersonalAccessTokenMayNotEditTheirAccountWhereTheirPasswordSessionMay(): void
    {
        $decide = fn (string $login, AccessDecision $accessDecision): bool => self::transactions()->rolledBackAs(
            self::ACME_FOODS,
            fn (): bool => $this->accessDecisionManager()->decide(self::tokenOf('Lena', $login), [AccountPermission::EDIT_OWN], null, $accessDecision),
        );
        $passwordSessionDecision = new AccessDecision();
        $tokenDecision = new AccessDecision();

        self::assertTrue($decide(self::PASSWORD_SESSION, $passwordSessionDecision));
        self::assertFalse($decide(self::PERSONAL_ACCESS_TOKEN, $tokenDecision));
        self::assertStringContainsString(OwnAccountVoter::TOKEN_REFUSAL_REASON, $tokenDecision->getMessage());
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

    private static function subjectNamed(string $name): OrganizationId|TeamId|OpenInvitationLink|TicketVisibilityFacts|UploadTarget|MessageOnTicket|TicketId|RoundAccessFacts
    {
        if (isset(self::TICKET_IDS[$name])) {
            return new TicketId(self::TICKET_IDS[$name]);
        }
        if (isset(self::ROUNDS[$name])) {
            [$organizationId, $ticketId, $isUnsharedDraft, $isDeleted] = self::ROUNDS[$name];

            return new RoundAccessFacts(new OrganizationId($organizationId), self::ROUND, new TicketId($ticketId), new UserId(self::PEOPLE['Omar'][0]), $isUnsharedDraft, $isDeleted);
        }
        if (isset(self::UPLOAD_TARGETS[$name])) {
            return new UploadTarget(...self::UPLOAD_TARGETS[$name]);
        }
        if (isset(self::ORGANIZATIONS[$name])) {
            return new OrganizationId(self::ORGANIZATIONS[$name]);
        }
        if (1 === preg_match('/\A(\w+)\'s comment on (.+)\z/', $name, $comment)) {
            [, $author, $ticketName] = $comment;
            self::assertArrayHasKey($author, self::PEOPLE, 'The comment\'s author is one of VoterMatrixTest::PEOPLE.');
            $ticket = self::subjectNamed($ticketName);
            self::assertInstanceOf(TicketVisibilityFacts::class, $ticket, 'A comment is on a ticket of VoterMatrixTest::TICKETS.');
            $teamLead = null === $ticket->teamId ? null : self::TEAM_LEADS[$ticket->teamId->toString()];

            return new MessageOnTicket($ticket, new UserId(self::PEOPLE[$author][0]), null === $teamLead ? null : new UserId(self::PEOPLE[$teamLead][0]));
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
        self::assertArrayHasKey($name, self::TEAMS, 'The subject is an organization, a team, an invitation, a ticket or a comment of VoterMatrixTest.');

        return new TeamId(self::TEAMS[$name]);
    }

    /**
     * A token as the login form leaves it for a password session, or as TokenAuthenticator leaves it for a personal
     * access token of the person's organization with Claude Code's default scopes; the person needs no idn_user row
     * unless the row asks for a code proof.
     */
    private static function tokenOf(string $person, string $login = self::PASSWORD_SESSION): TokenInterface
    {
        $user = self::userOf($person);
        $hash = new PasswordHash('$argon2id$v=19$m=65536,t=4,p=1$NUkxU2sxQlFxbXpDaUZUQQ$suVp+0jzKZuqeONGVmUghGQxxI2vd02CMokdMtaQcfA');
        $securityUser = SecurityUser::of($user, new Credential($user, $hash, new \DateTimeImmutable('2026-11-02 09:30:00 UTC')));
        if (self::PERSONAL_ACCESS_TOKEN === $login) {
            return new ApiTokenAuthentication($securityUser, new ApiTokenAccess(self::API_TOKEN, new OrganizationId(self::PEOPLE[$person][1]), ['read', 'rounds:write']), null);
        }

        return new UsernamePasswordToken($securityUser, 'main', $securityUser->getRoles());
    }

    /**
     * A ticket's turn facts need the people of its team to be users; the upload rows read them.
     */
    private static function persistUsersOfEveryone(): void
    {
        $entityManager = self::entityManager();
        foreach (array_keys(self::PEOPLE) as $person) {
            $entityManager->persist(self::userOf($person));
        }
        $entityManager->flush();
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
     * Through the migrator, which owns the tables: a To do step and a Done step, a project with no team and four team-only
     * tickets reported by someone who is no actor here: one of Shop and one of Support, and two of Shop assigned to Omar,
     * one of them moved to Done.
     */
    private static function addTicketsOfAcmeFoods(): void
    {
        $tenantContext = self::tenantContext();
        $tenantContext->set(new OrganizationId(self::ACME_FOODS));
        try {
            self::migrator()->transactional(static fn (Connection $migrator): int|string => $migrator->executeStatement(
                "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'To do', 'todo', 'todo', 'core', 1)",
                [self::ACME_FOODS, self::TO_DO_STEP],
            ));
            self::migrator()->transactional(static fn (Connection $migrator): int|string => $migrator->executeStatement(
                "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'Done', 'done', 'done', 'core', 7)",
                [self::ACME_FOODS, self::DONE_STEP],
            ));
        } finally {
            $tenantContext->clear();
        }
        $tickets = new TicketRows(self::migrator(), $tenantContext);
        $tickets->addProjectOf(self::ACME_FOODS, self::PROJECT);
        $tickets->addWithAudienceOf(self::ACME_FOODS, self::SHOP_TICKET, self::PROJECT, 1, 'team', self::SHOP, self::REPORTER);
        $tickets->addWithAudienceOf(self::ACME_FOODS, self::SUPPORT_TICKET, self::PROJECT, 2, 'team', self::SUPPORT, self::REPORTER);
        $tickets->addWithAudienceOf(self::ACME_FOODS, self::ASSIGNED_TICKET, self::PROJECT, 3, 'team', self::SHOP, self::REPORTER, ['assignee_id' => self::PEOPLE['Omar'][0]]);
        $tickets->addWithAudienceOf(self::ACME_FOODS, self::DONE_TICKET, self::PROJECT, 4, 'team', self::SHOP, self::REPORTER, ['assignee_id' => self::PEOPLE['Omar'][0]]);
        $tickets->moveToStepOf(self::ACME_FOODS, self::DONE_TICKET, self::DONE_STEP);
    }

    private static function removeSeededRows(): void
    {
        new TicketRows(self::migrator(), self::tenantContext())->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        new WorkflowRows(self::migrator(), self::tenantContext())->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        self::rows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
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
