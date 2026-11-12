<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use App\Identity\Domain\Credential;
use App\Identity\Domain\Email;
use App\Identity\Domain\PasswordHash;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Identity\Infrastructure\Security\SymfonyCurrentTimeZone;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class SymfonyCurrentTimeZoneTest extends TestCase
{
    private const string LENA = '0197a3c0-0000-7000-8000-0000000000aa';
    private const string HASH = '$argon2id$v=19$m=65536,t=4,p=1$NUkxU2sxQlFxbXpDaUZUQQ$suVp+0jzKZuqeONGVmUghGQxxI2vd02CMokdMtaQcfA';

    /**
     * @return iterable<string, array{string}>
     */
    public static function savedZones(): iterable
    {
        yield 'a current name' => ['Europe/Berlin'];
        yield 'an old name the profile still accepts' => ['Asia/Calcutta'];
        yield 'another old name' => ['Europe/Kiev'];
    }

    #[Test]
    #[DataProvider('savedZones')]
    public function theSavedZoneOfTheLoggedInUserIsUsed(string $savedZone): void
    {
        self::assertSame($savedZone, new SymfonyCurrentTimeZone(self::storageOf(self::lena($savedZone)))->zone()->getName());
    }

    #[Test]
    public function withoutATokenTheZoneIsUtc(): void
    {
        self::assertSame('UTC', new SymfonyCurrentTimeZone(new TokenStorage())->zone()->getName());
    }

    #[Test]
    public function aTokenUserThatIsNotAnIdentityUserGivesUtc(): void
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken(new InMemoryUser('lena', null), 'main'));

        self::assertSame('UTC', new SymfonyCurrentTimeZone($tokenStorage)->zone()->getName());
    }

    #[Test]
    public function aUserWithNoSavedZoneGivesUtc(): void
    {
        $withoutZone = self::lena('Europe/Berlin');
        $withoutZone->__unserialize([...$withoutZone->__serialize(), 'timeZone' => null]);

        self::assertSame('UTC', new SymfonyCurrentTimeZone(self::storageOf($withoutZone))->zone()->getName());
    }

    #[Test]
    public function aSavedZoneThatIsNotAKnownIanaNameGivesUtc(): void
    {
        $unknownZone = self::lena('Europe/Berlin');
        $unknownZone->__unserialize([...$unknownZone->__serialize(), 'timeZone' => 'Mars/Olympus']);

        self::assertSame('UTC', new SymfonyCurrentTimeZone(self::storageOf($unknownZone))->zone()->getName());
    }

    private static function storageOf(SecurityUser $user): TokenStorage
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main'));

        return $tokenStorage;
    }

    private static function lena(string $timeZone): SecurityUser
    {
        $user = new User(new UserId(self::LENA), new Email('lena@acme-foods.example'), 'Lena Berg', 'en', $timeZone, new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));

        return SecurityUser::of($user, new Credential($user, new PasswordHash(self::HASH), new \DateTimeImmutable('2026-11-02 09:30:00 UTC')));
    }
}
