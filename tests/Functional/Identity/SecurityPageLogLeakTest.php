<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Tests\Fixtures\Messaging\CapturedLogRecords;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * A wrong try and a successful password change on /me/security with markers for every password (security.md
 * "Logging without leaking", ADR-0027). CapturedLogRecords keeps each record before RedactionProcessor runs, so no
 * password may be in what the code logs at all.
 */
final class SecurityPageLogLeakTest extends WebTestCase
{
    use Factories;
    use LogsInUsers;

    #[Test]
    public function changingThePasswordLogsNoneOfThePasswords(): void
    {
        $client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $client->disableReboot();
        $capturedLogRecords = self::getContainer()->get(CapturedLogRecords::class);
        self::assertInstanceOf(CapturedLogRecords::class, $capturedLogRecords);
        $currentMarker = 'leak-marker-current-'.bin2hex(random_bytes(8));
        $wrongMarker = 'leak-marker-wrong-'.bin2hex(random_bytes(8));
        $newMarker = 'leak-marker-new-'.bin2hex(random_bytes(8));
        $this->logIn($client, $this->createUserWithPassword('Omar Haddad', $currentMarker));
        $capturedLogRecords->clear();

        $client->request('GET', '/me/security');
        self::assertResponseStatusCodeSame(200);
        $client->submitForm('Change password', ['current_password' => $wrongMarker, 'new_password' => $newMarker, 'new_password_repeat' => $newMarker]);
        self::assertResponseStatusCodeSame(422);
        $client->submitForm('Change password', ['current_password' => $currentMarker, 'new_password' => $newMarker, 'new_password_repeat' => $newMarker]);
        self::assertResponseRedirects('/me/security', 302);

        $records = $capturedLogRecords->all();
        self::assertContains('Matched route "{route}".', array_map(static fn (LogRecord $record): string => $record->message, $records), 'the requests were not logged, so nothing was checked');
        $logged = implode("\n", array_map(LoginLogLeakTest::textOf(...), $records));
        foreach (['current password' => $currentMarker, 'wrong password' => $wrongMarker, 'new password' => $newMarker] as $what => $secret) {
            self::assertStringNotContainsString($secret, $logged, 'a log record holds the '.$what);
        }
    }
}
