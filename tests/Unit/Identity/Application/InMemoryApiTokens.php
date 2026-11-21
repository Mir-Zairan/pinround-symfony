<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenOwner;
use App\Identity\Domain\ApiTokens;
use App\Identity\Domain\ExpiringApiToken;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Symfony\Component\Uid\Uuid;

/**
 * Tokens of every organization as idn_api_token keeps them, read in one transaction under $tenant as DoctrineApiTokens
 * reads them: only the tenant's rows, (organization, id) and the hash unique, live counted by the given time, a revoke
 * and an expiry warning written once, and a token read as stored, its revocation, last use and warning included, as a
 * fresh entity manager loads it. Stricter than the real one in one way: either count refuses unless lockIssuingFor() took that
 * person's lock earlier in the transaction, so a count read ahead of the lock fails the test instead of passing.
 */
final class InMemoryApiTokens implements ApiTokens
{
    /** @var array<string, ApiToken> by rowKey() */
    private array $rows = [];

    /** @var array<string, \DateTimeImmutable> by rowKey() */
    private array $revokedAt = [];

    /** @var array<string, \DateTimeImmutable> by rowKey() */
    private array $lastUsedAt = [];

    /** @var array<string, \DateTimeImmutable> by rowKey() */
    private array $expiryWarnedAt = [];

    /** @var array<string, true> by lockKey(), each issuing lock held in this transaction */
    private array $heldIssuingLocks = [];

    private int $issuedIds = 0;

    /** @var list<string> the calls of lockIssuingFor, the two counts and add, in order */
    public array $calls = [];

    /**
     * @param \DateTimeImmutable $databaseNow the database clock ownerOfLiveToken() reads, as resolve_api_token does
     */
    public function __construct(
        private readonly ?OrganizationId $tenant,
        private readonly \DateTimeImmutable $databaseNow,
        private readonly bool $isTransactionOpen = true,
    ) {
    }

    /**
     * A committed row, of any organization, as another transaction left it.
     */
    public function seed(ApiToken $token, ?\DateTimeImmutable $revokedAt = null): void
    {
        $this->insert($token);
        if (null !== $revokedAt) {
            $this->revokedAt[self::rowKey($token->organizationId(), $token->id())] = $revokedAt;
        }
    }

    /**
     * @return list<string> the ids of every organization's tokens, in id order
     */
    public function ids(): array
    {
        $ids = array_map(static fn (ApiToken $token): string => $token->id(), array_values($this->rows));
        sort($ids);

        return $ids;
    }

    public function nextIdentity(): string
    {
        return \sprintf('0197a3c0-0000-7000-8000-%012d', ++$this->issuedIds);
    }

    public function add(ApiToken $token): void
    {
        $this->calls[] = 'add';
        if (!$this->requireTenant()->equals($token->organizationId())) {
            throw new \LogicException('new row violates row-level security policy for table "idn_api_token"');
        }
        $this->insert($token);
    }

    public function lockIssuingFor(OrganizationId $organizationId, UserId $userId): void
    {
        $this->calls[] = 'lockIssuingFor';
        if (!$this->isTransactionOpen) {
            throw new \LogicException('Issuing a token needs an open transaction; none is open.');
        }
        if (!$this->requireTenant()->equals($organizationId)) {
            throw new \LogicException('A token is issued under its own organization as tenant.');
        }
        $this->heldIssuingLocks[self::lockKey($organizationId, $userId)] = true;
    }

    public function countLiveOf(UserId $userId, \DateTimeImmutable $at): int
    {
        $this->calls[] = 'countLiveOf';

        return \count($this->liveOfUnderLock($userId, $at));
    }

    public function countLivePersonalOf(UserId $userId, \DateTimeImmutable $at): int
    {
        $this->calls[] = 'countLivePersonalOf';

        return \count(array_filter($this->liveOfUnderLock($userId, $at), self::isPersonal(...)));
    }

    public function byIdForOwner(string $id, UserId $ownerId): ?ApiToken
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $token = $this->tenantTokens()[strtolower($id)] ?? null;

        return null !== $token && $token->userId()->equals($ownerId) ? $token : null;
    }

    public function byId(string $id): ?ApiToken
    {
        $tokens = $this->tenantTokens();
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $tokens[strtolower($id)] ?? null;
    }

    public function livePage(\DateTimeImmutable $at, int $limit, ?\DateTimeImmutable $afterExpiresAt = null, ?string $afterId = null): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('A page of tokens holds at least one.');
        }
        if ((null === $afterExpiresAt) !== (null === $afterId) || (null !== $afterId && !Uuid::isValid($afterId))) {
            throw new \InvalidArgumentException('A page of tokens starts after both the expiry and the id of a token.');
        }
        $after = null === $afterExpiresAt || null === $afterId ? null : [self::cutToSecond($afterExpiresAt), strtolower($afterId)];
        $tokens = array_values(array_filter(
            $this->tenantTokens(),
            fn (ApiToken $token): bool => $this->isLive($token, self::cutToSecond($at)) && self::isPersonal($token)
                && (null === $after || [self::cutToSecond($token->expiresAt()), strtolower($token->id())] > $after),
        ));
        usort($tokens, static fn (ApiToken $a, ApiToken $b): int => [self::cutToSecond($a->expiresAt()), strtolower($a->id())] <=> [self::cutToSecond($b->expiresAt()), strtolower($b->id())]);

        return \array_slice($tokens, 0, $limit);
    }

    public function forUser(UserId $userId): array
    {
        $tokens = array_values(array_filter($this->tenantTokens(), static fn (ApiToken $token): bool => $token->userId()->equals($userId) && self::isPersonal($token)));
        usort($tokens, self::newestFirst(...));

        return \array_slice($tokens, 0, self::MAX_LISTED_TOKENS);
    }

    public function liveForUser(UserId $userId, \DateTimeImmutable $at): array
    {
        $tokens = array_values(array_filter(
            $this->tenantTokens(),
            fn (ApiToken $token): bool => $token->userId()->equals($userId) && $this->isLive($token, self::cutToSecond($at)) && self::isPersonal($token),
        ));
        usort($tokens, self::newestFirst(...));

        return \array_slice($tokens, 0, self::MAX_LISTED_TOKENS);
    }

    public function revoke(string $id, \DateTimeImmutable $revokedAt): bool
    {
        $rowKey = self::rowKey($this->requireTenant(), $id);
        if (!Uuid::isValid($id) || !isset($this->rows[$rowKey]) || isset($this->revokedAt[$rowKey])) {
            return false;
        }
        $this->revokedAt[$rowKey] = $revokedAt;

        return true;
    }

    public function markUsed(string $id, \DateTimeImmutable $usedAt): bool
    {
        $rowKey = self::rowKey($this->requireTenant(), $id);
        if (!Uuid::isValid($id) || !isset($this->rows[$rowKey])) {
            return false;
        }
        $lastUsedAt = $this->lastUsedAt[$rowKey] ?? null;
        if (null !== $lastUsedAt && $lastUsedAt >= $usedAt->modify('-1 minute')) {
            return false;
        }
        $this->lastUsedAt[$rowKey] = $usedAt;

        return true;
    }

    public function claimExpiryWarnings(\DateTimeImmutable $expiresFrom, \DateTimeImmutable $expiresBefore, \DateTimeImmutable $warnedAt, int $limit): array
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('A claim of expiry warnings takes at least one token.');
        }
        if ($expiresBefore <= $expiresFrom) {
            throw new \InvalidArgumentException('The expiry range of a claim ends after it starts.');
        }
        $from = self::cutToSecond($expiresFrom);
        $before = self::cutToSecond($expiresBefore);
        $claimable = array_values(array_filter(
            $this->tenantTokens(),
            fn (ApiToken $token): bool => !isset($this->revokedAt[self::rowKey($token->organizationId(), $token->id())])
                && !isset($this->expiryWarnedAt[self::rowKey($token->organizationId(), $token->id())])
                && self::cutToSecond($token->expiresAt()) >= $from
                && self::cutToSecond($token->expiresAt()) < $before
                && self::cutToSecond($token->expiresAt())->getTimestamp() - self::cutToSecond($token->createdAt())->getTimestamp() > ApiToken::EXPIRY_WARNING_WINDOW_DAYS * 86_400,
        ));
        usort($claimable, static fn (ApiToken $a, ApiToken $b): int => [self::cutToSecond($a->expiresAt()), strtolower($a->id())] <=> [self::cutToSecond($b->expiresAt()), strtolower($b->id())]);

        $claimed = [];
        foreach (\array_slice($claimable, 0, $limit) as $token) {
            $this->expiryWarnedAt[self::rowKey($token->organizationId(), $token->id())] = $warnedAt;
            $claimed[] = new ExpiringApiToken($token->id(), $token->userId(), $token->name(), $token->prefix(), self::cutToSecond($token->expiresAt()));
        }

        return $claimed;
    }

    public function ownerOfLiveToken(string $tokenHash): ?ApiTokenOwner
    {
        foreach ($this->rows as $token) {
            if (hash_equals($token->tokenHash(), $tokenHash) && $this->isLive($token, $this->databaseNow)) {
                return new ApiTokenOwner($token->id(), $token->organizationId(), $token->userId());
            }
        }

        return null;
    }

    private function insert(ApiToken $token): void
    {
        $rowKey = self::rowKey($token->organizationId(), $token->id());
        foreach ($this->rows as $keptKey => $kept) {
            if ($keptKey === $rowKey || $kept->tokenHash() === $token->tokenHash()) {
                throw new \LogicException('duplicate key value violates unique constraint of idn_api_token');
            }
        }
        $this->rows[$rowKey] = $token;
    }

    /**
     * @return list<ApiToken> the user's live tokens in the tenant
     */
    private function liveOfUnderLock(UserId $userId, \DateTimeImmutable $at): array
    {
        $tenant = $this->requireTenant();
        if (!isset($this->heldIssuingLocks[self::lockKey($tenant, $userId)])) {
            throw new \LogicException('Live tokens were counted before the issuing lock of the person was taken.');
        }

        return array_values(array_filter(
            $this->tenantTokens(),
            fn (ApiToken $token): bool => $token->userId()->equals($userId) && $this->isLive($token, self::cutToSecond($at)),
        ));
    }

    /**
     * As DoctrineApiTokens tells the kinds apart: by the stored times, each to the second.
     */
    private static function isPersonal(ApiToken $token): bool
    {
        return self::cutToSecond($token->expiresAt())->getTimestamp() - self::cutToSecond($token->createdAt())->getTimestamp() > ApiToken::OAUTH_ACCESS_LIFETIME_SECONDS;
    }

    private function isLive(ApiToken $token, \DateTimeImmutable $at): bool
    {
        return !isset($this->revokedAt[self::rowKey($token->organizationId(), $token->id())]) && self::cutToSecond($token->expiresAt()) > $at;
    }

    /**
     * By created_at, then the later id, as stored: a uuid column sorts as its lowercase text does.
     */
    private static function newestFirst(ApiToken $a, ApiToken $b): int
    {
        return [self::cutToSecond($b->createdAt()), strtolower($b->id())] <=> [self::cutToSecond($a->createdAt()), strtolower($a->id())];
    }

    /**
     * As idn_api_token keeps a time and DoctrineApiTokens sends one: Doctrine's datetimetz format and the
     * TIMESTAMP_FORMAT of DoctrineApiTokens both leave out the fraction of a second.
     */
    private static function cutToSecond(\DateTimeImmutable $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@'.$time->getTimestamp());
    }

    /**
     * @return array<string, ApiToken> the tenant's tokens by id
     */
    private function tenantTokens(): array
    {
        $tenant = $this->requireTenant();
        $tokens = [];
        foreach ($this->rows as $token) {
            if ($token->organizationId()->equals($tenant)) {
                $tokens[$token->id()] = $this->asStored($token);
            }
        }

        return $tokens;
    }

    /**
     * The token as Doctrine hydrates its row: the columns only the database writes filled in, each to the second.
     */
    private function asStored(ApiToken $token): ApiToken
    {
        $rowKey = self::rowKey($token->organizationId(), $token->id());
        $writtenInTheDatabase = array_filter([
            'revokedAt' => $this->revokedAt[$rowKey] ?? null,
            'lastUsedAt' => $this->lastUsedAt[$rowKey] ?? null,
            'expiryWarnedAt' => $this->expiryWarnedAt[$rowKey] ?? null,
        ]);
        if ([] === $writtenInTheDatabase) {
            return $token;
        }
        $loaded = clone $token;
        foreach ($writtenInTheDatabase as $property => $writtenAt) {
            new \ReflectionProperty(ApiToken::class, $property)->setValue($loaded, self::cutToSecond($writtenAt));
        }

        return $loaded;
    }

    private function requireTenant(): OrganizationId
    {
        return $this->tenant ?? throw new TenantNotSet();
    }

    private static function rowKey(OrganizationId $organizationId, string $id): string
    {
        return $organizationId->toString().':'.strtolower($id);
    }

    private static function lockKey(OrganizationId $organizationId, UserId $userId): string
    {
        return $organizationId->toString().':'.$userId->toString();
    }
}
