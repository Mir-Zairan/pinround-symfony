<?php

declare(strict_types=1);

namespace App\SharedKernel\Application;

/**
 * A stored encrypted field cannot be read back. The message is safe to log: it names at most a valid key id, never
 * the stored bytes or the plaintext.
 */
final class UndecryptableField extends \RuntimeException
{
    public static function tooShort(): self
    {
        return new self('The stored field is too short to hold a key id, a nonce and a ciphertext.');
    }

    public static function unknownKeyId(string $keyId, bool $isValidKeyId): self
    {
        if (!$isValidKeyId) {
            return new self('No field encryption key has the stored key id, which is not a valid id.');
        }

        return new self(\sprintf('No field encryption key has the id "%s".', $keyId));
    }

    public static function altered(string $keyId): self
    {
        return new self(\sprintf('The field encrypted with key "%s" is altered or was written with another key.', $keyId));
    }
}
