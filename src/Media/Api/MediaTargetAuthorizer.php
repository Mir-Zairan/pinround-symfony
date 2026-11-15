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
     * Whether $userId may attach an upload to the target; false for an unknown or malformed $targetId.
     */
    public function mayAttach(OrganizationId $organizationId, UserId $userId, string $targetId): bool;
}
