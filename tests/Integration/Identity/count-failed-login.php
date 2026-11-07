<?php

declare(strict_types=1);

/*
 * One failed login counted by the app's login limiter in its own PHP process, for LoginRateLimiterTest.
 * Usage: count-failed-login.php <email> <client ip> <unix time to wait for>. Prints "accepted" or "refused".
 */

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
new Dotenv()->bootEnv(dirname(__DIR__, 3).'/.env');
$kernel = new Kernel('test', true);
$kernel->boot();

$testContainer = $kernel->getContainer()->get('test.service_container');
assert($testContainer instanceof Psr\Container\ContainerInterface);
$limiter = $testContainer->get('identity.login_rate_limiter');

$arguments = $_SERVER['argv'] ?? null;
assert(is_array($arguments) && is_string($arguments[1] ?? null) && is_string($arguments[2] ?? null) && is_numeric($arguments[3] ?? null));
[, $email, $clientIp, $startAt] = $arguments;
$request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => $clientIp]);
$request->attributes->set(SecurityRequestAttributes::LAST_USERNAME, $email);

// Every process waits for the same instant, so their read-add-write of the counter overlap.
time_sleep_until((float) $startAt);
echo $limiter->consume($request)->isAccepted() ? 'accepted' : 'refused';
