<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * The tab rail between Profile, Security and API tokens (P14.T17 step 6): the same three tabs on each page, the page's own
 * one marked current, through the real firewall over HTTPS.
 */
final class AccountTabsTest extends WebTestCase
{
    use Factories;
    use LogsInUsers;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function accountPages(): iterable
    {
        yield 'Profile' => ['/me/profile', 'Profile'];
        yield 'Security' => ['/me/security', 'Security'];
        yield 'API tokens' => ['/me/tokens', 'API tokens'];
    }

    #[Test]
    #[DataProvider('accountPages')]
    public function eachAccountPageShowsTheThreeTabsWithItsOwnTabCurrent(string $url, string $currentLabel): void
    {
        $client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $this->logIn($client, $this->createUserWithPassword('Mia Lund'));

        $page = $client->request('GET', $url);

        self::assertResponseStatusCodeSame(200);
        $tabs = $page->filter('main nav[aria-label="Your account"] a');
        self::assertSame(
            [['Profile', '/me/profile'], ['Security', '/me/security'], ['API tokens', '/me/tokens']],
            $tabs->each(static fn (Crawler $tab): array => [$tab->text(), $tab->attr('href')]),
        );
        self::assertSame([$currentLabel], $tabs->filter('[aria-current="page"]')->each(static fn (Crawler $tab): string => $tab->text()));
    }
}
