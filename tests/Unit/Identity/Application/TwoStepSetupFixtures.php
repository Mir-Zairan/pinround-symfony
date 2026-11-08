<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Domain\Email;
use App\Identity\Domain\RecoveryCodeHashing;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\OtphpOneTimeCodes;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Encryption\SodiumFieldEncryption;
use OTPHP\TOTP;
use Symfony\Component\Clock\MockClock;

/**
 * The real seed, code and encryption services for the two-step login commands, with a test key and a hashing that is
 * readable in assertions.
 */
trait TwoStepSetupFixtures
{
    private static function lena(): User
    {
        return new User(new UserId('0195a1b2-0000-7000-8000-0000000000aa'), new Email('lena@acme-foods.example'), 'Lena Berg', 'en', 'Europe/Berlin', new \DateTimeImmutable('2026-11-01 08:00:00 UTC'));
    }

    private static function fieldEncryption(): SodiumFieldEncryption
    {
        return new SodiumFieldEncryption('unit:'.base64_encode(str_repeat('u', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES)), 'unit');
    }

    private static function oneTimeCodes(): OtphpOneTimeCodes
    {
        return new OtphpOneTimeCodes(new MockClock('2001-01-01 00:00:00 UTC'));
    }

    private static function codeAt(string $seed, \DateTimeImmutable $at): string
    {
        $timestamp = $at->getTimestamp();
        \assert('' !== $seed && $timestamp >= 0);

        return TOTP::createFromSecret($seed, new MockClock())->at($timestamp);
    }

    private static function readableHashing(): RecoveryCodeHashing
    {
        return new class implements RecoveryCodeHashing {
            public function hash(#[\SensitiveParameter] string $recoveryCode): string
            {
                return 'hash of '.$recoveryCode;
            }

            public function matches(string $hash, #[\SensitiveParameter] string $typedRecoveryCode): bool
            {
                return $hash === 'hash of '.$typedRecoveryCode;
            }
        };
    }
}
