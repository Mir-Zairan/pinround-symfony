<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\NewPassword;
use App\Identity\Domain\PasswordHash;
use App\Identity\Domain\PasswordHashing;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/**
 * Hashes with the hasher security.yaml pins for SecurityUser, the one the login form verifies with.
 */
final readonly class SymfonyPasswordHashing implements PasswordHashing
{
    public function __construct(private PasswordHasherFactoryInterface $hasherFactory)
    {
    }

    public function hash(NewPassword $password): PasswordHash
    {
        return new PasswordHash($this->hasherFactory->getPasswordHasher(SecurityUser::class)->hash($password->reveal()));
    }
}
