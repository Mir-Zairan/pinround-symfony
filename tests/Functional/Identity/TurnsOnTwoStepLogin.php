<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Domain\User;
use OTPHP\TOTP;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Two-step login codes for functional tests, computed for the clock the requests read. Use it next to LogsInUsers.
 */
trait TurnsOnTwoStepLogin
{
    /**
     * Turns two-step login on through the setup pages, logged in without the form, then forgets the client's cookies,
     * so it is logged out. The confirmation spends the code of the clock's current step: move the clock on by 30
     * seconds before a login with a code from the app.
     *
     * @return array{string, list<string>} the seed and the 10 recovery codes
     */
    private function turnOnTwoStepLogin(KernelBrowser $client, MockClock $clock, User $user): array
    {
        $this->logIn($client, $user);
        $client->request('GET', '/me/security/two-step');
        self::assertResponseStatusCodeSame(200);
        $client->submitForm('Set up two-step login');
        self::assertResponseRedirects('/me/security/two-step', 302);
        $page = $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        $seed = str_replace(' ', '', $page->filter('main .two-step__key')->text());
        $client->submitForm('Turn on', ['code' => self::codeOf($seed, $clock)]);
        self::assertResponseStatusCodeSame(200);
        $recoveryCodes = $client->getCrawler()->filter('main .two-step__codes li')->each(static fn (Crawler $item): string => $item->text());
        self::assertCount(10, $recoveryCodes);
        $client->getCookieJar()->clear();

        return [$seed, $recoveryCodes];
    }

    private static function codeOf(string $seed, MockClock $clock): string
    {
        $now = $clock->now()->getTimestamp();
        \assert('' !== $seed && $now >= 0);

        return TOTP::createFromSecret($seed, $clock)->at($now);
    }

    /**
     * Six digits that are the code of none of the three steps the server accepts now.
     */
    private static function wrongCodeFor(string $seed, MockClock $clock): string
    {
        $now = $clock->now()->getTimestamp();
        \assert('' !== $seed && $now >= 30);
        $totp = TOTP::createFromSecret($seed, $clock);
        $accepted = [$totp->at($now - 30), $totp->at($now), $totp->at($now + 30)];
        $candidate = (int) $accepted[1];
        do {
            $candidate = ($candidate + 1) % 1_000_000;
            $code = str_pad((string) $candidate, 6, '0', \STR_PAD_LEFT);
        } while (\in_array($code, $accepted, true));

        return $code;
    }
}
