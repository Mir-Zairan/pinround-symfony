<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use Symfony\Component\HttpFoundation\Response;

/**
 * Carries the form page with its field errors out of Transactions::run, so the transaction rolls back, including the
 * ticket number a refused create has already taken.
 */
final class TicketFormRefused extends \RuntimeException
{
    public function __construct(private readonly Response $formPage)
    {
        parent::__construct('The new ticket form was refused.');
    }

    public function formPage(): Response
    {
        return $this->formPage;
    }
}
