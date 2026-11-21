<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\UI\Voter;

use App\Identity\Api\ApiTokenAccess;
use App\Identity\Api\OAuthConnectionRefused;
use App\Identity\Application\OAuthConnectionOwner;
use App\Identity\Application\OAuthConnectionPermission;
use App\Identity\Application\RecentAuth;
use App\Identity\Application\SessionConfirmations;
use App\Identity\Domain\Credential;
use App\Identity\Domain\Email;
use App\Identity\Domain\PasswordHash;
use App\Identity\Domain\TwoStepLogins;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\ApiTokenAuthentication;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Identity\Infrastructure\Security\SymfonyCurrentUser;
use App\Identity\Infrastructure\Security\SymfonyLoginAssurance;
use App\Identity\UI\Voter\OAuthConnectionVoter;
use App\Organization\Api\AdminPermission;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\Strategy\UnanimousStrategy;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * The voter with the app's own SymfonyLoginAssurance and SymfonyCurrentUser, so token kinds and users are told apart as
 * in a request. ADMIN_AREA comes from a stand-in that grants it to Sara on Acme Foods only, as AdminAreaVoter would to
 * an Admin of the request's organization with the admin login. The stand-in answers Organization's own AdminPermission::AREA,
 * so a rename of it fails the Admin case.
 */
final class OAuthConnectionVoterTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000c0a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000c0b1';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000c0c1';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000c0c2';
    private const string SARA = '0197a3c0-0000-7000-8000-00000000c0c3';
    private const string FAMILY = '0197a3c0-0000-7000-8000-00000000c0f1';

    #[Test]
    public function itsPersonMayEndTheirOwnConnection(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter()->vote(self::browserSessionOf(self::OMAR), self::omarsConnectionAt(self::ACME_FOODS), [OAuthConnectionPermission::END]));
    }

    #[Test]
    public function anotherPersonWhoIsNoAdminIsRefusedWithTheReason(): void
    {
        $vote = new Vote();

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote(self::browserSessionOf(self::MIA), self::omarsConnectionAt(self::ACME_FOODS), [OAuthConnectionPermission::END], $vote));
        self::assertSame([OAuthConnectionRefused::NOT_YOURS], $vote->reasons);
    }

    #[Test]
    public function anAdminOfItsOrganizationMayEndAnyonesConnection(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter()->vote(self::browserSessionOf(self::SARA), self::omarsConnectionAt(self::ACME_FOODS), [OAuthConnectionPermission::END]));
    }

    #[Test]
    public function anAdminOfAnotherOrganizationIsRefused(): void
    {
        $vote = new Vote();

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote(self::browserSessionOf(self::SARA), self::omarsConnectionAt(self::BETA_BAKERY), [OAuthConnectionPermission::END], $vote));
        self::assertSame([OAuthConnectionRefused::NOT_YOURS], $vote->reasons);
    }

    #[Test]
    public function aRequestMadeWithATokenIsRefusedEvenForItsOwnPerson(): void
    {
        $vote = new Vote();

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote(self::tokenActorOf(self::OMAR), self::omarsConnectionAt(self::ACME_FOODS), [OAuthConnectionPermission::END], $vote));
        self::assertSame([OAuthConnectionVoter::TOKEN_REFUSAL_REASON], $vote->reasons);
    }

    #[Test]
    public function nobodyLoggedInIsRefused(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote(new NullToken(), self::omarsConnectionAt(self::ACME_FOODS), [OAuthConnectionPermission::END]));
    }

    #[Test]
    public function itAbstainsOnOtherAttributesAndSubjects(): void
    {
        $voter = $this->voter();

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(self::browserSessionOf(self::OMAR), self::omarsConnectionAt(self::ACME_FOODS), ['ACCOUNT_EDIT_OWN']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(self::browserSessionOf(self::OMAR), self::FAMILY, [OAuthConnectionPermission::END]));
    }

    private function voter(): OAuthConnectionVoter
    {
        $twoStepLogins = self::createStub(TwoStepLogins::class);
        $adminAreaOfSaraAtAcme = new class(self::SARA, self::ACME_FOODS) implements VoterInterface {
            public function __construct(private readonly string $adminId, private readonly string $organizationId)
            {
            }

            public function vote(TokenInterface $token, mixed $subject, array $attributes, ?Vote $vote = null): int
            {
                if ([AdminPermission::AREA] !== $attributes) {
                    return self::ACCESS_ABSTAIN;
                }
                $user = $token->getUser();
                $isAdminHere = $user instanceof SecurityUser && $user->id() === $this->adminId
                    && $subject instanceof OrganizationId && $subject->equals(new OrganizationId($this->organizationId));

                return $isAdminHere ? self::ACCESS_GRANTED : self::ACCESS_DENIED;
            }
        };

        return new OAuthConnectionVoter(
            new SymfonyLoginAssurance($twoStepLogins, new RecentAuth(self::createStub(SessionConfirmations::class), $twoStepLogins), new MockClock('2027-03-15 10:00:00 UTC')),
            new SymfonyCurrentUser(new TokenStorage()),
            new AccessDecisionManager([$adminAreaOfSaraAtAcme], new UnanimousStrategy(false)),
        );
    }

    private static function omarsConnectionAt(string $organizationId): OAuthConnectionOwner
    {
        return new OAuthConnectionOwner(self::FAMILY, new OrganizationId($organizationId), new UserId(self::OMAR));
    }

    private static function tokenActorOf(string $userId): ApiTokenAuthentication
    {
        return new ApiTokenAuthentication(self::person($userId), new ApiTokenAccess('0197a3c0-0000-7000-8000-00000000c0e1', new OrganizationId(self::ACME_FOODS), ['read'], new \DateTimeImmutable('2027-03-15 11:00:00 UTC')), null);
    }

    private static function browserSessionOf(string $userId): UsernamePasswordToken
    {
        return new UsernamePasswordToken(self::person($userId), 'main', ['ROLE_USER']);
    }

    private static function person(string $userId): SecurityUser
    {
        $user = new User(new UserId($userId), new Email(substr($userId, -4).'@acme-foods.example'), 'Person '.substr($userId, -4), 'en', 'Europe/Berlin', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));

        return SecurityUser::of($user, new Credential($user, new PasswordHash('$argon2id$v=19$m=10,t=3,p=1$stored'), new \DateTimeImmutable('2026-11-02 09:30:00 UTC')));
    }
}
