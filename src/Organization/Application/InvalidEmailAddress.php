<?php

declare(strict_types=1);

namespace App\Organization\Application;

/**
 * The value given to invite is blank or no email address. In the Application, not the Domain, so the People page may
 * catch it.
 */
final class InvalidEmailAddress extends \DomainException
{
}
