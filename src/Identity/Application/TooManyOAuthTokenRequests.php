<?php

declare(strict_types=1);

namespace App\Identity\Application;

final class TooManyOAuthTokenRequests extends \RuntimeException
{
    /**
     * @param int $minutesToWait at least 1, at most the interval of oauth_token_per_ip
     */
    public function __construct(public readonly int $minutesToWait)
    {
        parent::__construct('Too many OAuth token requests from this address.');
    }
}
