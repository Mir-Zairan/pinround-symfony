<?php

declare(strict_types=1);

namespace App\Notification\UI\Component;

use App\Notification\Api\BellView;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The body of the bell's popover: its title with the open count, Close, and one PingCard per group, or "No open pings.".
 * Its root keeps the id ROOT_ID, so a stream answer can replace it.
 */
#[AsTwigComponent]
final class BellPopover
{
    public const string ROOT_ID = 'bell-popover-body';

    public BellView $bell;
}
