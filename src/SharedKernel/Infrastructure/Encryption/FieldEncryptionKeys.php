<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Encryption;

/**
 * The key ring of field encryption: every key that may still have written a stored value, and the id of the one new
 * writes use. Refusal messages name the env variables and key ids, never key material.
 */
final readonly class FieldEncryptionKeys
{
    private const string KEY_ID_PATTERN = '/^[A-Za-z0-9_-]{1,32}$/D';

    /**
     * @param array<string, string> $keysById raw 32-byte keys
     */
    private function __construct(public string $currentKeyId, private array $keysById)
    {
    }

    /**
     * @param string $keyList      FIELD_ENCRYPTION_KEYS: a comma list of `<id>:<base64 of a 32-byte key>`
     * @param string $currentKeyId FIELD_ENCRYPTION_CURRENT_KEY_ID: one of the ids in the list
     *
     * @throws \InvalidArgumentException naming what is wrong
     */
    public static function fromConfiguration(#[\SensitiveParameter] string $keyList, string $currentKeyId): self
    {
        if ('' === trim($keyList)) {
            throw new \InvalidArgumentException('FIELD_ENCRYPTION_KEYS is empty; set it to a comma list of <id>:<base64 32-byte key>.');
        }

        $keysById = [];
        foreach (explode(',', $keyList) as $index => $entry) {
            $entryNumber = $index + 1;
            $parts = explode(':', trim($entry), 2);
            if (2 !== \count($parts)) {
                throw new \InvalidArgumentException(\sprintf('FIELD_ENCRYPTION_KEYS entry %d is not <id>:<base64 key>.', $entryNumber));
            }

            [$keyId, $encodedKey] = $parts;
            if (!self::isValidKeyId($keyId)) {
                throw new \InvalidArgumentException(\sprintf('FIELD_ENCRYPTION_KEYS entry %d has a key id that is not 1 to 32 letters, digits, "-" or "_".', $entryNumber));
            }
            if (\array_key_exists($keyId, $keysById)) {
                throw new \InvalidArgumentException(\sprintf('The key id "%s" appears twice in FIELD_ENCRYPTION_KEYS.', $keyId));
            }

            $key = base64_decode($encodedKey, true);
            if (false === $key) {
                throw new \InvalidArgumentException(\sprintf('The key "%s" in FIELD_ENCRYPTION_KEYS is not base64.', $keyId));
            }
            if (\SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== \strlen($key)) {
                throw new \InvalidArgumentException(\sprintf('The key "%s" in FIELD_ENCRYPTION_KEYS is %d bytes; it must be %d.', $keyId, \strlen($key), \SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
            }

            $keysById[$keyId] = $key;
        }

        if ('' === $currentKeyId) {
            throw new \InvalidArgumentException('FIELD_ENCRYPTION_CURRENT_KEY_ID is empty; set it to the id of the key new writes use.');
        }
        if (!\array_key_exists($currentKeyId, $keysById)) {
            throw new \InvalidArgumentException(\sprintf('FIELD_ENCRYPTION_CURRENT_KEY_ID "%s" is not one of the ids in FIELD_ENCRYPTION_KEYS (%s).', self::isValidKeyId($currentKeyId) ? $currentKeyId : '?', implode(', ', array_keys($keysById))));
        }

        return new self($currentKeyId, $keysById);
    }

    public static function isValidKeyId(string $keyId): bool
    {
        return 1 === preg_match(self::KEY_ID_PATTERN, $keyId);
    }

    public function currentKey(): string
    {
        return $this->keysById[$this->currentKeyId];
    }

    public function keyFor(string $keyId): ?string
    {
        return $this->keysById[$keyId] ?? null;
    }
}
