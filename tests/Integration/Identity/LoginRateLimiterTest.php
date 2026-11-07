<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use App\Identity\Infrastructure\Security\ConsumeFirstLoginRateLimiter;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * The firewall's login limiter on the tests' state Valkey. Each test uses its own random IP and email, as the counters
 * outlive the run.
 */
final class LoginRateLimiterTest extends KernelTestCase
{
    #[Test]
    public function theAppsLimiterRefusesTheSixthTryForOneEmailAndIp(): void
    {
        $limiter = self::getContainer()->get(ConsumeFirstLoginRateLimiter::class);
        $email = 'omar.'.bin2hex(random_bytes(4)).'@acme-foods.example';
        $ip = self::randomIp();
        for ($try = 1; $try <= 5; ++$try) {
            self::assertTrue($limiter->consume(self::loginRequest($email, $ip))->isAccepted());
        }

        self::assertFalse($limiter->consume(self::loginRequest(strtoupper($email), $ip))->isAccepted());
    }

    #[Test]
    public function theAppsLimiterGivesASuccessfulLoginsIpTryBack(): void
    {
        $limiter = self::getContainer()->get(ConsumeFirstLoginRateLimiter::class);
        $ip = self::randomIp();
        for ($try = 1; $try <= 49; ++$try) {
            self::assertTrue($limiter->consume(self::loginRequest('person'.$try.'@acme-foods.example', $ip))->isAccepted());
        }
        $login = self::loginRequest('omar.'.bin2hex(random_bytes(4)).'@acme-foods.example', $ip);
        self::assertTrue($limiter->consume($login)->isAccepted());

        $limiter->reset($login);

        self::assertTrue($limiter->consume(self::loginRequest('mia@acme-foods.example', $ip))->isAccepted(), 'the 50th wrong try');
        self::assertFalse($limiter->consume(self::loginRequest('raj@acme-foods.example', $ip))->isAccepted(), 'the 51st wrong try');
    }

    /**
     * Ten PHP processes, like ten app workers, send a wrong password for one email and IP at the same moment, through
     * the real firewall. Only five may get their password checked: a peekable limiter would check every try that starts
     * before a failure is stored, and an unlocked counter would lose some of the tries.
     */
    #[Test]
    public function aBurstOfTenTriesGetsAtMostFivePasswordChecks(): void
    {
        $email = 'lena.'.bin2hex(random_bytes(4)).'@acme-foods.example';
        $ip = self::randomIp();
        $checkCounterKey = 'test:login_burst_checks:'.bin2hex(random_bytes(8));
        $tries = [];
        for ($worker = 1; $worker <= 10; ++$worker) {
            $starter = new InputStream();
            $process = new Process([\PHP_BINARY, __DIR__.'/try-login.php', $email, $ip, '10', $checkCounterKey]);
            $process->setInput($starter);
            $process->start();
            $tries[] = [$process, $starter];
        }

        foreach ($tries as [$process]) {
            $process->waitUntil(static fn (): bool => str_contains($process->getOutput(), "ready\n"));
        }
        foreach ($tries as [, $starter]) {
            $starter->write("go\n");
            $starter->close();
        }
        // A process writes its input only while it is polled, so all are polled until each has read its start line.
        do {
            $startedCount = \count(array_filter($tries, static fn (array $try): bool => !$try[0]->isRunning() || str_contains($try[0]->getOutput(), "started\n")));
        } while ($startedCount < \count($tries));
        $refusals = [];
        foreach ($tries as [$process]) {
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $refusals[] = substr($process->getOutput(), \strlen("ready\nstarted\n"));
        }

        $counts = array_count_values($refusals);
        ksort($counts);
        self::assertSame(['BadCredentialsException' => 5, 'TooManyLoginAttemptsAuthenticationException' => 5], $counts);
        $stateValkey = self::getContainer()->get('state_valkey.connection');
        self::assertSame('5', $stateValkey->get($checkCounterKey), 'password checks');
    }

    private static function loginRequest(string $email, string $ip): Request
    {
        $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => $ip]);
        $request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        return $request;
    }

    private static function randomIp(): string
    {
        return \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));
    }
}
