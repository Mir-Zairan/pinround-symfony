<?php

declare(strict_types=1);

namespace App\Web\UI\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsTwigComponent]
final class SegmentedControl
{
    /** Accessible name of the group. */
    public string $label = '';

    /** @var array<array-key, string> option value => visible label, in display order */
    public array $options = [];

    /** One of the option values. */
    public string $selected = '';

    #[PostMount]
    public function assertValid(): void
    {
        if ('' === trim($this->label)) {
            throw new \InvalidArgumentException('A SegmentedControl needs a non-empty label.');
        }
        // PHP turns numeric-string keys into ints, so compare as strings.
        if (!\in_array($this->selected, array_map(strval(...), array_keys($this->options)), true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not one of the SegmentedControl options.', $this->selected));
        }
    }
}
