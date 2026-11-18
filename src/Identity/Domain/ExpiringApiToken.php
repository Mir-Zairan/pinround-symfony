<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\SharedKernel\Domain\UserId;

/**
 * A live token of the tenant that ApiTokens::claimExpiryWarnings() marked as warned, with what its owner's email names.
 */
final readonly class ExpiringApiToken
{
    /**
     * @param string $prefix "pr_" and the first 8 characters of the random part, as ApiToken::prefix()
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
