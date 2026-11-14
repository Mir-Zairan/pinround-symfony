<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Mail;

use App\Notification\Application\Digest\Digest;
use App\Notification\Application\Digest\DigestItem;
use App\Notification\Application\Notify\NotificationKind;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\BodyRendererInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

/**
 * The email digest, plain text and HTML, rendered at once so a queued message carries only what the email says: per
 * item the person, the kind, the ticket key, the title or "a restricted ticket in team …", and a link (FR-PNG-09).
 * The From address comes from mailer.yaml. Links are built on DEFAULT_URI, never on a request context, whose Host a
 * visitor controls.
 */
final readonly class DigestMail
{
    // Other modules' route names; DigestEmailLeakTest pins the links they give.
    private const string TICKET_ROUTE = 'ticket_show';
    private const string PROFILE_ROUTE = 'identity_profile';

    public function __construct(
        private BodyRendererInterface $bodyRenderer,
        private RouterInterface $router,
        #[Autowire(env: 'DEFAULT_URI')]
        private string $defaultUri,
    ) {
    }

    /**
     * @throws \LogicException when the digest is empty, as nothing waits to be told
     */
    public function emailFor(Digest $digest, Address $recipient): Email
    {
        if ($digest->isEmpty()) {
            throw new \LogicException('An empty digest is not sent.');
        }
        $requestContext = $this->router->getContext();
        $this->router->setContext(RequestContext::fromUri($this->defaultUri));
        try {
            $lines = array_map(fn (DigestItem $item): array => [
                'actorName' => $item->actorName,
                'action' => self::actionOf($item->kind),
                'ticketKey' => $item->ticketKey,
                'label' => self::labelOf($item),
                'link' => $this->router->generate(self::TICKET_ROUTE, ['key' => $item->ticketKey], UrlGeneratorInterface::ABSOLUTE_URL),
            ], $digest->items);
            $profileLink = $this->router->generate(self::PROFILE_ROUTE, [], UrlGeneratorInterface::ABSOLUTE_URL);
        } finally {
            $this->router->setContext($requestContext);
        }

        $email = new TemplatedEmail()
            ->to($recipient)
            ->subject(self::subjectFor($digest->itemCount))
            ->textTemplate('@Notification/email/digest.txt.twig')
            ->htmlTemplate('@Notification/email/digest.html.twig')
            ->context([
                'subject' => self::subjectFor($digest->itemCount),
                'lines' => $lines,
                'unlistedCount' => $digest->unlistedCount(),
                'profileLink' => $profileLink,
            ]);
        $this->bodyRenderer->render($email);

        return $email;
    }

    public static function subjectFor(int $itemCount): string
    {
        return 1 === $itemCount
            ? '1 thing waits on you in pinround'
            : \sprintf('%d things wait on you in pinround', $itemCount);
    }

    /**
     * @throws \LogicException for a kind other than ping or question, which no digest lists
     */
    public static function actionOf(NotificationKind $kind): string
    {
        return match ($kind) {
            NotificationKind::Ping => 'pinged you',
            NotificationKind::Question => 'asked you a question',
            default => throw new \LogicException(\sprintf('A digest has no words for the kind %s.', $kind->value)),
        };
    }

    /**
     * The ticket's title, or what an email may say instead when the ticket is restricted to its team.
     */
    public static function labelOf(DigestItem $item): string
    {
        return match (true) {
            null !== $item->ticketTitle => $item->ticketTitle,
            null !== $item->teamName => \sprintf('a restricted ticket in team %s', $item->teamName),
            default => 'a restricted ticket',
        };
    }
}
