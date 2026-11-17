<?php

declare(strict_types=1);

namespace App\Review\UI\Controller;

use Symfony\Component\HttpFoundation\Response;

/**
 * Carries a refused Review action's toast and status out of Transactions::run, so the transaction rolls back and nothing
 * of the action is kept, as Ticketing's MoveRefusedWithToast. RefusalAnswers answers it.
 */
final class RefusedWithToast extends \RuntimeException
{
    /** What a Review form whose token is not valid is told. */
    public const string FORM_EXPIRED = 'This page was open too long. Reload it and try again.';

    /**
     * @param int $status 403 for a person a permission denies, 422 for any other refusal
     */
    public function __construct(
        private readonly string $toast,
        private readonly int $status = Response::HTTP_UNPROCESSABLE_ENTITY,
    ) {
        parent::__construct('The action was refused.');
    }

    public static function formExpired(): self
    {
        return new self(self::FORM_EXPIRED);
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
