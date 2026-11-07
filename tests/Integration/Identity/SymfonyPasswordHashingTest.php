<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Domain\NewPassword;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Identity\Infrastructure\Security\SymfonyPasswordHashing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * With the hasher security.yaml configures (the test env lowers only the cost).
 */
final class SymfonyPasswordHashingTest extends KernelTestCase
{
    #[Test]
    public function aStoredHashIsArgon2idAndTheLoginHasherAcceptsIt(): void
    {
        $hasherFactory = self::getContainer()->get('security.password_hasher_factory');

        $hash = new SymfonyPasswordHashing($hasherFactory)->hash(new NewPassword('correct horse battery staple'));

        self::assertStringStartsWith('$argon2id$', $hash->encoded());
        $loginHasher = $hasherFactory->getPasswordHasher(SecurityUser::class);
        self::assertTrue($loginHasher->verify($hash->encoded(), 'correct horse battery staple'));
        self::assertFalse($loginHasher->verify($hash->encoded(), 'correct horse battery stapler'));
    }

    #[Test]
    public function theSamePasswordGetsADifferentSaltEachTime(): void
    {
        $hasherFactory = self::getContainer()->get('security.password_hasher_factory');
        $hashing = new SymfonyPasswordHashing($hasherFactory);

        self::assertNotSame(
            $hashing->hash(new NewPassword('correct horse battery staple'))->encoded(),
            $hashing->hash(new NewPassword('correct horse battery staple'))->encoded(),
        );
    }
}
