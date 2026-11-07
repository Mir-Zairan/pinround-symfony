<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use App\Identity\Application\AccountPermission;
use App\Identity\Domain\Credential;
use App\Identity\Domain\Email;
use App\Identity\Domain\PasswordHash;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\OwnAccountVoter;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class OwnAccountVoterTest extends TestCase
{
    #[Test]
    public function aPersonLoggedInWithTheirPasswordInThisSessionIsGranted(): void
    {
        $token = new UsernamePasswordToken(self::securityUser(), 'main', ['ROLE_USER']);

        self::assertSame(VoterInterface::ACCESS_GRANTED, new OwnAccountVoter()->vote($token, null, [AccountPermission::EDIT_OWN]));
    }

    /**
     * @return iterable<string, array{TokenInterface}>
     */
    public static function refusedTokens(): iterable
    {
        yield 'anonymous' => [new NullToken()];
        yield 'remember-me' => [new RememberMeToken(self::securityUser(), 'main')];
        yield 'another kind of user' => [new UsernamePasswordToken(new InMemoryUser('omar@acme-foods.example', null, ['ROLE_USER']), 'main', ['ROLE_USER'])];
    }

    #[Test]
    #[DataProvider('refusedTokens')]
    public function anyOtherTokenIsDenied(TokenInterface $token): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, new OwnAccountVoter()->vote($token, null, [AccountPermission::EDIT_OWN]));
    }

    #[Test]
    public function itAbstainsOnOtherAttributesAndOnAnySubject(): void
    {
        $token = new UsernamePasswordToken(self::securityUser(), 'main', ['ROLE_USER']);
        $voter = new OwnAccountVoter();

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, null, ['TICKET_VIEW']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, '0197a3c0-0000-7000-8000-0000000000c2', [AccountPermission::EDIT_OWN]));
    }

    private static function securityUser(): SecurityUser
    {
        $user = new User(new UserId('0197a3c0-0000-7000-8000-0000000000c1'), new Email('lena@acme-foods.example'), 'Lena Berg', 'en', 'UTC', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));

        return SecurityUser::of($user, new Credential($user, new PasswordHash('$argon2id$v=19$m=10,t=3,p=1$c2FsdA$aGFzaA'), new \DateTimeImmutable('2026-11-02 09:30:00 UTC')));
    }
}
