<?php

declare(strict_types=1);

namespace App\Assist\Application;

/**
 * The ticket or draft report a prompt is about, as the asking person may see it. The prompt builder treats both fields
 * as untrusted data: it delimits, cleans and cuts them.
 */
final readonly class PromptTicket
{
    public function __construct(
        public string $title,
        public ?string $text = null,
    ) {
    }
}
