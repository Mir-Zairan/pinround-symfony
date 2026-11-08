<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use App\Identity\Domain\Credential;
use App\Identity\Domain\Email;
use App\Identity\Domain\PasswordHash;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Identity\Infrastructure\Security\SymfonyLoginAssurance;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Test\TestBrowserToken;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

final class SymfonyLoginAssuranceTest extends TestCase
{
    private const string LENA = '0197a3c0-0000-7000-8000-0000000000aa';
    private const string HASH = '$argon2id$v=19$m=65536,t=4,p=1$NUkxU2sxQlFxbXpDaUZUQQ$suVp+0jzKZuqeONGVmUghGQxxI2vd02CMokdMtaQcfA';

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function tokensThatAreNoPasswordSession(): iterable
    {
        $lena = self::lena();

        yield 'remember-me' => [new RememberMeToken($lena, 'main')];
        yield 'an authenticator without a token class of its own, as an access token login' => [new PostAuthenticationToken($lena, 'main', $lena->getRoles())];
        yield 'impersonation' => [new SwitchUserToken($lena, 'main', $lena->getRoles(), new UsernamePasswordToken($lena, 'main'))];
        yield 'the test browser login' => [new TestBrowserToken($lena->getRoles(), $lena, 'main')];
        yield 'a form login of a user Identity did not log in' => [new UsernamePasswordToken(new InMemoryUser('lena@acme-foods.example', null), 'main')];
        yield 'anonymous' => [new NullToken()];
        yield 'no token' => [null];
    }

    #[Test]
    public function theFormLoginOfAUserIsAPasswordSession(): void
    {
        self::assertTrue(new SymfonyLoginAssurance()->isPasswordSession(new UsernamePasswordToken(self::lena(), 'main')));
    }

    #[Test]
    #[DataProvider('tokensThatAreNoPasswordSession')]
    public function everyOtherTokenKindIsNoPasswordSession(mixed $token): void
    {
        self::assertFalse(new SymfonyLoginAssurance()->isPasswordSession($token));
    }

    #[Test]
    public function nobodyHasAConfirmedTwoStepLoginBeforeItExists(): void
    {
        self::assertFalse(new SymfonyLoginAssurance()->hasConfirmedTwoStepLogin(new UsernamePasswordToken(self::lena(), 'main')));
    }

    private static function lena(): SecurityUser
    {
        $user = new User(new UserId(self::LENA), new Email('lena@acme-foods.example'), 'Lena Berg', 'en', 'Europe/Berlin', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));

        return SecurityUser::of($user, new Credential($user, new PasswordHash(self::HASH), new \DateTimeImmutable('2026-11-02 09:30:00 UTC')));
    }
}
