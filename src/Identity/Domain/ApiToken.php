<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TenantOwned;
use App\SharedKernel\Domain\UserId;

/**
 * A personal access token of one user in one organization, for Claude Code and the browser plugin (ADR-0021). Only
 * the peppered hash of the token is kept, with a short display prefix; the token itself is shown once, by issue().
 * Revocation, last use and the expiry warning are written in the database only (ApiTokens::revoke, ApiTokens::markUsed,
 * ApiTokens::claimExpiryWarnings), so two requests at once decide on the row, never on an entity loaded earlier.
 */
final class ApiToken implements TenantOwned
{
    public const string PLAIN_PREFIX = 'pr_';
    public const int DEFAULT_LIFETIME_DAYS = 90;
    public const int MAX_LIFETIME_DAYS = 365;
    public const int NAME_MAX_LENGTH = 100;
    public const int DISPLAY_PREFIX_LENGTH = 8;
    /**
     * A token is warned within this many days before it expires, and only when it lives longer than that: a shorter
     * one would be warned the day it is made. The resolver organizations_with_expiring_api_tokens repeats the number.
     */
    public const int EXPIRY_WARNING_WINDOW_DAYS = 8;
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
    private ?\DateTimeImmutable $lastUsedAt = null;
    private ?\DateTimeImmutable $revokedAt = null;
    private ?\DateTimeImmutable $expiryWarnedAt = null;

    /**
     * @param list<string> $scopes
     */
    private function __construct(private readonly string $id, OrganizationId $organizationId, UserId $userId, private readonly string $name, string $tokenHash, private readonly string $prefix, private readonly array $scopes, private readonly \DateTimeImmutable $createdAt, int $lifetimeDays)
    {
        $this->organizationId = $organizationId->toString();
        $this->userId = $userId->toString();
        $this->tokenHash = $tokenHash;
        // In UTC, so a daylight saving change cannot make a day 25 hours and the cap check in the table refuse it.
        $this->expiresAt = $createdAt->setTimezone(new \DateTimeZone('UTC'))->add(new \DateInterval('P'.$lifetimeDays.'D'));
    }

    /**
     * A new token for $userId in $organizationId, valid for $lifetimeDays from $issuedAt. The plain token is
     * "pr_" and 32 random bytes in base64url; it is in the result only and never kept.
     *
     * @param string              $id     a UUID from ApiTokens::nextIdentity()
     * @param string              $name   what the user calls it; Unicode space trimmed, 1 to NAME_MAX_LENGTH characters,
     *                                    one line
     * @param list<ApiTokenScope> $scopes at least one; repeats count once
     *
     * @throws InvalidApiToken when the name is blank (only spaces and format characters counts as blank), too long or
     *                         has a control character, a format character or a line break, no scope is given, or
     *                         $lifetimeDays is not between 1 and MAX_LIFETIME_DAYS
     */
    public static function issue(string $id, OrganizationId $organizationId, UserId $userId, string $name, array $scopes, ApiTokenHashing $hashing, \DateTimeImmutable $issuedAt, int $lifetimeDays = self::DEFAULT_LIFETIME_DAYS): IssuedApiToken
    {
        $trimmedName = OneLineText::trimmed($name);
        if (null === $trimmedName) {
            throw new InvalidApiToken('A token name is one line of plain text.');
        }
        if (OneLineText::isBlank($trimmedName)) {
            throw new InvalidApiToken('Give the token a name.');
        }
        if (OneLineText::hasControlLineBreakOrFormat($trimmedName)) {
            throw new InvalidApiToken('A token name is one line of plain text.');
        }
        if (mb_strlen($trimmedName) > self::NAME_MAX_LENGTH) {
            throw new InvalidApiToken(\sprintf('A token name has at most %d characters.', self::NAME_MAX_LENGTH));
        }
        if ([] === $scopes) {
            throw new InvalidApiToken('Choose at least one thing the token may do.');
        }
        if ($lifetimeDays < 1 || $lifetimeDays > self::MAX_LIFETIME_DAYS) {
            throw new InvalidApiToken(\sprintf('A token lasts from 1 to %d days.', self::MAX_LIFETIME_DAYS));
        }

        $randomPart = rtrim(strtr(base64_encode(random_bytes(self::RANDOM_BYTES)), '+/', '-_'), '=');
        $plainToken = self::PLAIN_PREFIX.$randomPart;
        // In the order of the cases, so the same choice is always stored the same way.
        $scopeValues = array_values(array_map(
            static fn (ApiTokenScope $scope): string => $scope->value,
            array_filter(ApiTokenScope::cases(), static fn (ApiTokenScope $scope): bool => \in_array($scope, $scopes, true)),
        ));
        $token = new self(
            $id,
            $organizationId,
            $userId,
            $trimmedName,
            $hashing->hash($plainToken),
            self::PLAIN_PREFIX.substr($randomPart, 0, self::DISPLAY_PREFIX_LENGTH),
            $scopeValues,
            $issuedAt,
            $lifetimeDays,
        );

        return new IssuedApiToken($token, $plainToken);
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

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return string "pr_" and the first 8 characters of the random part, to tell tokens apart in a list
     */
    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * @return string the 32 raw bytes of ApiTokenHashing::hash() of the plain token
     */
    public function tokenHash(): string
    {
        $storedHash = \is_resource($this->tokenHash) ? stream_get_contents($this->tokenHash, null, 0) : $this->tokenHash;
        if (!\is_string($storedHash)) {
            throw new \UnexpectedValueException('A stored token hash could not be read.');
        }

        return $storedHash;
    }

    /**
     * @return list<ApiTokenScope>
     */
    public function scopes(): array
    {
        return ApiTokenScope::fromValues($this->scopes);
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * The token works until just before this instant, as in resolve_api_token.
     */
    public function expiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * When the token was last used, to the minute at best: ApiTokens::markUsed writes at most once a minute.
     */
    public function lastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function revokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    /**
     * When the warning job claimed the token to email its owner that it stops working soon; null until then.
     * A token already loaded keeps its old value.
     */
    public function expiryWarnedAt(): ?\DateTimeImmutable
    {
        return $this->expiryWarnedAt;
    }
}
