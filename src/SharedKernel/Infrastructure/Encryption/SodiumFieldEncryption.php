<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Encryption;

use App\SharedKernel\Application\FieldEncryption;
use App\SharedKernel\Application\UndecryptableField;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * libsodium secretbox with a key id in front (ADR-0027); FieldEncryption documents the stored format.
 *
 * The key ring is read on first use, not when the container builds this service: the prod cache warm-up and every
 * page that never encrypts must not need the real keys. The parsed ring is process-wide configuration, not request
 * state, so worker mode may keep it (ADR-0008).
 */
final class SodiumFieldEncryption implements FieldEncryption
{
    private const int KEY_ID_LENGTH_BYTES = 1;

    private ?FieldEncryptionKeys $keys = null;

    public function __construct(
        #[\SensitiveParameter]
        #[Autowire(env: 'FIELD_ENCRYPTION_KEYS')]
        private readonly string $configuredKeys,
        #[Autowire(env: 'FIELD_ENCRYPTION_CURRENT_KEY_ID')]
        private readonly string $configuredCurrentKeyId,
    ) {
    }

    public function encrypt(string $plaintext): string
    {
        $keys = $this->keys();
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return pack('C', \strlen($keys->currentKeyId))
            .$keys->currentKeyId
            .$nonce
            .sodium_crypto_secretbox($plaintext, $nonce, $keys->currentKey());
    }

    public function decrypt(string $stored): string
    {
        $keys = $this->keys();
        if ('' === $stored) {
            throw UndecryptableField::tooShort();
        }

        $keyIdLength = \ord($stored[0]);
        $headerLength = self::KEY_ID_LENGTH_BYTES + $keyIdLength + \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        if (0 === $keyIdLength || \strlen($stored) < $headerLength + \SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw UndecryptableField::tooShort();
        }

        $keyId = substr($stored, self::KEY_ID_LENGTH_BYTES, $keyIdLength);
        $key = $keys->keyFor($keyId) ?? throw UndecryptableField::unknownKeyId($keyId, FieldEncryptionKeys::isValidKeyId($keyId));

        $nonce = substr($stored, self::KEY_ID_LENGTH_BYTES + $keyIdLength, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open(substr($stored, $headerLength), $nonce, $key);
        if (false === $plaintext) {
            throw UndecryptableField::altered($keyId);
        }

        return $plaintext;
    }

    private function keys(): FieldEncryptionKeys
    {
        return $this->keys ??= FieldEncryptionKeys::fromConfiguration($this->configuredKeys, $this->configuredCurrentKeyId);
    }
}
