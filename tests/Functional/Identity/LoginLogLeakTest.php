<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Tests\Fixtures\Messaging\CapturedLogRecords;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A failed login whose password is a marker (security.md "Logging without leaking"). CapturedLogRecords keeps each
 * record before RedactionProcessor runs, so the password, the CSRF token and the session id must be missing from
 * what the code logs at all, not only from what the handlers write.
 */
final class LoginLogLeakTest extends WebTestCase
{
    #[Test]
    public function aFailedLoginLogsNeitherThePasswordNorTheCsrfTokenNorTheSessionId(): void
    {
        $client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $client->disableReboot();
        $capturedLogRecords = self::getContainer()->get(CapturedLogRecords::class);
        self::assertInstanceOf(CapturedLogRecords::class, $capturedLogRecords);
        $loginPage = $client->request('GET', '/login');
        self::assertResponseStatusCodeSame(200);
        $csrfToken = (string) $loginPage->filter('input[name="_csrf_token"]')->attr('value');
        $sessionId = (string) $client->getCookieJar()->get('__Host-pinround', '/', 'localhost')?->getValue();
        $passwordMarker = 'leak-marker-'.bin2hex(random_bytes(8));
        $capturedLogRecords->clear();

        $client->submitForm('Log in', ['email' => 'nobody.'.bin2hex(random_bytes(4)).'@acme-foods.example', 'password' => $passwordMarker]);

        self::assertResponseRedirects('/login', 302);
        $records = $capturedLogRecords->all();
        self::assertContains('Authenticator failed.', array_map(static fn (LogRecord $record): string => $record->message, $records), 'the failed login was not logged, so nothing was checked');
        self::assertGreaterThan(20, \strlen($csrfToken));
        self::assertGreaterThan(20, \strlen($sessionId));
        $logged = implode("\n", array_map(self::textOf(...), $records));
        foreach (['password' => $passwordMarker, 'CSRF token' => $csrfToken, 'session id' => $sessionId] as $what => $secret) {
            self::assertStringNotContainsString($secret, $logged, 'a log record holds the '.$what);
        }
    }

    /**
     * Every string a record holds: its message, and the strings, stringables and exception messages in its context
     * and extra, at any depth. PasswordResetLogLeakTest reads records the same way.
     */
    public static function textOf(LogRecord $record): string
    {
        $strings = [$record->message];
        $fields = [$record->context, $record->extra];
        array_walk_recursive($fields, static function (mixed $value) use (&$strings): void {
            for ($throwable = $value instanceof \Throwable ? $value : null; null !== $throwable; $throwable = $throwable->getPrevious()) {
                $strings[] = $throwable->getMessage();
            }
            if (\is_string($value) || $value instanceof \Stringable) {
                $strings[] = (string) $value;
            } elseif (\is_object($value)) {
                // print_r shows private properties too and stops at a recursion.
                $strings[] = print_r($value, true);
            }
        });

        return implode("\n", $strings);
    }
}
