<?php

declare(strict_types=1);

namespace App\Workflow\Api;

/**
 * A verb button as the ticket page shows it.
 */
final readonly class TransitionView
{
    /**
     * @param string $verb      the verb's stable code, for example "plan_it"; the page sends it back to make the move
     * @param string $label     the button text, for example "Plan it"
     * @param bool   $isPrimary true for the filled button, false for an outline one
     */
    public function __construct(public string $verb, public string $label, public bool $isPrimary)
    {
    }
}
