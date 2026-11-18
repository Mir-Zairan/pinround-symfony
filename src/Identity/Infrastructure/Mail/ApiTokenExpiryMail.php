<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Mail;

use App\Identity\Domain\ExpiringApiToken;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\BodyRendererInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

/**
 * The email that tells an owner their API tokens stop working in about a week, plain text and HTML, rendered at once
 * so a queued message carries only what the email says: per token its name, prefix and expiry in the owner's time
 * zone, and a link to their Tokens page. Never the token itself, which is not kept. The From address comes from
 * mailer.yaml; the link is built on DEFAULT_URI, never on a request context, whose Host a visitor controls.
 */
final readonly class ApiTokenExpiryMail
{
    private const string TOKENS_ROUTE = 'identity_tokens';

    // With the hour and the zone: the token stops at that instant, and a mail client does not tell which zone is meant.
    private const string WORKS_UNTIL_FORMAT = 'j M Y, H:i T';

    public function __construct(
        private BodyRendererInterface $bodyRenderer,
        private RouterInterface $router,
        #[Autowire(env: 'DEFAULT_URI')]
        private string $defaultUri,
    ) {
    }

    /**
     * @param list<ExpiringApiToken> $tokens the owner's, in the order to list them
     *
     * @throws \LogicException           when $tokens is empty, as nothing is to be told
     * @throws \UnexpectedValueException when a name is not valid UTF-8
     */
    public function emailFor(array $tokens, Address $owner, \DateTimeZone $ownerTimeZone): Email
    {
        if ([] === $tokens) {
            throw new \LogicException('An expiry warning names at least one token.');
        }
        $requestContext = $this->router->getContext();
        $this->router->setContext(RequestContext::fromUri($this->defaultUri));
        try {
            $tokensLink = $this->router->generate(self::TOKENS_ROUTE, [], UrlGeneratorInterface::ABSOLUTE_URL);
        } finally {
            $this->router->setContext($requestContext);
        }

        $email = new TemplatedEmail()
            ->to($owner)
            ->subject(self::subjectFor(\count($tokens)))
            ->textTemplate('@Identity/email/api_token_expiry.txt.twig')
            ->htmlTemplate('@Identity/email/api_token_expiry.html.twig')
            ->context([
                'subject' => self::subjectFor(\count($tokens)),
                'lines' => array_map(static fn (ExpiringApiToken $token): array => [
                    'name' => self::singleLineOf($token->name),
                    'prefix' => self::singleLineOf($token->prefix),
                    'worksUntil' => $token->expiresAt->setTimezone($ownerTimeZone)->format(self::WORKS_UNTIL_FORMAT),
                ], $tokens),
                'tokensLink' => $tokensLink,
            ]);
        $this->bodyRenderer->render($email);

        return $email;
    }

    public static function subjectFor(int $tokenCount): string
    {
        return 1 === $tokenCount
            ? 'Your pinround API token stops working soon'
            : \sprintf('%d of your pinround API tokens stop working soon', $tokenCount);
    }

    /**
     * ApiToken::issue() refuses a name with a line break; this keeps a row written some other way from starting a line
     * of its own, such as a forged link, in the plain-text email.
     */
    private static function singleLineOf(string $storedText): string
    {
        return preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]+/u', ' ', $storedText)
            ?? throw new \UnexpectedValueException('Stored text for an expiry warning is not valid UTF-8.');
    }
}
