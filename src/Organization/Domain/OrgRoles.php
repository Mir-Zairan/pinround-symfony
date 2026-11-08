<?php

declare(strict_types=1);

namespace App\Organization\Domain;

/**
 * The org roles of one member, as a set: each role at most once, always in OrgRole's case order. Empty for a plain
 * user of the organization.
 */
final readonly class OrgRoles
{
    /** @var list<OrgRole> */
    private array $roles;

    public function __construct(OrgRole ...$roles)
    {
        $this->roles = array_values(array_filter(
            OrgRole::cases(),
            static fn (OrgRole $role): bool => \in_array($role, $roles, true),
        ));
    }

    /**
     * @param list<string> $values OrgRole values, as stored
     *
     * @throws \ValueError when a value is not an OrgRole
     */
    public static function fromValues(array $values): self
    {
        return new self(...array_map(OrgRole::from(...), $values));
    }

    public function has(OrgRole $role): bool
    {
        return \in_array($role, $this->roles, true);
    }

    public function isAdmin(): bool
    {
        return $this->has(OrgRole::Admin);
    }

    /**
     * @return list<OrgRole>
     */
    public function toList(): array
    {
        return $this->roles;
    }

    /**
     * @return list<string>
     */
    public function values(): array
    {
        return array_map(static fn (OrgRole $role): string => $role->value, $this->roles);
    }

    public function equals(self $other): bool
    {
        return $this->roles === $other->roles;
    }
}
