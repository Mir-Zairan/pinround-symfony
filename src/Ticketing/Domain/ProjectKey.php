<?php

declare(strict_types=1);

namespace App\Ticketing\Domain;

/**
 * The key of a project, the first part of its ticket keys ("SHOP" in "SHOP-41"): 2 to 10 capital letters A to Z or
 * digits, starting with a letter (FR-ORG-06). Unique within an organization.
 */
final readonly class ProjectKey
{
    // \z, because $ would also accept a trailing newline.
    private const string FORMAT = '/^[A-Z][A-Z0-9]{1,9}\z/';

    /**
     * @throws InvalidProjectKey when the value does not follow the format; it is never changed to fit
     */
    public function __construct(private string $value)
    {
        if (1 !== preg_match(self::FORMAT, $value)) {
            throw new InvalidProjectKey('A project key is 2 to 10 capital letters or digits and starts with a letter.');
        }
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
