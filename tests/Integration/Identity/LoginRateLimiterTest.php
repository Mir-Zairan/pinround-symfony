<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RateLimiter\PeekableRequestRateLimiterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Process;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * The firewall's limiter (identity.login_rate_limiter) on the tests' state Valkey, as the firewall uses it: a peek
 * before each try, a consume after each failure. Each test uses its own random IP, as the counters outlive the run.
 */
final class LoginRateLimiterTest extends KernelTestCase
{
    #[Test]
    public function fiftyFailuresFromOneIpWithFiftyEmailsRefuseTheNextEmail(): void
    {
        $limiter = self::limiter();
        $ip = self::randomIp();
        for ($try = 1; $try <= 50; ++$try) {
            $request = self::loginRequest('person'.$try.'@acme-foods.example', $ip);
            self::assertTrue($limiter->peek($request)->isAccepted(), 'try '.$try);
            $limiter->consume($request);
        }

        self::assertSame(0, $limiter->peek(self::loginRequest('fresh@acme-foods.example', $ip))->getRemainingTokens());
        self::assertGreaterThan(0, $limiter->peek(self::loginRequest('fresh@acme-foods.example', self::randomIp()))->getRemainingTokens());
    }

    #[Test]
    public function fiveFailuresForOneEmailAndIpRefuseThatPairOnly(): void
    {
        $limiter = self::limiter();
        $ip = self::randomIp();
        for ($try = 1; $try <= 5; ++$try) {
            $limiter->consume(self::loginRequest('omar@acme-foods.example', $ip));
        }

        self::assertSame(0, $limiter->peek(self::loginRequest('OMAR@acme-foods.example', $ip))->getRemainingTokens());
        self::assertGreaterThan(0, $limiter->peek(self::loginRequest('mia@acme-foods.example', $ip))->getRemainingTokens());
    }

    /**
     * Ten PHP processes, like ten app workers, count a failed try for one email and IP at the same instant. Without
     * the lock their read-add-write of the counter would overwrite each other and let more than five through.
     */
    #[Test]
    public function failedTriesSentInParallelAllCountSoOnlyFiveAreLetThrough(): void
    {
        $email = 'lena.'.bin2hex(random_bytes(4)).'@acme-foods.example';
        $ip = self::randomIp();
        $startAt = (string) (microtime(true) + 4);
        $processes = [];
        for ($worker = 1; $worker <= 10; ++$worker) {
            $process = new Process([\PHP_BINARY, __DIR__.'/count-failed-login.php', $email, $ip, $startAt]);
            $process->start();
            $processes[] = $process;
        }

        $answers = [];
        foreach ($processes as $process) {
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $answers[] = $process->getOutput();
        }

        $counts = array_count_values($answers) + ['accepted' => 0, 'refused' => 0];
        ksort($counts);
        self::assertSame(['accepted' => 5, 'refused' => 5], $counts);
        self::assertSame(0, self::limiter()->peek(self::loginRequest($email, $ip))->getRemainingTokens());
    }

    private static function limiter(): PeekableRequestRateLimiterInterface
    {
        return self::getContainer()->get('identity.login_rate_limiter');
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
