<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use App\Identity\Domain\Credential;
use App\Identity\Domain\Email;
use App\Identity\Domain\PasswordHash;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Identity\Infrastructure\Security\SymfonyCurrentUser;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class SymfonyCurrentUserTest extends TestCase
{
    private const string LENA = '0197a3c0-0000-7000-8000-0000000000aa';
    private const string HASH = '$argon2id$v=19$m=65536,t=4,p=1$NUkxU2sxQlFxbXpDaUZUQQ$suVp+0jzKZuqeONGVmUghGQxxI2vd02CMokdMtaQcfA';

    #[Test]
    public function theLoggedInUserOfTheRequestHasTheirId(): void
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(self::lena(), 'main'));

        self::assertSame(self::LENA, new SymfonyCurrentUser($tokenStorage)->id()?->toString());
    }

    #[Test]
    public function aRequestWithoutATokenHasNoUser(): void
    {
        self::assertNull(new SymfonyCurrentUser(new TokenStorage())->id());
    }

    #[Test]
    public function onlyAUserIdentityLoggedInHasAnId(): void
    {
        $currentUser = new SymfonyCurrentUser(new TokenStorage());

        self::assertSame(self::LENA, $currentUser->idOf(self::lena())?->toString());
        self::assertNull($currentUser->idOf(new NullToken()->getUser()));
        self::assertNull($currentUser->idOf(new InMemoryUser(self::LENA, null)));
    }

    private static function lena(): SecurityUser
    {
        $user = new User(new UserId(self::LENA), new Email('lena@acme-foods.example'), 'Lena Berg', 'en', 'Europe/Berlin', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));

        return SecurityUser::of($user, new Credential($user, new PasswordHash(self::HASH), new \DateTimeImmutable('2026-11-02 09:30:00 UTC')));
    }
}
