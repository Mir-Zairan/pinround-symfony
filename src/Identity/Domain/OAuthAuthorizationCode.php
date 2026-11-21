<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TenantOwned;
use App\SharedKernel\Domain\UserId;

/**
 * An OAuth authorization code (ADR-0050): what the consent page hands the client through its redirect URI, to trade
 * once for tokens. It is bound to the client, the exact redirect URI, the PKCE challenge, the scopes, the person and
 * the one organization picked on the consent page. Only the peppered hash of the code is kept; the code itself is
 * shown once, by issue(). Its use is written in the database only (OAuthAuthorizationCodes::consume), so of two
 * trades at once only one gets it.
 */
final class OAuthAuthorizationCode implements TenantOwned
{
    public const int MAX_LIFETIME_SECONDS = 60;
    /** The characters and length RFC 7636 allows; idn_oauth_authorization_code_code_challenge_check holds the same. */
    public const string CODE_CHALLENGE_SHAPE = '/\A[A-Za-z0-9._~-]{43,128}\z/';
    /** RFC 7636 section 4.1: the verifier has the challenge's characters and length. */
    public const string CODE_VERIFIER_SHAPE = self::CODE_CHALLENGE_SHAPE;
    /** What issue() hands out: 32 random bytes in base64url. */
    public const string PLAIN_CODE_SHAPE = '/\A[A-Za-z0-9_-]{43}\z/';
    private const int RANDOM_BYTES = 32;

    private readonly string $organizationId;
    private readonly string $userId;
    private readonly string $clientId;
    /**
     * Doctrine's binary type loads a stream, so a loaded code holds a resource here.
     *
     * @var resource|string
     */
    private mixed $codeHash;
    private readonly \DateTimeImmutable $expiresAt;
    private ?\DateTimeImmutable $usedAt = null;

    /**
     * @param list<string> $scopes
     */
    private function __construct(
        private readonly string $id,
        OrganizationId $organizationId,
        UserId $userId,
        OAuthClient $client,
        private readonly string $redirectUri,
        private readonly string $codeChallenge,
        private readonly array $scopes,
        string $codeHash,
        private readonly \DateTimeImmutable $createdAt,
        int $lifetimeSeconds,
    ) {
        $this->organizationId = $organizationId->toString();
        $this->userId = $userId->toString();
        $this->clientId = $client->clientId();
        $this->codeHash = $codeHash;
        $this->expiresAt = $createdAt->add(new \DateInterval('PT'.$lifetimeSeconds.'S'));
    }

    /**
     * A new code for $userId in $organizationId, for $client, valid for $lifetimeSeconds from $issuedAt. The plain code
     * is 32 random bytes in base64url (43 characters); it is in the result only and never kept.
     *
     * @param string              $id                  a UUID from OAuthAuthorizationCodes::nextIdentity()
     * @param string              $redirectUri         the one the authorize request carried, kept exactly as given; the
     *                                                 caller has matched it against the client's registered ones
     * @param string              $codeChallengeMethod only "S256" (OAuthClient::CODE_CHALLENGE_METHOD), case-sensitive
     * @param list<ApiTokenScope> $scopes              at least one; repeats count once
     * @param \DateTimeImmutable  $issuedAt            kept to the whole second, in UTC
     * @param int                 $lifetimeSeconds     1 to MAX_LIFETIME_SECONDS
     *
     * @throws InvalidOAuthAuthorizationCode when the challenge does not match CODE_CHALLENGE_SHAPE, the method is not
     *                                       S256, no scope is given, the redirect URI breaks the rule of
     *                                       OAuthRedirectUri, or the lifetime is out of range
     */
    public static function issue(
        string $id,
        OrganizationId $organizationId,
        UserId $userId,
        OAuthClient $client,
        string $redirectUri,
        string $codeChallenge,
        string $codeChallengeMethod,
        array $scopes,
        ApiTokenHashing $hashing,
        \DateTimeImmutable $issuedAt,
        int $lifetimeSeconds = self::MAX_LIFETIME_SECONDS,
    ): IssuedOAuthAuthorizationCode {
        if (OAuthClient::CODE_CHALLENGE_METHOD !== $codeChallengeMethod) {
            throw new InvalidOAuthAuthorizationCode('The code_challenge_method is S256.');
        }
        if (1 !== preg_match(self::CODE_CHALLENGE_SHAPE, $codeChallenge)) {
            throw new InvalidOAuthAuthorizationCode('A code_challenge is 43 to 128 characters of A-Z, a-z, 0-9, ".", "_", "~" and "-".');
        }
        if ([] === $scopes) {
            throw new InvalidOAuthAuthorizationCode('A code grants at least one scope.');
        }
        if ($lifetimeSeconds < 1 || $lifetimeSeconds > self::MAX_LIFETIME_SECONDS) {
            throw new InvalidOAuthAuthorizationCode(\sprintf('A code lasts from 1 to %d seconds.', self::MAX_LIFETIME_SECONDS));
        }
        try {
            $checkedRedirectUri = new OAuthRedirectUri($redirectUri)->toString();
        } catch (InvalidOAuthRedirectUri $refusal) {
            throw new InvalidOAuthAuthorizationCode($refusal->getMessage(), previous: $refusal);
        }

        $plainCode = rtrim(strtr(base64_encode(random_bytes(self::RANDOM_BYTES)), '+/', '-_'), '=');
        // In the order of the cases, so the same choice is always stored the same way.
        $scopeValues = array_values(array_map(
            static fn (ApiTokenScope $scope): string => $scope->value,
            array_filter(ApiTokenScope::cases(), static fn (ApiTokenScope $scope): bool => \in_array($scope, $scopes, true)),
        ));
        $code = new self(
            $id,
            $organizationId,
            $userId,
            $client,
            $checkedRedirectUri,
            $codeChallenge,
            $scopeValues,
            $hashing->hash($plainCode),
            // The columns keep whole seconds; PostgreSQL would round a fraction up and could stretch the 60 seconds.
            new \DateTimeImmutable('@'.$issuedAt->getTimestamp()),
            $lifetimeSeconds,
        );

        return new IssuedOAuthAuthorizationCode($code, $plainCode);
    }

    public function id(): string
    {
        return $this->id;
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
     * @return string exactly as the authorize request carried it; the token request must carry the same
     */
    public function redirectUri(): string
    {
        return $this->redirectUri;
    }

    /**
     * @return string the S256 challenge: base64url SHA-256 of the verifier the token request must present
     */
    public function codeChallenge(): string
    {
        return $this->codeChallenge;
    }

    /**
     * @return list<ApiTokenScope>
     */
    public function scopes(): array
    {
        return ApiTokenScope::fromValues($this->scopes);
    }

    /**
     * Whether $codeVerifier is the PKCE verifier of this code's S256 challenge: BASE64URL(SHA-256(verifier)) is the
     * challenge (RFC 7636 section 4.6), compared in constant time. A verifier that does not match CODE_VERIFIER_SHAPE
     * never is.
     */
    public function isVerifiedBy(#[\SensitiveParameter] string $codeVerifier): bool
    {
        if (1 !== preg_match(self::CODE_VERIFIER_SHAPE, $codeVerifier)) {
            return false;
        }

        return hash_equals($this->codeChallenge, rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '='));
    }

    /**
     * @return string the 32 raw bytes of ApiTokenHashing::hash() of the plain code
     */
    public function codeHash(): string
    {
        $storedHash = \is_resource($this->codeHash) ? stream_get_contents($this->codeHash, null, 0) : $this->codeHash;
        if (!\is_string($storedHash)) {
            throw new \UnexpectedValueException('A stored code hash could not be read.');
        }

        return $storedHash;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * The code can be used until just before this instant.
     */
    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * When the code was traded; null until then. A code already loaded keeps its old value.
     */
    public function usedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }
}
