<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\OAuthClientRegistrationLimiter;
use App\Identity\Application\TooManyOAuthClientRegistrations;

/**
 * Records every client IP it counts; once refusing, it counts the request and then refuses, as the real limiter does.
 */
final class RecordingOAuthClientRegistrationLimiter implements OAuthClientRegistrationLimiter
{
    /** @var list<string> */
    public array $consumedIps = [];

    public bool $isRefusing = false;

    public function consume(string $clientIp): void
    {
        $this->consumedIps[] = $clientIp;
        if ($this->isRefusing) {
            throw new TooManyOAuthClientRegistrations(60);
        }
    }
}
