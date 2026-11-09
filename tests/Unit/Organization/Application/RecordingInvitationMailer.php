<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Application;

use App\Organization\Application\InvitationMailer;
use App\Organization\Domain\Invitation;
use App\Organization\Domain\InvitationLinkToken;

/**
 * Keeps each link it is asked to send, with what the invitation and the invitations saved held at that moment.
 */
final class RecordingInvitationMailer implements InvitationMailer
{
    /** @var list<array{email: string, organizationName: string, hadLinkToken: bool, savedBefore: list<string>}> */
    public array $sentLinks = [];

    public function __construct(private readonly InMemoryInvitations $invitations)
    {
    }

    public function sendLink(Invitation $invitation, string $organizationName, InvitationLinkToken $token): void
    {
        $this->sentLinks[] = [
            'email' => $invitation->email(),
            'organizationName' => $organizationName,
            'hadLinkToken' => $invitation->hasLinkToken(),
            'savedBefore' => $this->invitations->calls,
        ];
    }
}
