<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * The 10 recovery codes of a two-step login, in plain text, shown to the user once when it is turned on; only their
 * hashes are stored. Each one logs in once in place of an authenticator code. It can be neither serialized nor dumped.
 */
final readonly class RecoveryCodes
{
    public const int COUNT = 10;
    // Crockford's base32: no i, l, o or u, so no two characters look alike. 32 characters, 5 bits each.
    private const string ALPHABET = '0123456789abcdefghjkmnpqrstvwxyz';
    private const int CHARACTERS_PER_GROUP = 5;

    /**
     * @param list<string> $codes
     */
    private function __construct(#[\SensitiveParameter] private array $codes)
    {
    }

    /**
     * Ten different codes such as "k7m2q-x9p4t": two groups of 5 characters, 50 random bits each.
     */
    public static function generate(): self
    {
        $codes = [];
        while (\count($codes) < self::COUNT) {
            $codes[self::randomCode()] = true;
        }

        return new self(array_keys($codes));
    }

    /**
     * @return list<string> in the order they are shown
     */
    public function reveal(): array
    {
        return $this->codes;
    }

    private static function randomCode(): string
    {
        // 256 is a multiple of 32, so taking each byte modulo 32 keeps every character equally likely.
        $characters = '';
        foreach (str_split(random_bytes(2 * self::CHARACTERS_PER_GROUP)) as $byte) {
            $characters .= self::ALPHABET[\ord($byte) % 32];
        }

        return substr($characters, 0, self::CHARACTERS_PER_GROUP).'-'.substr($characters, self::CHARACTERS_PER_GROUP);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['codes' => '[redacted]'];
    }

    /**
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        throw new \LogicException('Recovery codes are never serialized.');
    }
}
