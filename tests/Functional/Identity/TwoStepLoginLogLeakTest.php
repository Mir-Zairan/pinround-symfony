<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Tests\Fixtures\Messaging\CapturedLogRecords;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * Turning two-step login on, with a wrong try first, logging in with codes, right and wrong, and "Confirm it's you"
 * and turning it off with passwords and codes, right and wrong, log neither the seed, a password, a typed code nor a
 * recovery code (security.md "Logging without leaking", ADR-0027). CapturedLogRecords keeps each record before
 * RedactionProcessor runs, so none of them may be in what the code logs at all.
 */
final class TwoStepLoginLogLeakTest extends WebTestCase
{
    use Factories;
    use LogsInUsers;
    use TurnsOnTwoStepLogin;

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
        $client->submitForm('Turn on', ['code' => self::codeOf($seed, $clock)]);
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

    #[Test]
    public function loggingInWithCodesLogsNoCodeRightOrWrong(): void
    {
        $client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $client->disableReboot();
        $clock = new MockClock();
        self::getContainer()->set('clock', $clock);
        $capturedLogRecords = self::getContainer()->get(CapturedLogRecords::class);
        self::assertInstanceOf(CapturedLogRecords::class, $capturedLogRecords);
        $user = $this->createUserWithPassword('Mia Lund');
        [$seed, $recoveryCodes] = $this->turnOnTwoStepLogin($client, $clock, $user);
        $clock->sleep(30);
        $capturedLogRecords->clear();
        $wrongCodeMarker = 'leak-marker-code-'.bin2hex(random_bytes(8));
        // In the shape of a recovery code, so it is checked against the hashes like a real one.
        $wrongRecoveryCode = substr(bin2hex(random_bytes(5)), 0, 5).'-'.substr(bin2hex(random_bytes(5)), 0, 5);

        $this->openCodePage($client, $user);
        foreach ([$wrongCodeMarker, $wrongRecoveryCode] as $wrongCode) {
            $client->submitForm('Log in', ['code' => $wrongCode]);
            self::assertResponseStatusCodeSame(422);
        }
        $client->submitForm('Log in', ['code' => self::codeOf($seed, $clock)]);
        self::assertResponseRedirects('/', 302);
        $client->getCookieJar()->clear();
        $this->openCodePage($client, $user);
        $client->submitForm('Log in', ['code' => $recoveryCodes[0]]);
        self::assertResponseRedirects('/', 302);

        $records = $capturedLogRecords->all();
        self::assertContains('Matched route "{route}".', array_map(static fn (LogRecord $record): string => $record->message, $records), 'the requests were not logged, so nothing was checked');
        $logged = implode("\n", array_map(LoginLogLeakTest::textOf(...), $records));
        // The right code from the app is 6 digits, which a request id or a timestamp can hold by chance; the wrong
        // marker stands in for it, as both reach the same request parameter.
        $secrets = ['wrong code' => $wrongCodeMarker, 'wrong recovery code' => $wrongRecoveryCode, 'used recovery code' => $recoveryCodes[0], 'seed' => $seed];
        foreach ($secrets as $what => $secret) {
            self::assertStringNotContainsString($secret, $logged, 'a log record holds the '.$what);
        }
    }

    #[Test]
    public function confirmingItsYouLogsNoPasswordAndNoCode(): void
    {
        $client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $client->disableReboot();
        $clock = new MockClock();
        self::getContainer()->set('clock', $clock);
        $capturedLogRecords = self::getContainer()->get(CapturedLogRecords::class);
        self::assertInstanceOf(CapturedLogRecords::class, $capturedLogRecords);
        $user = $this->createUserWithPassword('Raj Patel');
        [, $recoveryCodes] = $this->turnOnTwoStepLogin($client, $clock, $user);
        $this->logIn($client, $user);
        $capturedLogRecords->clear();
        $wrongPasswordMarker = 'leak-marker-password-'.bin2hex(random_bytes(8));
        $wrongCodeMarker = 'leak-marker-code-'.bin2hex(random_bytes(8));

        $client->request('GET', '/_test/recent-auth');
        self::assertResponseRedirects('/me/confirm?return=/_test/recent-auth', 302);
        $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        $client->submitForm('Confirm', ['password' => $wrongPasswordMarker, 'code' => $recoveryCodes[0]]);
        self::assertResponseStatusCodeSame(422);
        $client->submitForm('Confirm', ['password' => self::PASSWORD, 'code' => $wrongCodeMarker]);
        self::assertResponseStatusCodeSame(422);
        $client->submitForm('Confirm', ['password' => self::PASSWORD, 'code' => $recoveryCodes[1]]);
        self::assertResponseRedirects('/_test/recent-auth', 302);

        $records = $capturedLogRecords->all();
        self::assertContains('Matched route "{route}".', array_map(static fn (LogRecord $record): string => $record->message, $records), 'the requests were not logged, so nothing was checked');
        $logged = implode("\n", array_map(LoginLogLeakTest::textOf(...), $records));
        $secrets = [
            'wrong password' => $wrongPasswordMarker,
            'password' => self::PASSWORD,
            'wrong code' => $wrongCodeMarker,
            'recovery code typed with the wrong password' => $recoveryCodes[0],
            'used recovery code' => $recoveryCodes[1],
        ];
        foreach ($secrets as $what => $secret) {
            self::assertStringNotContainsString($secret, $logged, 'a log record holds the '.$what);
        }
    }

    #[Test]
    public function turningItOffLogsNoPasswordAndNoCode(): void
    {
        $client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $client->disableReboot();
        $clock = new MockClock();
        self::getContainer()->set('clock', $clock);
        $capturedLogRecords = self::getContainer()->get(CapturedLogRecords::class);
        self::assertInstanceOf(CapturedLogRecords::class, $capturedLogRecords);
        $user = $this->createUserWithPassword('Sara Holm');
        [, $recoveryCodes] = $this->turnOnTwoStepLogin($client, $clock, $user);
        $this->logIn($client, $user);
        $capturedLogRecords->clear();
        $wrongPasswordMarker = 'leak-marker-password-'.bin2hex(random_bytes(8));
        $wrongCodeMarker = 'leak-marker-code-'.bin2hex(random_bytes(8));

        $client->request('GET', '/me/security');
        self::assertResponseStatusCodeSame(200);
        $client->submitForm('Turn off two-step login', ['password' => $wrongPasswordMarker, 'code' => $recoveryCodes[0]]);
        self::assertResponseStatusCodeSame(422);
        $client->submitForm('Turn off two-step login', ['password' => self::PASSWORD, 'code' => $wrongCodeMarker]);
        self::assertResponseStatusCodeSame(422);
        $client->submitForm('Turn off two-step login', ['password' => self::PASSWORD, 'code' => $recoveryCodes[1]]);
        self::assertResponseRedirects('/me/security', 302);

        $records = $capturedLogRecords->all();
        self::assertContains('Matched route "{route}".', array_map(static fn (LogRecord $record): string => $record->message, $records), 'the requests were not logged, so nothing was checked');
        $logged = implode("\n", array_map(LoginLogLeakTest::textOf(...), $records));
        $secrets = [
            'wrong password' => $wrongPasswordMarker,
            'password' => self::PASSWORD,
            'wrong code' => $wrongCodeMarker,
            'recovery code typed with the wrong password' => $recoveryCodes[0],
            'used recovery code' => $recoveryCodes[1],
        ];
        foreach ($secrets as $what => $secret) {
            self::assertStringNotContainsString($secret, $logged, 'a log record holds the '.$what);
        }
    }
}
