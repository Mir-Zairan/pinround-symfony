<?php

declare(strict_types=1);

namespace App\Media\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Implemented by the module that owns a kind of upload target, so Media's voter can ask it without knowing it.
 */
interface MediaTargetAuthorizer
{
    /**
     * The UploadTarget::$kind this authorizer decides; one authorizer per kind.
     */
    public function targetKind(): string;

    /**
     * Whether the request's actor may attach an upload to the target; false for an unknown or malformed $targetId.
     * Implementations decide for the current request actor, never for another person: $userId is a guard that must
     * equal that actor, and they refuse when it does not (or when $organizationId is not the actor's). Uploads calls
     * it, as does Media's voter, inside an open transaction whose tenant is $organizationId, so it must not begin or end
     * one itself.
     */
    public function mayAttach(OrganizationId $organizationId, UserId $userId, string $targetId): bool;
}
