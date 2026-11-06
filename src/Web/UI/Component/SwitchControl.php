<?php

declare(strict_types=1);

namespace App\Web\UI\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * Used as <twig:Switch>. The class cannot be called Switch because that is a reserved word in PHP.
 */
#[AsTwigComponent('Switch')]
final class SwitchControl
{
    public bool $checked = false;

    /** Accessible name; the visible text next to a switch belongs to the page, not to the component. */
    public string $label = '';

    #[PostMount]
    public function assertHasLabel(): void
    {
        if ('' === trim($this->label)) {
            throw new \InvalidArgumentException('A Switch needs a non-empty label.');
        }
    }
}
