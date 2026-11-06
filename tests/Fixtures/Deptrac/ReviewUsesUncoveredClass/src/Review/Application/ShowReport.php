<?php

declare(strict_types=1);

namespace App\Review\Application;

use App\Review\Helpers\Formatter;

final readonly class ShowReport
{
    public function __construct(private Formatter $formatter)
    {
    }

    public function show(string $text): string
    {
        return $this->formatter->format($text);
    }
}
