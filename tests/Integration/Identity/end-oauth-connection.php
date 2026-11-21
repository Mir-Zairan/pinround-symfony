<?php

declare(strict_types=1);

/*
 * One end of an OAuth connection through EndOAuthConnection, in its own PHP process with its own database connection
 * like one app worker, for EndOAuthConnectionTest. Logged in as the person with a password session, in one transaction
 * under the organization as tenant,
 *   end-oauth-connection.php <organization id> <family id> <person's user id> <time>
 * prints "ended", or "ended already" when the call found the family ended.
 */

use App\Identity\Domain\Credential;
use App\Identity\Domain\Email;
use App\Identity\Domain\PasswordHash;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Kernel;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Identity\HandWiredOAuthConnections;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? null;
assert(is_array($arguments) && is_string($arguments[1] ?? null) && is_string($arguments[2] ?? null) && is_string($arguments[3] ?? null) && is_string($arguments[4] ?? null));
[, $organizationId, $familyId, $userId, $at] = $arguments;

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
new Dotenv()->bootEnv(dirname(__DIR__, 3).'/.env');
$kernel = new Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
assert($container instanceof TestContainer);

$user = new User(new UserId($userId), new Email('end-oauth-connection@acme-foods.example'), 'Omar', 'en', 'Europe/Berlin', new DateTimeImmutable('2026-11-02 09:30:00 UTC'));
$securityUser = SecurityUser::of($user, new Credential($user, new PasswordHash('$argon2id$v=19$m=65536,t=4,p=1$NUkxU2sxQlFxbXpDaUZUQQ$suVp+0jzKZuqeONGVmUghGQxxI2vd02CMokdMtaQcfA'), new DateTimeImmutable('2026-11-02 09:30:00 UTC')));
$container->get('security.token_storage')->setToken(new UsernamePasswordToken($securityUser, 'main', $securityUser->getRoles()));
$container->get(TenantContext::class)->set(new OrganizationId($organizationId));
$endOAuthConnection = new HandWiredOAuthConnections($container)->endOAuthConnection();

$hasEnded = $container->get('doctrine.orm.entity_manager')->wrapInTransaction(static fn (): bool => $endOAuthConnection($familyId, new DateTimeImmutable($at)));
echo $hasEnded ? 'ended' : 'ended already';
