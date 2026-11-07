<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Mail;

use App\Identity\Application\PasswordResetMailer;
use App\Identity\Domain\PasswordReset;
use App\Identity\Domain\PasswordResetToken;
use App\Identity\Domain\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

/**
 * The reset email, plain text and HTML. The mailer queues it on async_notify (messenger.yaml), where worker-notify
 * renders and sends it; the From address comes from mailer.yaml. The link is built on DEFAULT_URI, never on the
 * request's Host header, which the person asking for the link controls.
 */
final readonly class SymfonyPasswordResetMailer implements PasswordResetMailer
{
    public const string SUBJECT = 'Reset your pinround password';

    public function __construct(
        private MailerInterface $mailer,
        private RouterInterface $router,
        #[Autowire(env: 'DEFAULT_URI')]
        private string $defaultUri,
    ) {
    }

    public function sendLink(User $user, PasswordResetToken $token): void
    {
        $email = new TemplatedEmail()
            ->to(new Address($user->email()->toString(), $user->name()))
            ->subject(self::SUBJECT)
            ->textTemplate('@Identity/email/password_reset.txt.twig')
            ->htmlTemplate('@Identity/email/password_reset.html.twig')
            ->context([
                'name' => $user->name(),
                'link' => $this->linkFor($token),
                'validMinutes' => PasswordReset::LIFETIME_MINUTES,
            ]);

        $this->mailer->send($email);
    }

    private function linkFor(PasswordResetToken $token): string
    {
        $requestContext = $this->router->getContext();
        $this->router->setContext(RequestContext::fromUri($this->defaultUri));
        try {
            return $this->router->generate('identity_password_reset_token', ['token' => $token->reveal()], UrlGeneratorInterface::ABSOLUTE_URL);
        } finally {
            $this->router->setContext($requestContext);
        }
    }
}
