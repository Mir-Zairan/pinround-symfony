<?php

declare(strict_types=1);

/*
 * One wrong-password login through the real firewall, in its own PHP process like one app worker, for
 * LoginRateLimiterTest. Usage: try-login.php <email> <client ip> <burst size> <check counter key>. Prints "ready" once
 * booted, waits for a line on stdin, prints "started", then sends the try and prints the short class name of the
 * refusal it got.
 *
 * Each password check adds one to the counter key on the state Valkey, then holds until the whole burst is checking
 * (or 2 seconds pass): test hashing takes microseconds, so without the hold every try would finish before the next
 * one starts and a burst could not overlap the way it does with prod's Argon2id cost.
 */

use App\Identity\Infrastructure\Security\SecurityUser;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherAwareInterface;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? null;
assert(is_array($arguments) && is_string($arguments[1] ?? null) && is_string($arguments[2] ?? null) && is_numeric($arguments[3] ?? null) && is_string($arguments[4] ?? null));
[, $email, $clientIp, $burstSize, $checkCounterKey] = $arguments;

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
new Dotenv()->bootEnv(dirname(__DIR__, 3).'/.env');
$kernel = new Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
assert($container instanceof TestContainer);

$stateValkey = $container->get('state_valkey.connection');
// security.yaml's test hasher, built here because the container's own may not be read before it is replaced.
$hasherFactory = new PasswordHasherFactory([SecurityUser::class => ['algorithm' => 'argon2id', 'time_cost' => 3, 'memory_cost' => 10]]);
$container->set('security.password_hasher_factory', new readonly class($hasherFactory, $stateValkey, (int) $burstSize, $checkCounterKey) implements PasswordHasherFactoryInterface {
    public function __construct(private PasswordHasherFactoryInterface $hasherFactory, private Redis $stateValkey, private int $burstSize, private string $checkCounterKey)
    {
    }

    public function getPasswordHasher(string|PasswordAuthenticatedUserInterface|PasswordHasherAwareInterface $user): PasswordHasherInterface
    {
        return new readonly class($this->hasherFactory->getPasswordHasher($user), $this->stateValkey, $this->burstSize, $this->checkCounterKey) implements PasswordHasherInterface {
            public function __construct(private PasswordHasherInterface $hasher, private Redis $stateValkey, private int $burstSize, private string $checkCounterKey)
            {
            }

            public function hash(#[SensitiveParameter] string $plainPassword): string
            {
                return $this->hasher->hash($plainPassword);
            }

            public function verify(string $hashedPassword, #[SensitiveParameter] string $plainPassword): bool
            {
                $this->stateValkey->incr($this->checkCounterKey);
                $this->stateValkey->expire($this->checkCounterKey, 60);
                for ($deadline = microtime(true) + 2; $this->checksSoFar() < $this->burstSize && microtime(true) < $deadline;) {
                    usleep(10_000);
                }

                return $this->hasher->verify($hashedPassword, $plainPassword);
            }

            private function checksSoFar(): int
            {
                $checks = $this->stateValkey->get($this->checkCounterKey);

                return is_string($checks) ? (int) $checks : 0;
            }

            public function needsRehash(string $hashedPassword): bool
            {
                return $this->hasher->needsRehash($hashedPassword);
            }
        };
    }
});

// The form's CSRF token lives in the session, so this process opens the login page's session first.
$session = $container->get('session.factory')->createSession();
$loginPage = Request::create('https://localhost/login');
$loginPage->setSession($session);
$container->get('request_stack')->push($loginPage);
$csrfToken = $container->get('security.csrf.token_manager')->getToken('authenticate')->getValue();
$session->save();
$container->get('request_stack')->pop();

$login = Request::create(
    'https://localhost/login',
    'POST',
    ['email' => $email, 'password' => 'not the password at all', '_csrf_token' => $csrfToken],
    [$session->getName() => $session->getId()],
    server: ['REMOTE_ADDR' => $clientIp],
);

echo "ready\n";
fgets(\STDIN);
echo "started\n";
$kernel->handle($login);

$refusal = $login->getSession()->get(SecurityRequestAttributes::AUTHENTICATION_ERROR);
echo $refusal instanceof Throwable ? new ReflectionClass($refusal)->getShortName() : 'none';
