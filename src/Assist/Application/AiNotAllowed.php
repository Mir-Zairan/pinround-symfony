<?php

declare(strict_types=1);

namespace App\Assist\Application;

use App\Assist\Application\Policy\PolicyRefusal;

/**
 * The organization's AI policy forbids this feature for this person (off, leads only, a ticket they cannot see, a
 * team-only ticket kept from cloud AI, or laptop AI only). Nothing was sent to a provider. The message is the refusal's
 * plain sentence, the one line the person is shown.
 */
final class AiNotAllowed extends \RuntimeException
{
    public function __construct(public readonly PolicyRefusal $refusal)
    {
        parent::__construct($refusal->sentence());
    }
}
