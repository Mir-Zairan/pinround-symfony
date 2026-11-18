<?php

declare(strict_types=1);

namespace App\Assist\UI;

/**
 * What every Assist route answers to a missing or wrong form token: the summary and intake check forms and the laptop
 * calls alike.
 */
final class FormExpired
{
    public const string LINE = 'The page was open too long. Reload it and try again.';

    private function __construct()
    {
    }
}
