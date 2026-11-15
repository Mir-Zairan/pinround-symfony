<?php

declare(strict_types=1);

namespace App\Media\Domain;

/**
 * The id of an upload intent: a UUIDv7 in canonical 8-4-4-4-12 form, kept in lowercase (ADR-0006). It is also the last
 * part of the intent's upload key, so nothing but these 36 characters can reach the key.
 */
final readonly class UploadIntentId
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
            throw new \InvalidArgumentException('An upload intent id must be a canonical UUIDv7.');
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
