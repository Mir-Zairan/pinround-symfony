<?php

declare(strict_types=1);

namespace App\Organization\Application;

/**
 * The invitation was accepted, expired or replaced after its link was looked up, as when the link is used twice at
 * once. In the Application, not the Domain, so the accept page may catch it.
 */
final class InvitationNoLongerValid extends \DomainException
{
}
