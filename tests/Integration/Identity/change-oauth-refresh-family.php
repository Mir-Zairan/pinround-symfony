<?php

declare(strict_types=1);

/*
 * One change of a refresh token family through the container's repository, in its own PHP process with its own
 * database connection like one app worker, for DoctrineOAuthRefreshTokensTest. In one transaction under the
 * organization as tenant, either
 *   change-oauth-refresh-family.php rotate <organization id> <token hash in hex> <client id> <time>
 * marks the token used and adds the family's next token, printing "won", or "lost" when it was not marked; or
 *   change-oauth-refresh-family.php revoke <organization id> <family id> <time>
 * revokes the family, printing how many untraded access tokens it named.
 */

use App\Identity\Domain\OAuthRefreshToken;
use App\Identity\Domain\OAuthRefreshTokens;
use App\Identity\Infrastructure\Security\HmacApiTokenHashing;
use App\Kernel;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? null;
assert(is_array($arguments) && is_string($arguments[1] ?? null) && is_string($arguments[2] ?? null) && is_string($arguments[3] ?? null) && is_string($arguments[4] ?? null));
$change = $arguments[1];
$organizationId = new OrganizationId($arguments[2]);

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
new Dotenv()->bootEnv(dirname(__DIR__, 3).'/.env');
$kernel = new Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
assert($container instanceof TestContainer);
$refreshTokens = $container->get('test.identity_oauth_refresh_tokens');
assert($refreshTokens instanceof OAuthRefreshTokens);
$entityManager = $container->get('doctrine.orm.entity_manager');
$container->get(TenantContext::class)->set($organizationId);
if ('rotate' === $change) {
    assert(is_string($arguments[5] ?? null));
    $tokenHash = hex2bin($arguments[3]);
    assert(is_string($tokenHash));
    $clientId = $arguments[4];
    $at = new DateTimeImmutable($arguments[5]);
    echo $entityManager->wrapInTransaction(static function () use ($refreshTokens, $organizationId, $tokenHash, $clientId, $at): string {
        $traded = $refreshTokens->markUsed($tokenHash, $clientId, $at);
        if (null === $traded) {
            return 'lost';
        }
        $refreshTokens->add(OAuthRefreshToken::issue(
            $refreshTokens->nextIdentity(),
            $traded->familyId(),
            $organizationId,
            $traded->userId(),
            $clientId,
            $traded->scopes(),
            $refreshTokens->nextIdentity(),
            new HmacApiTokenHashing('change-family-pepper-of-32-bytes-or-more'),
            $at,
        )->token());

        return 'won';
    });
} elseif ('revoke' === $change) {
    $familyId = $arguments[3];
    $at = new DateTimeImmutable($arguments[4]);
    echo count($entityManager->wrapInTransaction(static fn (): array => $refreshTokens->revokeFamily($familyId, $at)));
} else {
    fwrite(\STDERR, 'Unknown change: '.$change."\n");
    exit(2);
}
