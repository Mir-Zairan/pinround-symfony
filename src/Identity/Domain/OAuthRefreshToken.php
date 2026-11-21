<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TenantOwned;
use App\SharedKernel\Domain\UserId;

/**
 * An OAuth refresh token (ADR-0050): traded once at the token endpoint for a new access token and a new refresh token
 * of the same family. A family is one grant, from the code exchange on; when a token already traded comes back, the
 * whole family is revoked (RFC 9700). It is bound to the client, the person, the one organization of the grant and its
 * scopes, and names the access token issued with it. Only the peppered hash is kept; the token itself is shown once, by
 * issue(). Its use and revocation are written in the database only (OAuthRefreshTokens), so of two trades at once only
 * one gets it.
 */
final class OAuthRefreshToken implements TenantOwned
{
    public const string PLAIN_PREFIX = 'prr_';
    /** What issue() hands out: the prefix and 32 random bytes in base64url. */
    public const string PLAIN_SHAPE = '/\A'.self::PLAIN_PREFIX.'[A-Za-z0-9_-]{43}\z/';
    /** idn_oauth_refresh_token_expires_at_check holds the same cap. */
    public const int LIFETIME_DAYS = 30;
    private const int RANDOM_BYTES = 32;

    private readonly string $organizationId;
    private readonly string $userId;
    /**
     * Doctrine's binary type loads a stream, so a loaded token holds a resource here.
     *
     * @var resource|string
     */
    private mixed $tokenHash;
    private readonly \DateTimeImmutable $expiresAt;
    private ?\DateTimeImmutable $usedAt = null;
    private ?\DateTimeImmutable $revokedAt = null;

    /**
     * @param list<string> $scopes
     */
    private function __construct(
        private readonly string $id,
        private readonly string $familyId,
        OrganizationId $organizationId,
        UserId $userId,
        private readonly string $clientId,
        private readonly array $scopes,
        string $tokenHash,
        private readonly string $accessTokenId,
        private readonly \DateTimeImmutable $createdAt,
    ) {
        $this->organizationId = $organizationId->toString();
        $this->userId = $userId->toString();
        $this->tokenHash = $tokenHash;
        $this->expiresAt = $createdAt->add(new \DateInterval('P'.self::LIFETIME_DAYS.'D'));
    }

    /**
     * A new refresh token, valid for LIFETIME_DAYS from $issuedAt. The plain token is "prr_" and 32 random bytes in
     * base64url; it is in the result only and never kept.
     *
     * @param string              $id            a UUID from OAuthRefreshTokens::nextIdentity()
     * @param string              $familyId      the family's UUID: a new one at the code exchange, the traded token's
     *                                           at a refresh
     * @param list<ApiTokenScope> $scopes        at least one; repeats count once
     * @param string              $accessTokenId the UUID of the access token issued with it
     * @param \DateTimeImmutable  $issuedAt      kept to the whole second, in UTC
     *
     * @throws \InvalidArgumentException when no scope is given
     */
    public static function issue(
        string $id,
        string $familyId,
        OrganizationId $organizationId,
        UserId $userId,
        string $clientId,
        array $scopes,
        string $accessTokenId,
        ApiTokenHashing $hashing,
        \DateTimeImmutable $issuedAt,
    ): IssuedOAuthRefreshToken {
        if ([] === $scopes) {
            throw new \InvalidArgumentException('A refresh token grants at least one scope.');
        }
        $plainToken = self::PLAIN_PREFIX.rtrim(strtr(base64_encode(random_bytes(self::RANDOM_BYTES)), '+/', '-_'), '=');
        // In the order of the cases, so the same choice is always stored the same way.
        $scopeValues = array_values(array_map(
            static fn (ApiTokenScope $scope): string => $scope->value,
            array_filter(ApiTokenScope::cases(), static fn (ApiTokenScope $scope): bool => \in_array($scope, $scopes, true)),
        ));
        $token = new self(
            $id,
            $familyId,
            $organizationId,
            $userId,
            $clientId,
            $scopeValues,
            $hashing->hash($plainToken),
            $accessTokenId,
            // The columns keep whole seconds; PostgreSQL would round a fraction up and could stretch the 30 days.
            new \DateTimeImmutable('@'.$issuedAt->getTimestamp()),
        );

        return new IssuedOAuthRefreshToken($token, $plainToken);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function familyId(): string
    {
        return $this->familyId;
    }

    public function organizationId(): OrganizationId
    {
        return new OrganizationId($this->organizationId);
    }

    public function userId(): UserId
    {
        return new UserId($this->userId);
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    /**
     * @return list<ApiTokenScope>
     */
    public function scopes(): array
    {
        return ApiTokenScope::fromValues($this->scopes);
    }

    /**
     * @return string the 32 raw bytes of ApiTokenHashing::hash() of the plain token
     */
    public function tokenHash(): string
    {
        $storedHash = \is_resource($this->tokenHash) ? stream_get_contents($this->tokenHash, null, 0) : $this->tokenHash;
        if (!\is_string($storedHash)) {
            throw new \UnexpectedValueException('A stored refresh token hash could not be read.');
        }

        return $storedHash;
    }

    /**
     * @return string the id of the idn_api_token row issued with this token
     */
    public function accessTokenId(): string
    {
        return $this->accessTokenId;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * The token can be traded until just before this instant.
     */
    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * When the token was traded; null until then. A token already loaded keeps its old value.
     */
    public function usedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    /**
     * When the token, or its whole family, was revoked; null until then. A token already loaded keeps its old value.
     */
    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }
}
