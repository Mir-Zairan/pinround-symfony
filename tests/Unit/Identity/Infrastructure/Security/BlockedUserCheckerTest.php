<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use App\Identity\Domain\Credential;
use App\Identity\Domain\Email;
use App\Identity\Domain\PasswordHash;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\BlockedUserChecker;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\DisabledException;

final class BlockedUserCheckerTest extends TestCase
{
    private const string HASH = '$argon2id$v=19$m=10,t=3,p=1$stored';

    #[Test]
    public function aBlockedUserIsRefusedAfterThePasswordCheckOnly(): void
    {
        $checker = new BlockedUserChecker();
        $blocked = self::securityUser(isBlocked: true);

        $checker->checkPreAuth($blocked);

        $this->expectException(DisabledException::class);
        $checker->checkPostAuth($blocked);
    }

    #[Test]
    public function anActiveUserPassesBothChecks(): void
    {
        $checker = new BlockedUserChecker();
        $active = self::securityUser(isBlocked: false);

        $checker->checkPreAuth($active);
        $checker->checkPostAuth($active);

        $this->addToAssertionCount(1);
    }

    /**
     * The domain has no way to block a User yet (that comes with the admin pages), so the flag is set on the
     * serialized form, as a session would hold it.
     */
    private static function securityUser(bool $isBlocked): SecurityUser
    {
        $user = new User(new UserId('0197a3c0-0000-7000-8000-0000000000bb'), new Email('mia@acme-foods.example'), 'Mia Lund', 'en', 'Europe/Berlin', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));
        $securityUser = SecurityUser::of($user, new Credential($user, new PasswordHash(self::HASH), new \DateTimeImmutable('2026-11-02 09:30:00 UTC')));
        $data = $securityUser->__serialize();
        $data['isBlocked'] = $isBlocked;
        $restored = new \ReflectionClass(SecurityUser::class)->newInstanceWithoutConstructor();
        $restored->__unserialize($data);

        return $restored;
    }
}
