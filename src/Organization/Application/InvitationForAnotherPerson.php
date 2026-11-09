<?php

declare(strict_types=1);

namespace App\Organization\Application;

/**
 * The logged-in person is not the account with the invited email, or nobody has that email yet. In the Application,
 * not the Domain, so the accept page may catch it.
 */
final class InvitationForAnotherPerson extends \DomainException
{
}
