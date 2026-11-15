<?php

declare(strict_types=1);

namespace App\Notification\Application\Digest;

/**
 * Emails one person their digest when one is due (SendDigestHandler). Sent with a TenantStamp of $organizationId,
 * which the handler refuses to differ from.
 */
final readonly class SendDigest
{
    public function __construct(
        public string $organizationId,
        public string $userId,
    ) {
    }
}
