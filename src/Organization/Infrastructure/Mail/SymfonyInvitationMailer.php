<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Mail;

use App\Organization\Application\InvitationMailer;
use App\Organization\Domain\Invitation;
use App\Organization\Domain\InvitationLinkToken;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;

/**
 * The invitation email, plain text and HTML, sent straight to the mailer transport: it runs in worker-notify already
 * (EmailInvitationLinkHandler), so it is not queued again. The From address comes from mailer.yaml. The link is built
 * on DEFAULT_URI, never on a request context, whose Host a visitor controls.
 */
final readonly class SymfonyInvitationMailer implements InvitationMailer
{
    // TODO(P01.T7): generate the link from the accept page's route once it exists, as SymfonyPasswordResetMailer does.
    private const string LINK_PATH = '/invite/';

    public function __construct(
        private TransportInterface $mailerTransport,
        #[Autowire(env: 'DEFAULT_URI')]
        private string $defaultUri,
    ) {
    }

    public function sendLink(Invitation $invitation, string $organizationName, InvitationLinkToken $token): void
    {
        $email = new TemplatedEmail()
            ->to(new Address($invitation->email()))
            ->subject(self::subjectFor($organizationName))
            ->textTemplate('@Organization/email/invitation.txt.twig')
            ->htmlTemplate('@Organization/email/invitation.html.twig')
            ->context([
                'organizationName' => $organizationName,
                'link' => rtrim($this->defaultUri, '/').self::LINK_PATH.$token->reveal(),
                'validHours' => Invitation::LIFETIME_HOURS,
            ]);

        $this->mailerTransport->send($email);
    }

    public static function subjectFor(string $organizationName): string
    {
        return \sprintf('You are invited to %s on pinround', $organizationName);
    }
}
