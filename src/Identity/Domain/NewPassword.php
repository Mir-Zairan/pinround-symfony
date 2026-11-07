<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * A plain password a person has just chosen, before it is hashed. At least 12 characters and no composition rules
 * (ADR-0012). It can be neither serialized nor dumped, so it never reaches a session, a queue or a log.
 */
final readonly class NewPassword
{
    public const int MINIMUM_LENGTH = 12;

    private string $plainText;

    /**
     * @throws PasswordTooShort when it has fewer than 12 characters (characters, not bytes)
     */
    public function __construct(#[\SensitiveParameter] string $plainText)
    {
        if (mb_strlen($plainText, 'UTF-8') < self::MINIMUM_LENGTH) {
            throw new PasswordTooShort(\sprintf('A password needs at least %d characters.', self::MINIMUM_LENGTH));
        }

        $this->plainText = $plainText;
    }

    /**
     * The plain password, for the hasher only.
     */
    public function reveal(): string
    {
        return $this->plainText;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['plainText' => '[redacted]'];
    }

    /**
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        throw new \LogicException('A plain password is never serialized.');
    }
}
