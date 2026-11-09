<?php

declare(strict_types=1);

namespace App\Workflow\Domain;

enum StepOrigin: string
{
    case Core = 'core';
    case Company = 'company';
    case Team = 'team';
}
