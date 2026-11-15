<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Mail;

use App\Notification\Application\Digest\Digest;
use App\Notification\Application\Digest\DigestItem;
use App\Notification\Application\Notify\NotificationKind;
use App\Notification\Infrastructure\Mail\DigestMail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Mime\BodyRenderer;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Loader\ClosureLoader;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Router;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * DigestMail with the real email templates, on a router with the app's two link routes whose request context is a
 * visitor's evil.example, while DEFAULT_URI is http://localhost.
 */
final class DigestMailTest extends TestCase
{
    private const string DEFAULT_URI = 'http://localhost';
    private const string COVERED_UNTIL = '2027-03-20 09:00:00+00:00';

    #[Test]
    public function oneItemIsOneThingThatWaits(): void
    {
        self::assertSame('1 thing waits on you in pinround', DigestMail::subjectFor(1));
    }

    #[Test]
    public function threeItemsAreThreeThingsThatWait(): void
    {
        self::assertSame('3 things wait on you in pinround', DigestMail::subjectFor(3));
    }

    #[Test]
    public function aPingAndAQuestionHaveTheirOwnWords(): void
    {
        self::assertSame('pinged you', DigestMail::actionOf(NotificationKind::Ping));
        self::assertSame('asked you a question', DigestMail::actionOf(NotificationKind::Question));
    }

    #[Test]
    public function aKindNoDigestListsHasNoWords(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('A digest has no words for the kind answer.');

        DigestMail::actionOf(NotificationKind::Answer);
    }

    #[Test]
    public function aTicketForEveryoneIsNamedByItsTitle(): void
    {
        self::assertSame('Checkout button is hidden', DigestMail::labelOf(self::itemOf('Checkout button is hidden', 'Shop')));
    }

    #[Test]
    public function aRestrictedTicketIsNamedByItsTeamOnly(): void
    {
        self::assertSame('a restricted ticket in team Shop', DigestMail::labelOf(self::itemOf(null, 'Shop')));
    }

    #[Test]
    public function aRestrictedTicketWithNoTeamIsNamedByNothingElse(): void
    {
        self::assertSame('a restricted ticket', DigestMail::labelOf(self::itemOf(null, null)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function lineBreakers(): iterable
    {
        yield 'CRLF' => ["\r\n"];
        yield 'LF' => ["\n"];
        yield 'CR' => ["\r"];
        yield 'next line' => ["\u{0085}"];
        yield 'line separator' => ["\u{2028}"];
        yield 'paragraph separator' => ["\u{2029}"];
        yield 'escape' => ["\e"];
        yield 'tab' => ["\t"];
        yield 'byte order mark' => ["\u{FEFF}"];
    }

    #[Test]
    #[DataProvider('lineBreakers')]
    public function aTitleOrTeamNameIsPutOnOneLine(string $lineBreaker): void
    {
        self::assertSame('Café ✓ hidden', DigestMail::labelOf(self::itemOf('Café ✓'.$lineBreaker.'hidden', 'Shop')));
        self::assertSame('a restricted ticket in team Shop ✓ web', DigestMail::labelOf(self::itemOf(null, 'Shop ✓'.$lineBreaker.'web')));
    }

    #[Test]
    public function aTitleThatIsNotUtf8IsRefused(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessageIsOrContains('Stored text for a digest email is not valid UTF-8.');

        DigestMail::labelOf(self::itemOf("Checkout \xC3\x28 hidden", null));
    }

    /**
     * Each stored text tries to start its own line in the text email: a fake item, a phishing link, a second key.
     */
    #[Test]
    public function storedTextCannotForgeAnItemOrALinkLineInTheTextEmail(): void
    {
        $digest = new Digest([
            new DigestItem('Le'."\n".'na', NotificationKind::Ping, 'SHOP-12', "Checkout button is hidden\r\n- Raj pinged you on SHOP-1: Reset your password\r\n  https://evil.example/login", null, new \DateTimeImmutable('2027-03-20 08:30:00+00:00')),
            new DigestItem('Mia', NotificationKind::Question, "SHOP-13\u{2028}- Raj", null, "Shop\u{2029}https://evil.example/team", new \DateTimeImmutable('2027-03-20 08:00:00+00:00')),
        ], 2, new \DateTimeImmutable(self::COVERED_UNTIL));

        $email = $this->digestMailOn(self::routerWithBothRoutes())->emailFor($digest, new Address('omar@acme-foods.example'));

        $textLines = explode("\n", self::textOf($email));
        self::assertSame([
            '- Le na pinged you on SHOP-12: Checkout button is hidden - Raj pinged you on SHOP-1: Reset your password   https://evil.example/login',
            '- Mia asked you a question on SHOP-13 - Raj: a restricted ticket in team Shop https://evil.example/team',
        ], array_values(array_filter($textLines, static fn (string $line): bool => str_starts_with($line, '- '))));
        self::assertSame([
            '  http://localhost/t/SHOP-12',
            '  http://localhost/t/SHOP-13%E2%80%A8-%20Raj',
        ], array_values(array_filter($textLines, static fn (string $line): bool => 1 === preg_match('~^\s*https?://~', $line))));
        self::assertStringNotContainsString("\r", self::textOf($email));
        self::assertStringNotContainsString("\u{2028}", self::textOf($email).self::htmlOf($email));
        self::assertStringNotContainsString("\u{2029}", self::textOf($email).self::htmlOf($email));
        self::assertStringContainsString('Le na pinged you on', self::htmlOf($email));
    }

    #[Test]
    public function linksAreBuiltOnTheDefaultUriAndTheRequestContextIsPutBack(): void
    {
        $router = self::routerWithBothRoutes();
        $visitorsContext = RequestContext::fromUri('http://evil.example');
        $router->setContext($visitorsContext);

        $email = $this->digestMailOn($router)->emailFor(self::digestOf(1, 1), new Address('omar@acme-foods.example'));

        self::assertSame($visitorsContext, $router->getContext());
        self::assertSame('evil.example', $router->getContext()->getHost());
        foreach (['text' => self::textOf($email), 'html' => self::htmlOf($email)] as $part => $body) {
            preg_match_all('~https?://[^\s"<]+~', $body, $matches);
            self::assertSame([self::DEFAULT_URI.'/t/SHOP-12', self::DEFAULT_URI.'/me/profile'], $matches[0], $part);
            self::assertStringNotContainsString('evil.example', $body, $part);
        }
    }

    /**
     * The profile route is missing, so generate throws after the ticket links are built.
     */
    #[Test]
    public function theRequestContextIsPutBackWhenALinkCannotBeBuilt(): void
    {
        $routes = new RouteCollection();
        $routes->add('ticket_show', new Route('/t/{key}'));
        $router = new Router(new ClosureLoader(), static fn (): RouteCollection => $routes);
        $visitorsContext = RequestContext::fromUri('http://evil.example');
        $router->setContext($visitorsContext);

        try {
            $this->digestMailOn($router)->emailFor(self::digestOf(1, 1), new Address('omar@acme-foods.example'));
            self::fail('A digest whose profile link cannot be built was rendered.');
        } catch (RouteNotFoundException) {
        }

        self::assertSame($visitorsContext, $router->getContext());
    }

    #[Test]
    public function anEmptyDigestIsNotSent(): void
    {
        $router = self::routerWithBothRoutes();
        $visitorsContext = RequestContext::fromUri('http://evil.example');
        $router->setContext($visitorsContext);

        try {
            $this->digestMailOn($router)->emailFor(new Digest([], 0, new \DateTimeImmutable(self::COVERED_UNTIL)), new Address('omar@acme-foods.example'));
            self::fail('An empty digest was rendered.');
        } catch (\LogicException $refusal) {
            self::assertSame('An empty digest is not sent.', $refusal->getMessage());
        }

        self::assertSame($visitorsContext, $router->getContext());
    }

    #[Test]
    public function itemsCountedButNotListedAreNamedAsMore(): void
    {
        $email = $this->digestMailOn(self::routerWithBothRoutes())->emailFor(self::digestOf(1, 4), new Address('omar@acme-foods.example'));

        self::assertSame('4 things wait on you in pinround', $email->getSubject());
        self::assertStringContainsString("\nAnd 3 more in pinround.\n", self::textOf($email));
        self::assertStringContainsString('<p>And 3 more in pinround.</p>', self::htmlOf($email));
    }

    #[Test]
    public function aDigestThatListsEveryItemNamesNoMore(): void
    {
        $email = $this->digestMailOn(self::routerWithBothRoutes())->emailFor(self::digestOf(1, 1), new Address('omar@acme-foods.example'));

        self::assertStringNotContainsString('more in pinround', self::textOf($email));
        self::assertStringNotContainsString('more in pinround', self::htmlOf($email));
    }

    private function digestMailOn(Router $router): DigestMail
    {
        $templates = new FilesystemLoader();
        $templates->addPath(\dirname(__DIR__, 5).'/src/Notification/UI/templates', 'Notification');

        // TwigBundle escapes by file name, so the text template is not HTML-escaped; the test env is strict.
        return new DigestMail(new BodyRenderer(new Environment($templates, ['autoescape' => 'name', 'strict_variables' => true])), $router, self::DEFAULT_URI);
    }

    private static function routerWithBothRoutes(): Router
    {
        $routes = new RouteCollection();
        $routes->add('ticket_show', new Route('/t/{key}'));
        $routes->add('identity_profile', new Route('/me/profile'));

        return new Router(new ClosureLoader(), static fn (): RouteCollection => $routes);
    }

    private static function digestOf(int $listedCount, int $itemCount): Digest
    {
        return new Digest(
            array_fill(0, $listedCount, self::itemOf('Checkout button is hidden', null)),
            $itemCount,
            new \DateTimeImmutable(self::COVERED_UNTIL),
        );
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

    private static function itemOf(?string $ticketTitle, ?string $teamName): DigestItem
    {
        return new DigestItem('Raj', NotificationKind::Ping, 'SHOP-12', $ticketTitle, $teamName, new \DateTimeImmutable('2027-03-20 08:00:00+00:00'));
    }
}
