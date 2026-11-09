<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\RecoveryCodeHashing;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Hashes with the hasher security.yaml pins for SecurityUser (Argon2id), the one passwords use.
 */
final readonly class SymfonyRecoveryCodeHashing implements RecoveryCodeHashing
{
    public function __construct(private PasswordHasherFactoryInterface $hasherFactory)
    {
    }

    public function hash(#[\SensitiveParameter] string $recoveryCode): string
    {
        return $this->hasherFactory->getPasswordHasher(SecurityUser::class)->hash($recoveryCode);
    }

    public function matches(string $hash, #[\SensitiveParameter] string $typedRecoveryCode): bool
    {
        return $this->hasherFactory->getPasswordHasher(SecurityUser::class)->verify($hash, $typedRecoveryCode);
    }
}
