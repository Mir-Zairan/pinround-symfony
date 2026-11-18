<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\UI\Voter;

use App\Identity\Api\ApiTokenAccess;
use App\Identity\Application\AccountPermission;
use App\Identity\Application\RecentAuth;
use App\Identity\Application\SessionConfirmations;
use App\Identity\Domain\Credential;
use App\Identity\Domain\Email;
use App\Identity\Domain\PasswordHash;
use App\Identity\Domain\TwoStepLogins;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\ApiTokenAuthentication;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Identity\Infrastructure\Security\SymfonyLoginAssurance;
use App\Identity\UI\Voter\OwnAccountVoter;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

final class OwnAccountVoterTest extends TestCase
{
    /**
     * @return iterable<string, array{TokenInterface}>
     */
    public static function sessionLogins(): iterable
    {
        yield 'form login' => [new PostAuthenticationToken(self::user(), 'main', ['ROLE_USER'])];
        yield 'username and password' => [new UsernamePasswordToken(self::user(), 'main', ['ROLE_USER'])];
    }

    #[Test]
    #[DataProvider('sessionLogins')]
    public function aPersonLoggedInWithTheirPasswordInThisSessionIsGranted(TokenInterface $token): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter()->vote($token, null, [AccountPermission::EDIT_OWN]));
    }

    /**
     * @return iterable<string, array{TokenInterface}>
     */
    public static function refusedTokens(): iterable
    {
        yield 'anonymous' => [new NullToken()];
        yield 'remember-me' => [new RememberMeToken(self::user(), 'main')];
    }

    #[Test]
    #[DataProvider('refusedTokens')]
    public function anyOtherTokenIsDenied(TokenInterface $token): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote($token, null, [AccountPermission::EDIT_OWN]));
    }

    /**
     * A user with a password, as a token's user always is, so only the token kind refuses it (FR-ID-05).
     */
    #[Test]
    public function aPersonalAccessTokenOfThePersonIsDenied(): void
    {
        $user = new User(new UserId('0197a3c0-0000-7000-8000-0000000007c2'), new Email('omar@acme-foods.example'), 'Omar Haddad', 'en', 'Europe/Berlin', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));
        $omar = SecurityUser::of($user, new Credential($user, new PasswordHash('$argon2id$v=19$m=10,t=3,p=1$stored'), new \DateTimeImmutable('2026-11-02 09:30:00 UTC')));
        $token = new ApiTokenAuthentication($omar, new ApiTokenAccess('0197a3c0-0000-7000-8000-0000000007f1', new OrganizationId('0197a3c0-0000-7000-8000-0000000007a1'), ['read', 'rounds:write']), null);
        $vote = new Vote();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter()->vote(new UsernamePasswordToken($omar, 'main', ['ROLE_USER']), null, [AccountPermission::EDIT_OWN]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote($token, null, [AccountPermission::EDIT_OWN], $vote));
        self::assertSame(['Changing your account needs a browser session, not a personal access token.'], $vote->reasons);
    }

    #[Test]
    public function itAbstainsOnOtherAttributesAndOnAnySubject(): void
    {
        $token = new UsernamePasswordToken(self::user(), 'main', ['ROLE_USER']);
        $voter = $this->voter();

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, null, ['TICKET_VIEW']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, '0197a3c0-0000-7000-8000-0000000000c2', [AccountPermission::EDIT_OWN]));
    }

    /**
     * With the app's own SymfonyLoginAssurance, so the token kinds are told apart as in a request.
     */
    private function voter(): OwnAccountVoter
    {
        $twoStepLogins = self::createStub(TwoStepLogins::class);

        return new OwnAccountVoter(new SymfonyLoginAssurance(
            $twoStepLogins,
            new RecentAuth(self::createStub(SessionConfirmations::class), $twoStepLogins),
            new MockClock('2027-03-15 10:00:00 UTC'),
        ));
    }

    private static function user(): InMemoryUser
    {
        return new InMemoryUser('lena@acme-foods.example', null, ['ROLE_USER']);
    }
}
