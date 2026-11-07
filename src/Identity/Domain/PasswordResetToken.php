<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * The secret of a password reset link. Only its SHA-256 is stored, so a leaked table holds no usable link. It can be
 * neither serialized nor dumped, so it never reaches a queue or a log by accident.
 */
final readonly class PasswordResetToken
{
    private const int RANDOM_BYTES = 32;

    private function __construct(#[\SensitiveParameter] private string $value)
    {
    }

    /**
     * 32 random bytes, base64url without padding: 43 characters that need no escaping in a URL path.
     */
    public static function generate(): self
    {
        return new self(rtrim(strtr(base64_encode(random_bytes(self::RANDOM_BYTES)), '+/', '-_'), '='));
    }

    /**
     * A token as a link carried it, unchecked: a wrong one simply matches no reset.
     */
    public static function fromLink(#[\SensitiveParameter] string $value): self
    {
        return new self($value);
    }

    /**
     * The token for the link, for the email only.
     */
    public function reveal(): string
    {
        return $this->value;
    }

    /**
     * @return string the 32 raw bytes of the token's SHA-256, for the bytea column
     */
    public function hash(): string
    {
        return hash('sha256', $this->value, true);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }

    /**
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        throw new \LogicException('A password reset token is never serialized.');
    }
}
