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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

/**
 * The invitation email, plain text and HTML, sent straight to the mailer transport: it runs in worker-notify already
 * (EmailInvitationLinkHandler, for a queued EmailInvitationLink), so it is not queued again. The From address comes from mailer.yaml. The link is built
 * on DEFAULT_URI, never on a request context, whose Host a visitor controls.
 */
final readonly class SymfonyInvitationMailer implements InvitationMailer
{
    public function __construct(
        private TransportInterface $mailerTransport,
        private RouterInterface $router,
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
                'link' => $this->linkFor($token),
                'validHours' => Invitation::LIFETIME_HOURS,
            ]);

        $this->mailerTransport->send($email);
    }

    private function linkFor(InvitationLinkToken $token): string
    {
        $requestContext = $this->router->getContext();
        $this->router->setContext(RequestContext::fromUri($this->defaultUri));
        try {
            return $this->router->generate('organization_invitation_token', ['token' => $token->reveal()], UrlGeneratorInterface::ABSOLUTE_URL);
        } finally {
            $this->router->setContext($requestContext);
        }
    }

    public static function subjectFor(string $organizationName): string
    {
        return \sprintf('You are invited to %s on pinround', $organizationName);
    }
}
