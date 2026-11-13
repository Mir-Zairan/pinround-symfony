<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

/**
 * Carries a refused move's toast out of Transactions::run, so the transaction rolls back: the toast's names are read
 * inside it, as reads need the open transaction, but nothing of a refused move may be committed.
 */
final class MoveRefusedWithToast extends \RuntimeException
{
    public function __construct(private readonly string $toast)
    {
        parent::__construct('The move was refused.');
    }

    public function toast(): string
    {
        return $this->toast;
    }
}
