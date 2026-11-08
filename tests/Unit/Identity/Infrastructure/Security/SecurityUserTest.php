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
use Symfony\Component\Security\Core\User\InMemoryUser;

final class SecurityUserTest extends TestCase
{
    private const string HASH = '$argon2id$v=19$m=65536,t=4,p=1$NUkxU2sxQlFxbXpDaUZUQQ$suVp+0jzKZuqeONGVmUghGQxxI2vd02CMokdMtaQcfA';
    private const string OTHER_HASH = '$argon2id$v=19$m=65536,t=4,p=1$b3RoZXJzYWx0b3RoZXJzYQ$J4moa2MM0/6uf3HbY+Tf6kq/zEBh9P1dZEyCTdhTfhg';

    #[Test]
    public function itCarriesTheUsersIdEmailNameThemeStampAndHash(): void
    {
        $user = self::user();
        $securityUser = self::securityUser($user);

        self::assertSame('0197a3c0-0000-7000-8000-0000000000aa', $securityUser->id());
        self::assertSame('lena@acme-foods.example', $securityUser->getUserIdentifier());
        self::assertSame('Lena Berg', $securityUser->name());
        self::assertSame('system', $securityUser->theme());
        self::assertSame('Europe/Berlin', $securityUser->timeZone());
        self::assertSame($user->sessionStamp(), $securityUser->sessionStamp());
        self::assertSame(self::HASH, $securityUser->getPassword());
        self::assertSame(['ROLE_USER'], $securityUser->getRoles());
        self::assertFalse($securityUser->isBlocked());
    }

    #[Test]
    public function theSessionHoldsACrc32cOfTheHashInsteadOfTheHashAndKeepsTheStamp(): void
    {
        $user = self::user();
        $serialized = serialize(self::securityUser($user));

        self::assertStringNotContainsString(self::HASH, $serialized);
        $restored = self::restored($serialized);
        self::assertSame(hash('crc32c', self::HASH), $restored->getPassword());
        self::assertSame($user->sessionStamp(), $restored->sessionStamp());
        self::assertSame('0197a3c0-0000-7000-8000-0000000000aa', $restored->id());
        self::assertSame('lena@acme-foods.example', $restored->getUserIdentifier());
        self::assertSame('Lena Berg', $restored->name());
        self::assertSame('system', $restored->theme());
        self::assertSame('Europe/Berlin', $restored->timeZone());
        self::assertFalse($restored->isBlocked());
    }

    #[Test]
    public function theUserFromTheSessionEqualsTheRefreshedUserWithTheSameStampAndHash(): void
    {
        $user = self::user();
        $fromSession = self::restored(serialize(self::securityUser($user)));

        self::assertTrue($fromSession->isEqualTo(self::securityUser($user)));
        self::assertTrue(self::securityUser($user)->isEqualTo(self::securityUser($user)));
    }

    #[Test]
    public function aRotatedStampMakesTheRefreshedUserDifferent(): void
    {
        $user = self::user();
        $fromSession = self::restored(serialize(self::securityUser($user)));

        $user->rotateSessionStamp();

        self::assertFalse($fromSession->isEqualTo(self::securityUser($user)));
    }

    #[Test]
    public function aChangedPasswordMakesTheRefreshedUserDifferent(): void
    {
        $user = self::user();
        $fromSession = self::restored(serialize(self::securityUser($user)));

        self::assertFalse($fromSession->isEqualTo(self::securityUser($user, self::OTHER_HASH)));
    }

    #[Test]
    public function aChangedEmailOrIdMakesTheRefreshedUserDifferent(): void
    {
        $user = self::user();
        $fromSession = self::restored(serialize(self::securityUser($user)));
        $sameStampOtherEmail = self::restored(str_replace('lena@acme-foods.example', 'omar@acme-foods.example', serialize(self::securityUser($user))));
        $sameStampOtherId = self::restored(str_replace('0000000000aa', '0000000000bb', serialize(self::securityUser($user))));

        self::assertFalse($fromSession->isEqualTo($sameStampOtherEmail));
        self::assertFalse($fromSession->isEqualTo($sameStampOtherId));
    }

    #[Test]
    public function aChangedNameOrThemeKeepsTheSession(): void
    {
        $user = self::user();
        $savedBeforeTheProfileChange = str_replace(
            ['s:9:"Lena Berg"', 's:6:"system"'],
            ['s:9:"Lena Lund"', 's:4:"dark"'],
            serialize(self::securityUser($user)),
        );
        $fromSession = self::restored($savedBeforeTheProfileChange);
        self::assertSame('Lena Lund', $fromSession->name());
        self::assertSame('dark', $fromSession->theme());

        self::assertTrue($fromSession->isEqualTo(self::securityUser($user)));
    }

    #[Test]
    public function anUnknownThemeInTheSessionComesBackAsSystem(): void
    {
        $fromSession = self::restored(str_replace('s:6:"system"', 's:4:"blue"', serialize(self::securityUser(self::user()))));

        self::assertSame('system', $fromSession->theme());
    }

    #[Test]
    public function aSessionSavedBeforeTheStampExistedIsLoggedOut(): void
    {
        $user = self::user();
        $withoutStamp = 'O:'.\strlen(SecurityUser::class).':"'.SecurityUser::class.'":5:{s:2:"id";s:36:"0197a3c0-0000-7000-8000-0000000000aa";'
            .'s:5:"email";s:23:"lena@acme-foods.example";s:4:"name";s:9:"Lena Berg";s:9:"isBlocked";b:0;'
            .'s:12:"passwordHash";s:8:"'.hash('crc32c', self::HASH).'";}';

        $fromOldSession = self::restored($withoutStamp);

        self::assertSame('', $fromOldSession->sessionStamp());
        self::assertSame('system', $fromOldSession->theme());
        self::assertNull($fromOldSession->timeZone());
        self::assertFalse($fromOldSession->isEqualTo(self::securityUser($user)));
    }

    #[Test]
    public function anotherKindOfUserIsNeverEqual(): void
    {
        self::assertFalse(self::securityUser(self::user())->isEqualTo(new InMemoryUser('lena@acme-foods.example', self::HASH, ['ROLE_USER'])));
    }

    private static function user(): User
    {
        return new User(new UserId('0197a3c0-0000-7000-8000-0000000000aa'), new Email('Lena@Acme-Foods.example'), 'Lena Berg', 'en', 'Europe/Berlin', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));
    }

    private static function securityUser(User $user, string $hash = self::HASH): SecurityUser
    {
        return SecurityUser::of($user, new Credential($user, new PasswordHash($hash), new \DateTimeImmutable('2026-11-02 09:30:00 UTC')));
    }

    private static function restored(string $serialized): SecurityUser
    {
        $restored = unserialize($serialized);
        self::assertInstanceOf(SecurityUser::class, $restored);

        return $restored;
    }
}
