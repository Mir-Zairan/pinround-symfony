<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Domain\ApiTokenHashing;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\OAuthAuthorizationCode;
use App\Identity\Domain\OAuthAuthorizationCodes;
use App\Identity\Domain\OAuthClients;
use App\Identity\Domain\User;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\MockClock;

/**
 * OAuth clients registered through POST /oauth/register, authorization codes issued as the consent page issues them,
 * and the removal of the clients and of the codes and refresh tokens of the organizations the test made. Use it next to
 * LogsInUsers, and call removeOAuthClientsAndCodes() in tearDown before LogsInUsers' own.
 */
trait RegistersOAuthClients
{
    /** @var list<string> */
    private array $registeredClientIds = [];

    /**
     * Committed through the container's repository and hashing, so the token endpoint finds it. Its resolver compares
     * with the database clock, so a code the endpoint should find is issued ahead of it, such as in 2099.
     *
     * @param list<ApiTokenScope> $scopes
     *
     * @return string the plain code
     */
    private function issueAuthorizationCode(User $user, OrganizationId $organizationId, string $clientId, string $redirectUri, string $codeChallenge, array $scopes, \DateTimeImmutable $issuedAt): string
    {
        $client = self::getContainer()->get(OAuthClients::class)->byClientId($clientId);
        self::assertNotNull($client, 'no client has the id '.$clientId);
        $codes = self::getContainer()->get('test.identity_oauth_authorization_codes');
        \assert($codes instanceof OAuthAuthorizationCodes);
        $hashing = self::getContainer()->get('test.identity_api_token_hashing');
        \assert($hashing instanceof ApiTokenHashing);
        $issued = OAuthAuthorizationCode::issue($codes->nextIdentity(), $organizationId, $user->id(), $client, $redirectUri, $codeChallenge, 'S256', $scopes, $hashing, $issuedAt);
        $codes->add($issued->code());

        return $issued->reveal();
    }

    /**
     * @param list<string>      $redirectUris
     * @param list<string>|null $grantTypes   null leaves grant_types out, which registers authorization_code alone
     *
     * @return string the client id
     */
    private function registerClient(KernelBrowser $client, string $clientName, array $redirectUris, ?array $grantTypes = null): string
    {
        $metadata = ['client_name' => $clientName, 'redirect_uris' => $redirectUris];
        if (null !== $grantTypes) {
            $metadata['grant_types'] = $grantTypes;
        }
        $client->request('POST', '/oauth/register', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($metadata, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
        self::assertResponseStatusCodeSame(201);
        $answer = json_decode((string) $client->getResponse()->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);
        $clientId = $answer['client_id'] ?? null;
        self::assertIsString($clientId);
        $this->registeredClientIds[] = $clientId;

        return $clientId;
    }

    /**
     * A connection as a client makes one: a code for read is issued at $tradedAt and traded at POST /oauth/token with
     * the clock there, then the clock goes back to where it was. The resolvers compare with the database clock, so
     * $tradedAt is ahead of it, such as in 2099. Make it before the browser logs in: a request with the clock ahead of
     * the wall clock would end the session.
     *
     * @return array{familyId: string, accessToken: string, refreshToken: string}
     */
    private function connectWithOAuth(KernelBrowser $client, MockClock $clock, User $user, OrganizationId $organizationId, string $clientId, string $redirectUri, string $tradedAt): array
    {
        // The verifier and S256 challenge of RFC 7636 Appendix B.
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $wasAt = $clock->now();
        $clock->modify($tradedAt);
        try {
            $code = $this->issueAuthorizationCode($user, $organizationId, $clientId, $redirectUri, 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', [ApiTokenScope::Read], $clock->now());
            $client->request('POST', '/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri, 'client_id' => $clientId, 'code_verifier' => $verifier]);
            self::assertResponseStatusCodeSame(200, (string) $client->getResponse()->getContent());
        } finally {
            $clock->modify($wasAt->format(\DATE_ATOM));
        }
        $answer = json_decode((string) $client->getResponse()->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($answer);
        $accessToken = $answer['access_token'] ?? null;
        $refreshToken = $answer['refresh_token'] ?? null;
        self::assertIsString($accessToken);
        self::assertIsString($refreshToken);

        return ['familyId' => $this->familyOfRefreshToken($organizationId, $refreshToken), 'accessToken' => $accessToken, 'refreshToken' => $refreshToken];
    }

    private function familyOfRefreshToken(OrganizationId $organizationId, string $refreshToken): string
    {
        $hashing = self::getContainer()->get('test.identity_api_token_hashing');
        \assert($hashing instanceof ApiTokenHashing);
        $familyId = $this->readAs($organizationId, static fn (Connection $migrator): mixed => $migrator->fetchOne(
            'SELECT family_id::text FROM idn_oauth_refresh_token WHERE token_hash = ?',
            [$hashing->hash($refreshToken)],
            [ParameterType::BINARY],
        ));
        self::assertIsString($familyId, 'no refresh token of the organization has this hash');

        return $familyId;
    }

    /**
     * @return list<string|null> when each refresh token of the family was revoked, in UTC; null for one that was not
     */
    private function refreshTokensRevokedAtOf(OrganizationId $organizationId, string $familyId): array
    {
        $revokedAt = $this->readAs($organizationId, static fn (Connection $migrator): array => $migrator->fetchFirstColumn(
            "SELECT to_char(revoked_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') FROM idn_oauth_refresh_token WHERE family_id = ? ORDER BY created_at",
            [$familyId],
        ));
        self::assertNotSame([], $revokedAt, 'the family has no refresh token');

        return array_map(static fn (mixed $at): ?string => \is_string($at) ? $at : null, $revokedAt);
    }

    private function removeOAuthClientsAndCodes(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->readAs(new OrganizationId($organizationId), static function (Connection $migrator): void {
                $migrator->executeStatement('DELETE FROM idn_oauth_authorization_code');
                $migrator->executeStatement('DELETE FROM idn_oauth_refresh_token');
            });
        }
        if ([] !== $this->registeredClientIds) {
            $this->migrator()->executeStatement('DELETE FROM idn_oauth_client WHERE client_id IN (?)', [$this->registeredClientIds], [ArrayParameterType::STRING]);
            $this->registeredClientIds = [];
        }
    }

    /**
     * Through the migrator, which owns the table; FORCE ROW LEVEL SECURITY binds it too, so it runs under the tenant.
     *
     * @template T
     *
     * @param \Closure(Connection): T $work
     *
     * @return T
     */
    private function readAs(OrganizationId $organizationId, \Closure $work): mixed
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->reset();
        $tenantContext->set($organizationId);
        try {
            return $this->migrator()->transactional($work);
        } finally {
            $tenantContext->reset();
        }
    }
}
