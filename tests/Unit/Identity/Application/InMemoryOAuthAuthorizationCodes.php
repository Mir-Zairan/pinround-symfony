<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Domain\OAuthAuthorizationCode;
use App\Identity\Domain\OAuthAuthorizationCodes;
use App\SharedKernel\Domain\OrganizationId;
use Symfony\Component\Uid\Uuid;

/**
 * Codes as idn_oauth_authorization_code keeps them: (organization_id, id) the primary key and code_hash unique across
 * all organizations. Only the authorize step uses it, which never trades or resolves a code.
 */
final class InMemoryOAuthAuthorizationCodes implements OAuthAuthorizationCodes
{
    /** @var list<OAuthAuthorizationCode> */
    private array $rows = [];

    public function nextIdentity(): string
    {
        return Uuid::v7()->toRfc4122();
    }

    public function add(OAuthAuthorizationCode $code): void
    {
        foreach ($this->rows as $row) {
            if ($row->organizationId()->equals($code->organizationId()) && $row->id() === $code->id()) {
                throw new \LogicException('duplicate key value violates unique constraint "idn_oauth_authorization_code_pkey"');
            }
            if ($row->codeHash() === $code->codeHash()) {
                throw new \LogicException('duplicate key value violates unique constraint "idn_oauth_authorization_code_code_hash_key"');
            }
        }
        $this->rows[] = $code;
    }

    public function consume(#[\SensitiveParameter] string $codeHash, \DateTimeImmutable $at): ?OAuthAuthorizationCode
    {
        throw new \LogicException('The authorize step never trades a code.');
    }

    public function idOfUsedCode(#[\SensitiveParameter] string $codeHash): ?string
    {
        throw new \LogicException('The authorize step never trades a code.');
    }

    public function organizationOfCode(#[\SensitiveParameter] string $codeHash): ?OrganizationId
    {
        throw new \LogicException('The authorize step never resolves a code.');
    }

    /**
     * @return list<OAuthAuthorizationCode> every saved code, in the order added
     */
    public function all(): array
    {
        return $this->rows;
    }
}
