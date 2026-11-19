<?php

declare(strict_types=1);

namespace App\Tests\Integration\AgentApi\Infrastructure\Rest;

use App\AgentApi\Infrastructure\Rest\IdempotencyKeys;
use App\AgentApi\Infrastructure\Rest\StoredAnswer;
use App\Identity\Api\ApiTokenAccess;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * IdempotencyKeys on the real api_idempotency_key as pinround_app, under row-level security. Every time is a fixed one
 * in March 2027; nothing reads the clock. Each test commits its keys, so what another transaction wrote is what is read
 * back, and removes them through the migrator.
 */
final class IdempotencyKeysTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000dd5001';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000dd5002';
    private const string MIAS_TOKEN = '0197a3c0-0000-7000-8000-000000dd5011';
    private const string OMARS_TOKEN = '0197a3c0-0000-7000-8000-000000dd5012';
    private const string KEY = 'report-1';
    private const string AT = '2027-03-15 11:20:30+01:00';

    protected function setUp(): void
    {
        $this->removeKeys();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->removeKeys();
        parent::tearDown();
    }

    #[Test]
    public function theSameKeyForTwoTokensIsTwoReservationsThatEachReplayTheirOwnAnswer(): void
    {
        $mia = self::access(self::MIAS_TOKEN, self::ACME_FOODS);
        $omar = self::access(self::OMARS_TOKEN, self::ACME_FOODS);
        $hash = self::requestHash('POST /api/v1/reports');

        $reserved = $this->transactions()->committedAs(self::ACME_FOODS, fn (): array => [
            'Mia' => $this->keys()->reserve($mia, self::KEY, $hash, new \DateTimeImmutable(self::AT)),
            'Omar' => $this->keys()->reserve($omar, self::KEY, $hash, new \DateTimeImmutable(self::AT)),
        ]);
        self::assertSame(['Mia' => null, 'Omar' => null], $reserved);
        $this->transactions()->committedAs(self::ACME_FOODS, function () use ($mia, $omar): void {
            $this->keys()->answer($mia, self::KEY, 201, '{"draft_id":"mias"}');
            $this->keys()->answer($omar, self::KEY, 404, '{"outcome":"not_found"}');
        });

        $replayed = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
            'Mia' => $this->keys()->reserve($mia, self::KEY, $hash, new \DateTimeImmutable(self::AT)),
            'Omar' => $this->keys()->reserve($omar, self::KEY, $hash, new \DateTimeImmutable(self::AT)),
        ]);

        self::assertEquals(['Mia' => new StoredAnswer(201, '{"draft_id":"mias"}'), 'Omar' => new StoredAnswer(404, '{"outcome":"not_found"}')], $replayed);
        self::assertSame([[self::MIAS_TOKEN, 201], [self::OMARS_TOKEN, 404]], $this->storedKeysOf(self::ACME_FOODS));
    }

    /**
     * The token id and key are the same in both organizations, so only row-level security tells the rows apart.
     */
    #[Test]
    public function aKeyReservedUnderOneOrganizationIsNotSeenUnderAnotherWhereTheSameKeyTakesANewRow(): void
    {
        $hash = self::requestHash('POST /api/v1/reports');
        $this->transactions()->committedAs(self::ACME_FOODS, function () use ($hash): void {
            $acmeFoodsMia = self::access(self::MIAS_TOKEN, self::ACME_FOODS);
            self::assertNull($this->keys()->reserve($acmeFoodsMia, self::KEY, $hash, new \DateTimeImmutable(self::AT)));
            $this->keys()->answer($acmeFoodsMia, self::KEY, 201, '{"draft_id":"acme"}');
        });

        $seenUnderBetaBakery = $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): array => $this->appConnection()->fetchAllNumeric('SELECT organization_id, token_id, key FROM api_idempotency_key'));
        $reservedUnderBetaBakery = $this->transactions()->committedAs(self::BETA_BAKERY, fn (): ?StoredAnswer => $this->keys()->reserve(self::access(self::MIAS_TOKEN, self::BETA_BAKERY), self::KEY, $hash, new \DateTimeImmutable(self::AT)));

        self::assertSame([], $seenUnderBetaBakery);
        self::assertNull($reservedUnderBetaBakery, 'Acme Foods\' answer was replayed under Beta Bakery.');
        self::assertSame([[self::MIAS_TOKEN, 201]], $this->storedKeysOf(self::ACME_FOODS));
        self::assertSame([[self::MIAS_TOKEN, null]], $this->storedKeysOf(self::BETA_BAKERY));
    }

    #[Test]
    public function answeringAKeyThatWasNeverReservedThrowsAndWritesNothing(): void
    {
        $mia = self::access(self::MIAS_TOKEN, self::ACME_FOODS);

        try {
            $this->transactions()->committedAs(self::ACME_FOODS, function () use ($mia): void {
                $this->keys()->answer($mia, self::KEY, 201, '{"draft_id":"mias"}');
            });
            self::fail('An answer to a key never reserved was taken.');
        } catch (\LogicException $refusal) {
            self::assertSame('The idempotency key to answer was not reserved by this request.', $refusal->getMessage());
        }

        self::assertSame([], $this->storedKeysOf(self::ACME_FOODS));
    }

    #[Test]
    public function aReservationRolledBackWithItsRequestCanBeTakenAgain(): void
    {
        $mia = self::access(self::MIAS_TOKEN, self::ACME_FOODS);
        $hash = self::requestHash('POST /api/v1/reports');

        $first = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?StoredAnswer => $this->keys()->reserve($mia, self::KEY, $hash, new \DateTimeImmutable(self::AT)));
        self::assertNull($first);
        self::assertSame([], $this->storedKeysOf(self::ACME_FOODS));

        $second = $this->transactions()->committedAs(self::ACME_FOODS, function () use ($mia, $hash): ?StoredAnswer {
            $taken = $this->keys()->reserve($mia, self::KEY, $hash, new \DateTimeImmutable(self::AT));
            $this->keys()->answer($mia, self::KEY, 201, '{"draft_id":"mias"}');

            return $taken;
        });

        self::assertNull($second);
        self::assertSame([[self::MIAS_TOKEN, 201]], $this->storedKeysOf(self::ACME_FOODS));
    }

    private static function access(string $tokenId, string $organizationId): ApiTokenAccess
    {
        return new ApiTokenAccess($tokenId, new OrganizationId($organizationId), ['reports:write'], new \DateTimeImmutable('2027-06-13 10:00:00 UTC'));
    }

    private static function requestHash(string $request): string
    {
        return hash('sha256', $request, true);
    }

    /**
     * @return list<list<mixed>> each key's token and stored status, read by the migrator, by token
     */
    private function storedKeysOf(string $organizationId): array
    {
        $rows = [];
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set(new OrganizationId($organizationId));
        try {
            $this->migrator()->transactional(static function (Connection $migrator) use ($organizationId, &$rows): void {
                $rows = $migrator->fetchAllNumeric('SELECT token_id, status FROM api_idempotency_key WHERE organization_id = ? AND key = ? ORDER BY token_id', [$organizationId, self::KEY]);
            });
        } finally {
            $tenantContext->clear();
        }

        return $rows;
    }

    private function removeKeys(): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            $tenantContext->set(new OrganizationId($organizationId));
            try {
                $this->migrator()->transactional(static fn (Connection $connection): int|string => $connection->executeStatement('DELETE FROM api_idempotency_key WHERE organization_id = ?', [$organizationId]));
            } finally {
                $tenantContext->clear();
            }
        }
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private function appConnection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function keys(): IdempotencyKeys
    {
        return new IdempotencyKeys($this->appConnection(), self::getContainer()->get(TenantContext::class));
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), self::getContainer()->get(TenantContext::class));
    }
}
