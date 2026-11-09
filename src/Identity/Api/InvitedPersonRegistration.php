<?php

declare(strict_types=1);

namespace App\Identity\Api;

use App\SharedKernel\Domain\UserId;

/**
 * Creates the account of a person who accepts an invitation (FR-ID-02), for the accept page.
 */
interface InvitedPersonRegistration
{
    /**
     * In characters, not bytes.
     */
    public function minimumPasswordLength(): int;

    /**
     * In characters, not bytes, after trimming.
     */
    public function maximumNameLength(): int;

    /**
     * Creates an active user who can log in with this email and password. Its writes share the caller's transaction,
     * so the invitation is used up and the membership added together with the account, or none of them.
     *
     * @param string $email stored, and compared with existing accounts, in lowercase and trimmed
     * @param string $name  stored trimmed
     *
     * @throws RegistrationRefused before anything is saved, listing every refused input; when the email is invalid the
     *                             name is not checked. Also, with EmailTaken alone, when another transaction added an
     *                             account with this email at the same moment: the transaction is then failed and must
     *                             be rolled back
     */
    public function register(string $email, string $name, #[\SensitiveParameter] string $password, \DateTimeImmutable $now): UserId;
}
