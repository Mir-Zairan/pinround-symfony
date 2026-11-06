<?php

declare(strict_types=1);

namespace App\Web\UI\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * One plain sentence and, optionally, one action given in the `action` block.
 */
#[AsTwigComponent]
final class EmptyState
{
    public string $message = '';

    #[PostMount]
    public function assertHasMessage(): void
    {
        if ('' === trim($this->message)) {
            throw new \InvalidArgumentException('An EmptyState needs a non-empty message.');
        }
    }
}
