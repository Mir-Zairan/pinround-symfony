<?php

declare(strict_types=1);

namespace App\Assist\Api;

/**
 * The id of one "Check with AI" of a draft report: a UUIDv7 in canonical 8-4-4-4-12 form, kept in lowercase, which
 * also becomes the id of its ast_suggestion row.
 */
final readonly class IntakeCheckId
{
    // Version nibble 7 and variant bits 10xx; \z, because $ would also accept a trailing newline.
    private const string CANONICAL_UUID_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    private string $value;

    /**
     * @throws \InvalidArgumentException when the value is not a canonical UUIDv7 (any case)
     */
    public function __construct(string $value)
    {
        $lowercase = strtolower($value);
        if (1 !== preg_match(self::CANONICAL_UUID_V7, $lowercase)) {
            throw new \InvalidArgumentException('An intake check id must be a canonical UUIDv7.');
        }

        $this->value = $lowercase;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
