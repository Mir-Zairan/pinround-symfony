<?php

declare(strict_types=1);

namespace App\Web\UI\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsTwigComponent]
final class Card
{
    private const array ELEMENTS = ['div', 'section', 'article'];

    /** The HTML element to render; it is written into the template, so only the whitelist is accepted. */
    public string $tag = 'div';

    #[PostMount]
    public function assertKnownElement(): void
    {
        if (!\in_array($this->tag, self::ELEMENTS, true)) {
            throw new \InvalidArgumentException(\sprintf('A Card element must be one of %s.', implode(', ', self::ELEMENTS)));
        }
    }
}
