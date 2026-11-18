<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenScope;

/**
 * One personal access token as the tokens page lists it: never its hash or the token itself.
 */
final readonly class ApiTokenView
{
    /**
     * @param string              $prefix     "pr_" and the first 8 characters of the random part, to tell tokens apart
     * @param list<string>        $scopeNames in the order of ApiTokenScope's cases
     * @param ?\DateTimeImmutable $lastUsedAt to the minute at best; null when never used
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $prefix,
        public array $scopeNames,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $lastUsedAt,
        public \DateTimeImmutable $expiresAt,
    ) {
    }

    public static function of(ApiToken $token): self
    {
        return new self(
            $token->id(),
            $token->name(),
            $token->prefix(),
            array_map(static fn (ApiTokenScope $scope): string => $scope->value, $token->scopes()),
            $token->createdAt(),
            $token->lastUsedAt(),
            $token->expiresAt(),
        );
    }
}
