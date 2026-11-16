<?php

declare(strict_types=1);

namespace App\Review\UI;

/**
 * Carries a refused draft action's toast out of Transactions::run, so the transaction rolls back and nothing of the
 * action is kept, as Ticketing's MoveRefusedWithToast.
 */
final class DraftRefusedWithToast extends \RuntimeException
{
    public function __construct(private readonly string $toast)
    {
        parent::__construct('The draft action was refused.');
    }

    public function toast(): string
    {
        return $this->toast;
    }
}
