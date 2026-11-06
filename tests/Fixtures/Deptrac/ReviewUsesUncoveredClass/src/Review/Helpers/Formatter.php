<?php

declare(strict_types=1);

namespace App\Review\Helpers;

final readonly class Formatter
{
    public function format(string $text): string
    {
        return trim($text);
    }
}
