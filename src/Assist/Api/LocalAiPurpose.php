<?php

declare(strict_types=1);

namespace App\Assist\Api;

/**
 * What a laptop AI prompt package is for. Only the ticket summary is built; "Check with AI" on the laptop
 * (ast_local_package allows 'intake') comes with P08.T9 step 4.
 */
enum LocalAiPurpose: string
{
    case Summary = 'summary';
}
