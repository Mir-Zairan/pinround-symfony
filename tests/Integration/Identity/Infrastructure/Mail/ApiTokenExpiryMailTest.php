<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity\Infrastructure\Mail;

use App\Identity\Domain\ExpiringApiToken;
use App\Identity\Infrastructure\Mail\ApiTokenExpiryMail;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bridge\Twig\Mime\BodyRenderer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

/**
 * The expiry warning email rendered through the real Twig templates and router. Its DEFAULT_URI is not the test env's
 * (http://localhost), so a link built on the router's own context would show.
 */
final class ApiTokenExpiryMailTest extends KernelTestCase
{
    private const string DEFAULT_URI = 'https://pinround.example';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000e9b11';

    #[Test]
    public function aStoredNameOrPrefixWithLineBreaksOrInvisibleCharactersRendersAsOneLineWithSpaces(): void
    {
        $email = $this->emailFor([
            $this->token("\u{FEFF}Build\r\nserver\u{2028}one\u{200B}two", "pr_ab\u{1B}cdefgh", '2027-03-10 09:00:00 UTC'),
        ]);

        $text = self::textOf($email);
        self::assertStringContainsString("\n-  Build server one two (pr_ab cdefgh) works until 10 Mar 2027, 09:00 UTC\n", $text);
        foreach (["\r", "\u{2028}", "\u{200B}", "\u{FEFF}", "\u{1B}"] as $removed) {
            self::assertStringNotContainsString($removed, $text);
            self::assertStringNotContainsString($removed, self::htmlOf($email));
        }
    }

    #[Test]
    public function aMultibyteNameComesThroughUnchanged(): void
    {
        $email = $this->emailFor([$this->token('Café 日本', 'pr_abcdefgh', '2027-03-10 09:00:00 UTC')]);

        self::assertStringContainsString("\n- Café 日本 (pr_abcdefgh) works until 10 Mar 2027, 09:00 UTC\n", self::textOf($email));
        self::assertStringContainsString('<li>Café 日本 (pr_abcdefgh) works until 10 Mar 2027, 09:00 UTC</li>', self::htmlOf($email));
    }

    #[Test]
    public function theLinkIsOnDefaultUriWhateverHostTheRouterHasAndTheRoutersContextIsPutBack(): void
    {
        $router = $this->router();
        $visitorsContext = new RequestContext(host: 'attacker.example', scheme: 'https');
        $router->setContext($visitorsContext);

        $email = $this->emailFor([$this->token('Claude Code', 'pr_abcdefgh', '2027-03-10 09:00:00 UTC')]);

        self::assertMatchesRegularExpression('#^https://pinround\.example/me/tokens$#m', self::textOf($email));
        self::assertStringNotContainsString('attacker.example', self::textOf($email));
        self::assertStringNotContainsString('attacker.example', self::htmlOf($email));
        self::assertSame($visitorsContext, $router->getContext());
        self::assertSame('attacker.example', $router->getContext()->getHost());
    }

    #[Test]
    public function theHtmlListsTheTokensInTheGivenOrderEscapesTheirNamesAndLinksToTheTokensPage(): void
    {
        $email = $this->emailFor([
            $this->token('<b>Laptop</b>', 'pr_aaaaaaaa', '2027-03-10 09:00:00 UTC'),
            $this->token('Browser plugin', 'pr_bbbbbbbb', '2027-03-09 09:00:00 UTC'),
        ]);

        $html = self::htmlOf($email);
        self::assertSame(2, preg_match_all('#<li>(.*?)</li>#', $html, $items));
        self::assertSame(
            [
                '&lt;b&gt;Laptop&lt;/b&gt; (pr_aaaaaaaa) works until 10 Mar 2027, 09:00 UTC',
                'Browser plugin (pr_bbbbbbbb) works until 9 Mar 2027, 09:00 UTC',
            ],
            $items[1],
        );
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringContainsString('<a href="https://pinround.example/me/tokens">Your API tokens</a>', $html);
        self::assertStringContainsString('<title>2 of your pinround API tokens stop working soon</title>', $html);
    }

    #[Test]
    public function anEmailNamingNoTokenIsRefused(): void
    {
        $this->expectExceptionObject(new \LogicException('An expiry warning names at least one token.'));

        $this->emailFor([]);
    }

    /**
     * @param list<ExpiringApiToken> $tokens
     */
    private function emailFor(array $tokens): Email
    {
        $mail = new ApiTokenExpiryMail(new BodyRenderer(self::getContainer()->get('twig')), $this->router(), self::DEFAULT_URI);

        return $mail->emailFor($tokens, new Address('omar@acme-foods.example'), new \DateTimeZone('UTC'));
    }

    private function token(string $name, string $prefix, string $expiresAt): ExpiringApiToken
    {
        return new ExpiringApiToken('0197a3c0-0000-7000-8000-0000000e9b21', new UserId(self::OMAR), $name, $prefix, new \DateTimeImmutable($expiresAt));
    }

    private function router(): RouterInterface
    {
        return self::getContainer()->get('router');
    }

    private static function textOf(Email $email): string
    {
        $text = $email->getTextBody();
        self::assertIsString($text);

        return $text;
    }

    private static function htmlOf(Email $email): string
    {
        $html = $email->getHtmlBody();
        self::assertIsString($html);

        return $html;
    }
}
