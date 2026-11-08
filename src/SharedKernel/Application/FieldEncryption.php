<?php

declare(strict_types=1);

namespace App\SharedKernel\Application;

/**
 * Encrypts a short secret (an MFA seed, an integration token, a client or webhook secret) for a bytea column and
 * reads it back (ADR-0027). Ticket text never goes through it: it must stay searchable.
 *
 * The stored value is one byte holding the key id's length (1 to 32), the key id, the 24-byte random nonce, then the
 * libsodium secretbox ciphertext (XSalsa20-Poly1305: the plaintext plus a 16-byte tag). Writes use the current key;
 * reads pick the key by the stored id, so values written before a key rotation still decrypt while their key is in
 * the ring.
 */
interface FieldEncryption
{
    /**
     * @return string binary bytes for a bytea column; a new random nonce each call, so equal plaintexts differ
     *
     * @throws \InvalidArgumentException when the key configuration is missing or broken
     */
    public function encrypt(string $plaintext): string;

    /**
     * @param string $stored a value encrypt() returned
     *
     * @throws UndecryptableField        when the value is too short, its key id is not in the ring, or it was altered
     * @throws \InvalidArgumentException when the key configuration is missing or broken
     */
    public function decrypt(string $stored): string;
}
