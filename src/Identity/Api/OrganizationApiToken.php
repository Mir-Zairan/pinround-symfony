<?php

declare(strict_types=1);

namespace App\Identity\Api;

use App\SharedKernel\Domain\UserId;

/**
 * A live personal access token of the organization, as an Admin sees it in a list. Never the token or its hash.
 */
final readonly class OrganizationApiToken
{
    /**
     * @param string             $id        a canonical UUID
     * @param string             $prefix    "pr_" and the first 8 characters of the random part, to tell tokens apart
     * @param \DateTimeImmutable $expiresAt the token works until just before this instant; whole seconds
     */
    public function __construct(
        public string $id,
        public UserId $ownerId,
        public string $name,
        public string $prefix,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
