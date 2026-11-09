<?php

declare(strict_types=1);

namespace App\Organization\Application;

/**
 * The invited person joined the organization another way after the invitation was sent. In the Application, not the
 * Domain, so the accept page may catch it.
 */
final class AlreadyJoined extends \DomainException
{
}
