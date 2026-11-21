<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Domain\OAuthRefreshToken;
use App\Identity\Domain\OAuthRefreshTokens;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Records add(); IssueOAuthTokenPair calls nothing else.
 */
final class AddRecordingOAuthRefreshTokens implements OAuthRefreshTokens
{
    /** @var list<OAuthRefreshToken> */
    public array $added = [];

    private int $issuedIds = 0;

    public function nextIdentity(): string
    {
        return \sprintf('0197a3c0-0000-7000-8004-%012d', ++$this->issuedIds);
    }

    public function add(OAuthRefreshToken $token): void
    {
        $this->added[] = $token;
    }

    public function markUsed(#[\SensitiveParameter] string $tokenHash, string $clientId, \DateTimeImmutable $at): ?OAuthRefreshToken
    {
        throw new \LogicException('IssueOAuthTokenPair never marks a token used.');
    }

    public function byHash(#[\SensitiveParameter] string $tokenHash): ?OAuthRefreshToken
    {
        throw new \LogicException('IssueOAuthTokenPair never loads a token.');
    }

    public function byAccessTokenId(string $accessTokenId): ?OAuthRefreshToken
    {
        throw new \LogicException('IssueOAuthTokenPair never loads a token.');
    }

    public function ownerOfFamily(string $familyId): ?UserId
    {
        throw new \LogicException('IssueOAuthTokenPair never reads a family\'s owner.');
    }

    public function revokeFamily(string $familyId, \DateTimeImmutable $revokedAt): array
    {
        throw new \LogicException('IssueOAuthTokenPair never revokes.');
    }

    public function organizationOfToken(#[\SensitiveParameter] string $tokenHash): ?OrganizationId
    {
        throw new \LogicException('IssueOAuthTokenPair never resolves a token.');
    }
}
