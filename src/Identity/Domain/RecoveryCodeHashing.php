<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * Hashes the recovery codes of two-step login like passwords (Argon2id, ADR-0012). Not PasswordHashing: that one takes
 * only a NewPassword, which holds the password rules (at least 12 characters), and a recovery code is not a password.
 */
interface RecoveryCodeHashing
{
    /**
     * @return string the hash in crypt form ("$argon2id$..."), with a fresh salt each time
     */
    public function hash(#[\SensitiveParameter] string $recoveryCode): string;

    public function matches(string $hash, #[\SensitiveParameter] string $typedRecoveryCode): bool;
}
