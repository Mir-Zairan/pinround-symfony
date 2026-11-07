<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Organization\Domain\Membership;
use App\Organization\Domain\Memberships;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Memberships in memory. Nothing locks; the calls are logged in order, so a test can tell the lock came first.
 */
final class InMemoryMemberships implements Memberships
{
    /** @var array<string, Membership> by organization id and user id */
    private array $memberships = [];
    /** @var list<string> */
    public array $calls = [];

    public function __construct(Membership ...$memberships)
    {
        foreach ($memberships as $membership) {
            $this->memberships[self::key($membership->organizationId(), $membership->userId())] = $membership;
        }
    }

    public function of(OrganizationId $organizationId, UserId $userId): ?Membership
    {
        $this->calls[] = 'of';

        return $this->memberships[self::key($organizationId, $userId)] ?? null;
    }

    public function lockAdminsOf(OrganizationId $organizationId): array
    {
        $this->calls[] = 'lockAdminsOf';

        return array_values(array_filter(
            $this->memberships,
            static fn (Membership $membership): bool => $membership->organizationId()->equals($organizationId) && $membership->orgRoles()->isAdmin(),
        ));
    }

    public function add(Membership $membership): void
    {
        $this->calls[] = 'add';
        $this->memberships[self::key($membership->organizationId(), $membership->userId())] = $membership;
    }

    public function save(Membership $membership): void
    {
        $this->calls[] = 'save';
    }

    /**
     * @return list<Membership>
     */
    public function all(): array
    {
        return array_values($this->memberships);
    }

    private static function key(OrganizationId $organizationId, UserId $userId): string
    {
        return $organizationId->toString().'/'.$userId->toString();
    }
}
