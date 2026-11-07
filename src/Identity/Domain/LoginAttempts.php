<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * The append-only history of login tries, kept to match a run of tries to one address or one client. It holds keyed
 * hashes only, never the email, the IP or the password.
 */
interface LoginAttempts
{
    /**
     * Writes the try at once, in its own commit when no transaction is open.
     *
     * @param string $emailHash 32 raw bytes: the keyed hash of the trimmed, lowercase email, so letter case does not split the history
     * @param string $ipHash    32 raw bytes: the keyed hash of the client IP
     */
    public function record(string $emailHash, string $ipHash, bool $success): void;
}
