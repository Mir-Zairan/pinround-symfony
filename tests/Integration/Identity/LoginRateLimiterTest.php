<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RateLimiter\PeekableRequestRateLimiterInterface;
use Symfony\Component\HttpFoundation\Request;
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
