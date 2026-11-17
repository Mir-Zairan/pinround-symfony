<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Application\Gateway;

use App\Assist\Application\AiCallLimit;
use App\Assist\Application\AiRateLimited;
use App\Organization\Api\ActorContext;

/**
 * The limit of 30 calls per person in an organization, as SymfonyAiCallLimit keys it; a refused call is not counted.
 */
final class CountingAiCallLimit implements AiCallLimit
{
    public const int LIMIT = 30;

    /** @var array<string, int> calls by "organization:user" */
    public array $counted = [];

    public function consume(ActorContext $caller): void
    {
        $key = $caller->organizationId()->toString().':'.$caller->userId()->toString();
        if (($this->counted[$key] ?? 0) >= self::LIMIT) {
            throw new AiRateLimited(provider: null);
        }
        $this->counted[$key] = ($this->counted[$key] ?? 0) + 1;
    }
}
