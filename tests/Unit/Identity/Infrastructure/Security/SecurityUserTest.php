<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use App\Identity\Domain\Credential;
use App\Identity\Domain\Email;
use App\Identity\Domain\PasswordHash;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SecurityUserTest extends TestCase
{
    private const string HASH = '$argon2id$v=19$m=65536,t=4,p=1$NUkxU2sxQlFxbXpDaUZUQQ$suVp+0jzKZuqeONGVmUghGQxxI2vd02CMokdMtaQcfA';

    #[Test]
    public function itCarriesTheUsersIdEmailNameAndHash(): void
    {
        $securityUser = self::securityUser();

        self::assertSame('0197a3c0-0000-7000-8000-0000000000aa', $securityUser->id());
        self::assertSame('lena@acme-foods.example', $securityUser->getUserIdentifier());
        self::assertSame('Lena Berg', $securityUser->name());
        self::assertSame(self::HASH, $securityUser->getPassword());
        self::assertSame(['ROLE_USER'], $securityUser->getRoles());
        self::assertFalse($securityUser->isBlocked());
    }

    #[Test]
    public function theSessionHoldsACrc32cOfTheHashInsteadOfTheHash(): void
    {
        $serialized = serialize(self::securityUser());

        self::assertStringNotContainsString(self::HASH, $serialized);
        $restored = unserialize($serialized);
        self::assertInstanceOf(SecurityUser::class, $restored);
        self::assertSame(hash('crc32c', self::HASH), $restored->getPassword());
        self::assertSame('0197a3c0-0000-7000-8000-0000000000aa', $restored->id());
        self::assertSame('lena@acme-foods.example', $restored->getUserIdentifier());
        self::assertSame('Lena Berg', $restored->name());
        self::assertFalse($restored->isBlocked());
    }

    private static function securityUser(): SecurityUser
    {
        $user = new User(new UserId('0197a3c0-0000-7000-8000-0000000000aa'), new Email('Lena@Acme-Foods.example'), 'Lena Berg', 'en', 'Europe/Berlin', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));

        return SecurityUser::of($user, new Credential($user, new PasswordHash(self::HASH), new \DateTimeImmutable('2026-11-02 09:30:00 UTC')));
    }
}
