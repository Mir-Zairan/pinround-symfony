<?php

declare(strict_types=1);

namespace App\Media\Application;

use App\Media\Api\MediaTargetAuthorizer;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Every module's MediaTargetAuthorizer by the kind it decides; a kind none decides is refused.
 */
final readonly class MediaTargetAuthorizers
{
    /** @var array<string, MediaTargetAuthorizer> */
    private array $byKind;

    /**
     * @param iterable<MediaTargetAuthorizer> $authorizers
     *
     * @throws \LogicException when two authorizers decide the same kind
     */
    public function __construct(iterable $authorizers)
    {
        $byKind = [];
        foreach ($authorizers as $authorizer) {
            $kind = $authorizer->targetKind();
            if (isset($byKind[$kind])) {
                throw new \LogicException(\sprintf('Two media target authorizers decide the kind "%s".', $kind));
            }
            $byKind[$kind] = $authorizer;
        }
        $this->byKind = $byKind;
    }

    /**
     * False when no authorizer decides $targetKind, so an unknown or unconfigured kind never passes.
     */
    public function mayAttach(OrganizationId $organizationId, UserId $userId, string $targetKind, string $targetId): bool
    {
        $authorizer = $this->byKind[$targetKind] ?? null;

        return null !== $authorizer && $authorizer->mayAttach($organizationId, $userId, $targetId);
    }
}
