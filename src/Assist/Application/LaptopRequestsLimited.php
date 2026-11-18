<?php

declare(strict_types=1);

namespace App\Assist\Application;

/**
 * The person sent as many laptop AI requests this minute as LaptopRequestLimit allows. The message is the one line
 * the person is shown.
 */
final class LaptopRequestsLimited extends \RuntimeException
{
    public const string MESSAGE = 'You sent too many laptop AI requests. Wait a minute and try again.';

    /**
     * @param int $retryAfterSeconds until the next minute begins, 1 to 60
     */
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct(self::MESSAGE);
    }
}
