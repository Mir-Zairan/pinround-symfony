<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\UI\Voter;

use App\Identity\Application\AccountPermission;
use App\Identity\UI\Voter\OwnAccountVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
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
        self::assertSame(VoterInterface::ACCESS_GRANTED, new OwnAccountVoter()->vote($token, null, [AccountPermission::EDIT_OWN]));
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
        self::assertSame(VoterInterface::ACCESS_DENIED, new OwnAccountVoter()->vote($token, null, [AccountPermission::EDIT_OWN]));
    }

    #[Test]
    public function itAbstainsOnOtherAttributesAndOnAnySubject(): void
    {
        $token = new UsernamePasswordToken(self::user(), 'main', ['ROLE_USER']);
        $voter = new OwnAccountVoter();

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, null, ['TICKET_VIEW']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, '0197a3c0-0000-7000-8000-0000000000c2', [AccountPermission::EDIT_OWN]));
    }

    private static function user(): InMemoryUser
    {
        return new InMemoryUser('lena@acme-foods.example', null, ['ROLE_USER']);
    }
}
