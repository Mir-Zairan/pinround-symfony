<?php

declare(strict_types=1);

namespace App\Workflow\Application;

/**
 * Open tickets are still in the step; they must be moved before it is removed.
 */
final class StepHasOpenTickets extends \DomainException
{
    public function __construct(public readonly int $openTickets)
    {
        parent::__construct(1 === $openTickets
            ? '1 open ticket is still in this step. Move it first.'
            : \sprintf('%d open tickets are still in this step. Move them first.', $openTickets));
    }
}
