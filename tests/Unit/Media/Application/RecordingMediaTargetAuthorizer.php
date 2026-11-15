<?php

declare(strict_types=1);

namespace App\Tests\Unit\Media\Application;

use App\Media\Api\MediaTargetAuthorizer;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Lets exactly the people a test allows attach to exactly the targets it names, and keeps every question it was asked.
 * Ids are compared exactly, as a module compares its own ids.
 */
final class RecordingMediaTargetAuthorizer implements MediaTargetAuthorizer
{
    /** @var list<string> each "organization user target" allowed */
    private array $allowed = [];

    /** @var list<array{organizationId: string, userId: string, targetId: string}> */
    private array $asked = [];

    public function __construct(private readonly string $kind)
    {
    }

    public function allow(OrganizationId $organizationId, UserId $userId, string $targetId): void
    {
        $this->allowed[] = self::keyOf($organizationId, $userId, $targetId);
    }

    public function deny(OrganizationId $organizationId, UserId $userId, string $targetId): void
    {
        $this->allowed = array_values(array_diff($this->allowed, [self::keyOf($organizationId, $userId, $targetId)]));
    }

    public function targetKind(): string
    {
        return $this->kind;
    }

    public function mayAttach(OrganizationId $organizationId, UserId $userId, string $targetId): bool
    {
        $this->asked[] = ['organizationId' => $organizationId->toString(), 'userId' => $userId->toString(), 'targetId' => $targetId];

        return \in_array(self::keyOf($organizationId, $userId, $targetId), $this->allowed, true);
    }

    /**
     * @return list<array{organizationId: string, userId: string, targetId: string}> oldest first
     */
    public function asked(): array
    {
        return $this->asked;
    }

    private static function keyOf(OrganizationId $organizationId, UserId $userId, string $targetId): string
    {
        return $organizationId->toString().' '.$userId->toString().' '.$targetId;
    }
}
