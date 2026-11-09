<?php

declare(strict_types=1);

namespace App\Organization\Application;

/**
 * Another invitation of the same email was saved at the same time, as by a double click. In the Application, not the
 * Domain, so the People page may catch it.
 */
final class InvitationSentMeanwhile extends \DomainException
{
}
