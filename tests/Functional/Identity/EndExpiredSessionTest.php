<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Zenstruck\Foundry\Test\Factories;

/**
 * The session limits through the real firewall. The session's own timestamps come from PHP's time(), so the clock is
 * moved relative to now; the exact limits are pinned in the unit test of the same name.
 */
final class EndExpiredSessionTest extends WebTestCase
{
    use Factories;
    use LogsInUsers;
    use ReadsSessionMetadata;

    #[Test]
    public function aSessionIdleForMoreThanEightHoursLandsOnTheLoginPageAndStaysOut(): void
    {
        $client = $this->loggedInClientWithTheClockAt('+8 hours +1 minute');

        $client->request('GET', '/');

        self::assertResponseRedirects('/login', 302);
        $client->request('GET', '/');
        self::assertResponseRedirects('/login', 302);
    }

    #[Test]
    public function aSessionIdleForLessThanEightHoursStaysIn(): void
    {
        $client = $this->loggedInClientWithTheClockAt('+7 hours +59 minutes');

        $client->request('GET', '/');

        self::assertResponseStatusCodeSame(200);
    }

    #[Test]
    public function theLoginPageOfAnEndedSessionShowsTheForm(): void
    {
        $client = $this->loggedInClientWithTheClockAt('+31 days');

        $client->request('GET', '/login');

        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextSame('h1', 'Log in');
    }

    #[Test]
    public function aHealthProbeWithAnExpiredSessionsCookieLeavesTheSessionAlone(): void
    {
        $client = $this->loggedInClientWithTheClockAt('+31 days');

        $client->request('GET', '/health/live');
        self::assertResponseStatusCodeSame(200);

        $client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);
    }

    #[Test]
    public function theThirtyDaysCountFromTheLoginNotFromTheFirstVisitOfTheLoginPage(): void
    {
        $client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
        $user = $this->createUserWithPassword('Sara Holm');
        $client->request('GET', '/login');
        self::assertResponseStatusCodeSame(200);
        $firstVisit = time() - 29 * 86400;
        self::writeSessionCreatedTime($client, $firstVisit);
        self::assertSame($firstVisit, self::sessionMetadata($client)['c']);
        $beforeLogin = time();

        $client->submitForm('Log in', ['email' => $user->email()->toString(), 'password' => self::PASSWORD]);

        self::assertResponseRedirects('/', 302);
        self::assertGreaterThanOrEqual($beforeLogin, self::sessionMetadata($client)['c']);
    }

    /**
     * Freezes the clock before the first request; the client reboots the kernel after it, which drops the frozen clock.
     */
    private function loggedInClientWithTheClockAt(string $fromNow): KernelBrowser
    {
        $client = self::createClient([], ['HTTPS' => 'on']);
        $this->logIn($client, $this->createUserWithPassword('Raj Patel'));
        self::getContainer()->set('clock', new MockClock($fromNow));

        return $client;
    }
}
