<?php

declare(strict_types=1);

namespace App\Assist\Application;

/**
 * The organization's AI policy forbids this feature for this person (off, leads only, or the ticket may not go to cloud
 * AI). Nothing was sent to a provider.
 */
final class AiNotAllowed extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The AI policy does not allow this feature for this person.');
    }
}
