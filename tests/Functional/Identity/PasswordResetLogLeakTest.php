<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\SharedKernel\Infrastructure\Logging\RedactionProcessor;
use App\Tests\Fixtures\Messaging\CapturedLogRecords;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Test\Factories;

/**
 * A whole reset, from the request for a link through its handling in the worker to the new password (security.md
 * "Logging without leaking", ADR-0027).
 * CapturedLogRecords keeps each record before RedactionProcessor runs. The token is in the link's path, which
 * Symfony's RouterListener puts in its "Matched route" record; RedactionProcessor drops those keys, so no record
 * reaches a handler with the token, and no other record holds it at all.
 */
final class PasswordResetLogLeakTest extends WebTestCase
{
    use Factories;
    use LogsInUsers;

    /** The keys of RouterListener's record that hold the request path, which RedactionProcessor drops. */
    private const array ROUTER_PATH_KEYS = ['route_parameters', 'request_uri'];

    #[Test]
    public function aResetLogsNeitherTheTokenNorTheNewPassword(): void
    {
        $client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $client->disableReboot();
        $capturedLogRecords = self::getContainer()->get(CapturedLogRecords::class);
        self::assertInstanceOf(CapturedLogRecords::class, $capturedLogRecords);
        $user = $this->createUserWithPassword('Omar Haddad');
        $passwordMarker = 'leak-marker-'.bin2hex(random_bytes(8));
        $capturedLogRecords->clear();

        $client->request('GET', '/password/forgot');
        self::assertResponseStatusCodeSame(200);
        $client->submitForm('Send link', ['email' => $user->email()->toString()]);
        self::assertResponseRedirects('/password/forgot', 302);
        $transport = self::getContainer()->get('messenger.transport.async_notify');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        foreach ($transport->getSent() as $envelope) {
            self::getContainer()->get('command.bus')->dispatch($envelope->with(new ReceivedStamp('async_notify')));
        }
        $email = self::getMailerMessage();
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame(1, preg_match('~/password/reset/([A-Za-z0-9_-]{43})~', (string) $email->getTextBody(), $match));
        $token = $match[1];
        $client->request('GET', '/password/reset/'.$token);
        self::assertResponseRedirects('/password/reset', 302);
        $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        $client->submitForm('Save password', ['password' => $passwordMarker, 'password_repeat' => $passwordMarker]);
        self::assertResponseRedirects('/login', 302);

        $records = $capturedLogRecords->all();
        $redaction = new RedactionProcessor();
        $redacted = implode("\n", array_map(static fn (LogRecord $record): string => LoginLogLeakTest::textOf($redaction($record)), $records));
        $withoutRouterPaths = implode("\n", array_map(
            static fn (LogRecord $record): string => LoginLogLeakTest::textOf($record->with(context: array_diff_key($record->context, array_flip(self::ROUTER_PATH_KEYS)))),
            $records,
        ));
        self::assertStringContainsString($token, implode("\n", array_map(LoginLogLeakTest::textOf(...), $records)), 'the link request was not logged, so nothing was checked');
        foreach (['token' => $token, 'password' => $passwordMarker] as $what => $secret) {
            self::assertStringNotContainsString($secret, $redacted, 'a written log record holds the '.$what);
            self::assertStringNotContainsString($secret, $withoutRouterPaths, 'a log record outside the router\'s path keys holds the '.$what);
        }
    }
}
