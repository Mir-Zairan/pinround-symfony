<?php

declare(strict_types=1);

namespace App\Review\UI\Controller;

/**
 * Carries a refused verdict's toast and status out of Transactions::run, so the transaction rolls back and nothing of
 * the action is kept, as DraftRefusedWithToast.
 */
final class VerdictRefusedWithToast extends \RuntimeException
{
    /**
     * @param int $status 403 for a person VerdictPermission SET denies, 422 for any other refusal
     */
    public function __construct(
        private readonly string $toast,
        private readonly int $status,
    ) {
        parent::__construct('The verdict was refused.');
    }

    public function toast(): string
    {
        return $this->toast;
    }

    public function status(): int
    {
        return $this->status;
    }
}
