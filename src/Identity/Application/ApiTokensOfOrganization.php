<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Api\OrganizationApiToken;
use App\Identity\Api\OrganizationApiTokens;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokens;
use App\SharedKernel\Domain\UserId;

final readonly class ApiTokensOfOrganization implements OrganizationApiTokens
{
    public function __construct(private ApiTokens $tokens, private RevokeApiToken $revokeApiToken)
    {
    }

    public function livePage(\DateTimeImmutable $now, int $limit, ?\DateTimeImmutable $afterExpiresAt = null, ?string $afterId = null): array
    {
        if ($limit < 1 || $limit > self::MAX_PAGE_SIZE) {
            throw new \InvalidArgumentException(\sprintf('A page of tokens holds 1 to %d.', self::MAX_PAGE_SIZE));
        }

        return array_map(
            static fn (ApiToken $token): OrganizationApiToken => new OrganizationApiToken($token->id(), $token->userId(), $token->name(), $token->prefix(), $token->expiresAt()),
            $this->tokens->livePage($now, $limit, $afterExpiresAt, $afterId),
        );
    }

    public function revokeAsAdmin(UserId $adminId, string $tokenId, \DateTimeImmutable $now): bool
    {
        return $this->revokeApiToken->asAdmin($adminId, $tokenId, $now);
    }
}
