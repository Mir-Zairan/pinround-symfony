<?php

declare(strict_types=1);

namespace App\Web\UI\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * Renders buttons from `options` + `selected`, or page links from `links`; never both.
 */
#[AsTwigComponent]
final class SegmentedControl
{
    /** Accessible name of the group. */
    public string $label = '';

    /** @var array<array-key, string> option value => visible label, in display order */
    public array $options = [];

    /** One of the option values. */
    public string $selected = '';

    /**
     * Link mode: each segment is a link to another page; exactly one is the current page.
     *
     * @var list<array{label: string, href: string, isCurrent: bool}> in display order
     */
    public array $links = [];

    #[PostMount]
    public function assertValid(): void
    {
        if ('' === trim($this->label)) {
            throw new \InvalidArgumentException('A SegmentedControl needs a non-empty label.');
        }
        if ([] !== $this->links) {
            $this->assertValidLinks();

            return;
        }
        // PHP turns numeric-string keys into ints, so compare as strings.
        if (!\in_array($this->selected, array_map(strval(...), array_keys($this->options)), true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not one of the SegmentedControl options.', $this->selected));
        }
    }

    private function assertValidLinks(): void
    {
        if ([] !== $this->options || '' !== $this->selected) {
            throw new \InvalidArgumentException('A SegmentedControl takes either options or links, not both.');
        }
        $currentCount = 0;
        foreach ($this->links as $link) {
            /** @var mixed $link the prop comes from a template, so its shape is not guaranteed */
            if (!\is_array($link)
                || !\is_string($link['label'] ?? null) || '' === trim($link['label'])
                || !\is_string($link['href'] ?? null) || '' === trim($link['href'])
                || !\is_bool($link['isCurrent'] ?? null)) {
                throw new \InvalidArgumentException('Each SegmentedControl link needs a label, an href and isCurrent.');
            }
            if ($link['isCurrent']) {
                ++$currentCount;
            }
        }
        if (1 !== $currentCount) {
            throw new \InvalidArgumentException(\sprintf('A SegmentedControl needs exactly one current link, got %d.', $currentCount));
        }
    }
}
