<?php

declare(strict_types=1);

namespace App\Web\UI\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Markup only. It has no live region of its own: the page's `.toast-region` (role="status") announces it, which it does
 * only for text added after the region is shown, so a flashed toast starts empty and its `toast` controller fills it.
 */
#[AsTwigComponent]
final class Toast
{
}
