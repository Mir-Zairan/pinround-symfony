<?php

declare(strict_types=1);

/*
 * One OAuthAuthorizationCodes::consume() through the container's repository, in a transaction under the organization
 * as tenant, in its own PHP process with its own database connection like one app worker, for
 * DoctrineOAuthAuthorizationCodesTest. Usage: consume-oauth-code.php <organization id> <code hash in hex> <time of the
 * use>. Prints "won" when it marked the code used, "lost" otherwise.
 */

use App\Identity\Domain\OAuthAuthorizationCode;
use App\Identity\Domain\OAuthAuthorizationCodes;
use App\Kernel;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? null;
assert(is_array($arguments) && is_string($arguments[1] ?? null) && is_string($arguments[2] ?? null) && is_string($arguments[3] ?? null));
[, $organizationId, $codeHashHex, $usedAt] = $arguments;

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
new Dotenv()->bootEnv(dirname(__DIR__, 3).'/.env');
$kernel = new Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
assert($container instanceof TestContainer);
$codes = $container->get('test.identity_oauth_authorization_codes');
assert($codes instanceof OAuthAuthorizationCodes);

$codeHash = hex2bin($codeHashHex);
assert(is_string($codeHash));
$container->get(TenantContext::class)->set(new OrganizationId($organizationId));
$consumed = $container->get('doctrine.orm.default_entity_manager')->wrapInTransaction(static fn (): ?OAuthAuthorizationCode => $codes->consume($codeHash, new DateTimeImmutable($usedAt)));
echo null === $consumed ? 'lost' : 'won';
