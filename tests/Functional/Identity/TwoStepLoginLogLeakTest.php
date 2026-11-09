<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Tests\Fixtures\Messaging\CapturedLogRecords;
use Monolog\LogRecord;
use OTPHP\TOTP;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * Turning two-step login on, with a wrong try first, logs neither the seed, the typed code nor a recovery code
 * (security.md "Logging without leaking", ADR-0027). CapturedLogRecords keeps each record before RedactionProcessor
 * runs, so none of them may be in what the code logs at all.
 */
final class TwoStepLoginLogLeakTest extends WebTestCase
{
    use Factories;
    use LogsInUsers;

    #[Test]
    public function turningItOnLogsNoSeedNoCodeAndNoRecoveryCode(): void
    {
        $client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $client->disableReboot();
        $clock = new MockClock();
        self::getContainer()->set('clock', $clock);
        $capturedLogRecords = self::getContainer()->get(CapturedLogRecords::class);
        self::assertInstanceOf(CapturedLogRecords::class, $capturedLogRecords);
        $this->logIn($client, $this->createUserWithPassword('Omar Haddad'));
        $capturedLogRecords->clear();
        $wrongCodeMarker = 'leak-marker-code-'.bin2hex(random_bytes(8));

        $client->request('GET', '/me/security/two-step');
        self::assertResponseStatusCodeSame(200);
        $client->submitForm('Set up two-step login');
        self::assertResponseRedirects('/me/security/two-step', 302);
        $page = $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        $seed = str_replace(' ', '', $page->filter('main .two-step__key')->text());
        $client->submitForm('Turn on', ['code' => $wrongCodeMarker]);
        self::assertResponseStatusCodeSame(422);
        $now = $clock->now()->getTimestamp();
        \assert('' !== $seed && $now >= 0);
        $client->submitForm('Turn on', ['code' => TOTP::createFromSecret($seed, $clock)->at($now)]);
        self::assertResponseStatusCodeSame(200);
        $recoveryCodes = $client->getCrawler()->filter('main .two-step__codes li')->each(static fn (Crawler $item): string => $item->text());
        self::assertCount(10, $recoveryCodes);

        $records = $capturedLogRecords->all();
        self::assertContains('Matched route "{route}".', array_map(static fn (LogRecord $record): string => $record->message, $records), 'the requests were not logged, so nothing was checked');
        $logged = implode("\n", array_map(LoginLogLeakTest::textOf(...), $records));
        // The right code is 6 digits, which a request id or a timestamp can hold by chance; the wrong one stands in
        // for it, as both reach the same request parameter.
        $secrets = ['seed' => $seed, 'typed code' => $wrongCodeMarker];
        foreach ($recoveryCodes as $position => $recoveryCode) {
            $secrets['recovery code '.($position + 1)] = $recoveryCode;
        }
        foreach ($secrets as $what => $secret) {
            self::assertStringNotContainsString($secret, $logged, 'a log record holds the '.$what);
        }
    }
}
