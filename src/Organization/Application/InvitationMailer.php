<?php

declare(strict_types=1);

namespace App\Organization\Application;

use App\Organization\Domain\Invitation;
use App\Organization\Domain\InvitationLinkToken;

interface InvitationMailer
{
    /**
     * Sends the email with the link to /invite/{token} to the invited address at once; the token is its only secret.
     *
     * @throws \RuntimeException when the mail server refuses or cannot be reached
     */
    public function sendLink(Invitation $invitation, string $organizationName, InvitationLinkToken $token): void;
}
