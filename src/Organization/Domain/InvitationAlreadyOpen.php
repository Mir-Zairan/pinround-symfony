<?php

declare(strict_types=1);

namespace App\Organization\Domain;

/**
 * Another invitation of the same email, not yet accepted, was saved first, as when two invites are sent at once.
 */
final class InvitationAlreadyOpen extends \DomainException
{
}
