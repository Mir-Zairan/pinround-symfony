<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity\Infrastructure\Doctrine;

use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokens;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\ExpiringApiToken;
use App\Identity\Infrastructure\Doctrine\DoctrineApiTokens;
use App\Identity\Infrastructure\Security\HmacApiTokenHashing;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DoctrineApiTokens on the real idn_api_token table as pinround_app. The tokens are committed in two organizations, as
 * the resolver and the tenant checks need rows another transaction can see, and removed through the migrator. Omar is a
 * member of both organizations and Lena of Acme Foods, as resolve_api_token finds only a member's token. Every time is
 * fixed; the live tokens are dated ahead of the database clock that resolve_api_token reads.
 */
final class DoctrineApiTokensTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000ca1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000000cb1';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000cd1';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000cd2';
    private const string OMAR_LIVE = '0197a3c0-0000-7000-8000-000000000ce1';
    private const string OMAR_SAME_SECOND = '0197a3c0-0000-7000-8000-000000000ce2';
    private const string OMAR_OLDER = '0197a3c0-0000-7000-8000-000000000ce3';
    private const string OMAR_EXPIRED = '0197a3c0-0000-7000-8000-000000000ce4';
    private const string LENA_AT_ACME = '0197a3c0-0000-7000-8000-000000000ce5';
    private const string OMAR_AT_BETA = '0197a3c0-0000-7000-8000-000000000ce6';
    private const string OMAR_REVOKED = '0197a3c0-0000-7000-8000-000000000ce7';
    private const string OMAR_WITHIN_A_SECOND = '0197a3c0-0000-7000-8000-000000000ce8';
    private const string OMAR_NOTEBOOKS = '0197a3c0-0000-7000-8000-000000000ce9';
    private const string OMAR_REFUSED_SCOPE = '0197a3c0-0000-7000-8000-000000000cea';
    private const string LENA_AT_BETA = '0197a3c0-0000-7000-8000-000000000ceb';
    private const string PEPPER = 'integration-test-pepper-of-32-bytes-or-more';
    private const string ISSUED_AT = '2099-03-15 10:00:00 UTC';

    /** @var array<string, string> token id => the plain token, shown once at issue */
    private array $plainTokens = [];

    protected function setUp(): void
    {
        $this->removeTokensOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->removeMembershipsOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $this->addMembership(self::ACME_FOODS, self::OMAR);
            $this->addMembership(self::ACME_FOODS, self::LENA);
        });
        $this->transactions()->committedAs(self::BETA_BAKERY, fn () => $this->addMembership(self::BETA_BAKERY, self::OMAR));
        $issuedAt = new \DateTimeImmutable(self::ISSUED_AT);
        // Added out of order, so the list order cannot come from the insert order.
        $this->transactions()->committedAs(self::ACME_FOODS, function () use ($issuedAt): void {
            $this->add(self::OMAR_SAME_SECOND, self::ACME_FOODS, self::OMAR, $issuedAt);
            $this->add(self::OMAR_LIVE, self::ACME_FOODS, self::OMAR, $issuedAt);
            $this->add(self::LENA_AT_ACME, self::ACME_FOODS, self::LENA, $issuedAt->modify('+1 day'));
            $this->add(self::OMAR_EXPIRED, self::ACME_FOODS, self::OMAR, new \DateTimeImmutable('2020-01-01 10:00:00 UTC'), 1);
            $this->add(self::OMAR_OLDER, self::ACME_FOODS, self::OMAR, $issuedAt->modify('-1 day'));
        });
        $this->transactions()->committedAs(self::BETA_BAKERY, fn () => $this->add(self::OMAR_AT_BETA, self::BETA_BAKERY, self::OMAR, $issuedAt->modify('+2 days')));
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->removeTokensOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->removeMembershipsOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function theRowHoldsThePepperedHashAndNeverThePlainToken(): void
    {
        $plainToken = $this->plainTokens[self::OMAR_LIVE];

        $row = $this->asAcmeFoods(fn (): array|false => $this->connection()->fetchAssociative(
            "SELECT token_hash, prefix, scopes::text AS scopes, user_id::text AS user_id, name,
                    to_char(expires_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') AS expires_at, last_used_at, revoked_at,
                    row_to_json(t)::text AS every_column
             FROM idn_api_token t WHERE id = ?",
            [self::OMAR_LIVE],
        ));

        self::assertIsArray($row);
        $storedHash = \is_resource($row['token_hash']) ? stream_get_contents($row['token_hash']) : $row['token_hash'];
        self::assertSame(hash_hmac('sha256', $plainToken, self::PEPPER, true), $storedHash);
        self::assertSame(substr($plainToken, 0, 11), $row['prefix']);
        self::assertSame('{read,rounds:write}', $row['scopes']);
        self::assertSame(self::OMAR, $row['user_id']);
        self::assertSame('Claude Code', $row['name']);
        self::assertSame('2099-06-13 10:00:00', $row['expires_at']);
        self::assertNull($row['last_used_at']);
        self::assertNull($row['revoked_at']);
        self::assertIsString($row['every_column']);
        self::assertStringNotContainsString(substr($plainToken, 3), $row['every_column']);
    }

    #[Test]
    public function aTokenIsFoundByIdOnlyForItsOwnerInItsOrganization(): void
    {
        $this->asAcmeFoods(function (): void {
            $tokens = $this->tokens();
            $found = $tokens->byIdForOwner(self::OMAR_LIVE, new UserId(self::OMAR));

            self::assertNotNull($found);
            self::assertSame(self::OMAR_LIVE, $found->id());
            self::assertSame($this->hashOf(self::OMAR_LIVE), $found->tokenHash());
            self::assertSame([ApiTokenScope::Read, ApiTokenScope::RoundsWrite], $found->scopes());
            self::assertSame(self::OMAR_LIVE, $tokens->byIdForOwner(strtoupper(self::OMAR_LIVE), new UserId(self::OMAR))?->id());
            self::assertNull($tokens->byIdForOwner(self::OMAR_LIVE, new UserId(self::LENA)));
            self::assertNull($tokens->byIdForOwner(self::OMAR_AT_BETA, new UserId(self::OMAR)));
            self::assertNull($tokens->byIdForOwner('0197a3c0-0000-7000-8000-000000000cff', new UserId(self::OMAR)));
            self::assertNull($tokens->byIdForOwner('not-a-token-id', new UserId(self::OMAR)));
        });
    }

    #[Test]
    public function theUsersTokensOfTheOrganizationAreListedNewestFirstWithTheLaterIdFirstInOneSecond(): void
    {
        $listed = $this->asAcmeFoods(fn (): array => array_map(
            static fn (ApiToken $token): string => $token->id(),
            $this->tokens()->forUser(new UserId(self::OMAR)),
        ));

        self::assertSame([self::OMAR_SAME_SECOND, self::OMAR_LIVE, self::OMAR_OLDER, self::OMAR_EXPIRED], $listed);
    }

    /**
     * 49 more of Omar's tokens, 1.5 to 49.5 hours before ISSUED_AT, added in a shuffled order: with the four of setUp
     * that is 53, and the three oldest (49.5 and 48.5 hours back, and the one of 2020) are left out.
     */
    #[Test]
    public function onlyTheNewestFiftyTokensAreListedNewestFirst(): void
    {
        $issuedAt = new \DateTimeImmutable(self::ISSUED_AT);
        $this->transactions()->committedAs(self::ACME_FOODS, function () use ($issuedAt): void {
            for ($i = 0; $i < 49; ++$i) {
                $hoursBack = ($i * 17) % 49 + 1;
                $this->add(self::extraTokenId($hoursBack), self::ACME_FOODS, self::OMAR, $issuedAt->modify(\sprintf('-%d minutes', $hoursBack * 60 + 30)));
            }
        });

        $listed = $this->asAcmeFoods(fn (): array => array_map(
            static fn (ApiToken $token): string => $token->id(),
            $this->tokens()->forUser(new UserId(self::OMAR)),
        ));

        self::assertCount(ApiTokens::MAX_LISTED_TOKENS, $listed);
        self::assertSame(
            [
                self::OMAR_SAME_SECOND,
                self::OMAR_LIVE,
                ...array_map(self::extraTokenId(...), range(1, 23)),
                self::OMAR_OLDER,
                ...array_map(self::extraTokenId(...), range(24, 47)),
            ],
            $listed,
        );
    }

    /**
     * The revoked token is the newest, so a revoked one counted in the list would show. Lena's, the expired one and
     * Beta Bakery's are seeded in setUp().
     */
    #[Test]
    public function theUsersLiveTokensOfTheOrganizationAreListedNewestFirstWithTheLaterIdFirstInOneSecond(): void
    {
        $issuedAt = new \DateTimeImmutable(self::ISSUED_AT);
        $this->transactions()->committedAs(self::ACME_FOODS, function () use ($issuedAt): void {
            $this->add(self::OMAR_REVOKED, self::ACME_FOODS, self::OMAR, $issuedAt->modify('+1 hour'));
            self::assertTrue($this->tokens()->revoke(self::OMAR_REVOKED, $issuedAt->modify('+2 hours')));
        });

        $listed = $this->asAcmeFoods(fn (): array => $this->liveIdsOf(self::OMAR, $issuedAt->modify('+3 hours')));
        $listedAtBeta = $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): array => $this->liveIdsOf(self::OMAR, $issuedAt->modify('+3 hours')));

        self::assertSame([self::OMAR_SAME_SECOND, self::OMAR_LIVE, self::OMAR_OLDER], $listed);
        self::assertSame([self::OMAR_AT_BETA], $listedAtBeta);
        self::assertSame([self::LENA_AT_ACME], $this->asAcmeFoods(fn (): array => $this->liveIdsOf(self::LENA, $issuedAt)));
    }

    /**
     * expires_at keeps whole seconds and $at is cut to its second, so a token is listed until the second it expires
     * starts, as resolve_api_token stops it then.
     */
    #[Test]
    public function aTokenIsLiveUntilTheSecondItExpiresAndNotAtThatSecond(): void
    {
        // OMAR_OLDER lasts 90 days from a day before ISSUED_AT, so the others outlive it.
        $expiresAt = new \DateTimeImmutable(self::ISSUED_AT)->modify('-1 day +90 days');

        $listedAt = fn (string $at): array => $this->asAcmeFoods(fn (): array => $this->liveIdsOf(self::OMAR, new \DateTimeImmutable($at)));

        self::assertSame('2099-06-12 10:00:00', $expiresAt->format('Y-m-d H:i:s'));
        self::assertSame([self::OMAR_SAME_SECOND, self::OMAR_LIVE, self::OMAR_OLDER], $listedAt('2099-06-12 09:59:59 UTC'));
        self::assertSame([self::OMAR_SAME_SECOND, self::OMAR_LIVE, self::OMAR_OLDER], $listedAt('2099-06-12 09:59:59.600 UTC'));
        self::assertSame([self::OMAR_SAME_SECOND, self::OMAR_LIVE], $listedAt('2099-06-12 10:00:00 UTC'));
        self::assertSame([self::OMAR_SAME_SECOND, self::OMAR_LIVE], $listedAt('2099-06-12 10:00:00.400 UTC'));
        self::assertSame([], $listedAt('2099-06-13 10:00:00 UTC'));
    }

    /**
     * A token issued within a second is stored at the start of that second, as Doctrine sends a time without its
     * fraction: it expires at that start, a day on, and lists before tokens of the same second with a lower id.
     */
    #[Test]
    public function aTokenIssuedWithinASecondCountsFromTheStartOfThatSecond(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->add(self::OMAR_WITHIN_A_SECOND, self::ACME_FOODS, self::OMAR, new \DateTimeImmutable('2099-03-15 10:00:00.700 UTC'), 1));

        $listedAt = fn (string $at): array => $this->asAcmeFoods(fn (): array => $this->liveIdsOf(self::OMAR, new \DateTimeImmutable($at)));

        self::assertSame(
            ['2099-03-15 10:00:00', '2099-03-16 10:00:00'],
            $this->asAcmeFoods(fn (): array => [$this->utcTextOf('created_at', self::OMAR_WITHIN_A_SECOND), $this->utcTextOf('expires_at', self::OMAR_WITHIN_A_SECOND)]),
        );
        self::assertSame([self::OMAR_WITHIN_A_SECOND, self::OMAR_SAME_SECOND, self::OMAR_LIVE, self::OMAR_OLDER], $listedAt('2099-03-16 09:59:59.900 UTC'));
        self::assertSame([self::OMAR_SAME_SECOND, self::OMAR_LIVE, self::OMAR_OLDER], $listedAt('2099-03-16 10:00:00.300 UTC'));
    }

    /**
     * 49 more of Omar's live tokens, as in onlyTheNewestFiftyTokensAreListedNewestFirst(), and OMAR_LIVE revoked: of
     * the 51 live ones the oldest is left out, and the revoked one takes no place in the fifty.
     */
    #[Test]
    public function atMostFiftyLiveTokensAreListedAndARevokedOneTakesNoPlace(): void
    {
        $issuedAt = new \DateTimeImmutable(self::ISSUED_AT);
        $this->transactions()->committedAs(self::ACME_FOODS, function () use ($issuedAt): void {
            for ($i = 0; $i < 49; ++$i) {
                $hoursBack = ($i * 17) % 49 + 1;
                $this->add(self::extraTokenId($hoursBack), self::ACME_FOODS, self::OMAR, $issuedAt->modify(\sprintf('-%d minutes', $hoursBack * 60 + 30)));
            }
            self::assertTrue($this->tokens()->revoke(self::OMAR_LIVE, $issuedAt));
        });

        $listed = $this->asAcmeFoods(fn (): array => $this->liveIdsOf(self::OMAR, $issuedAt));

        self::assertCount(ApiTokens::MAX_LISTED_TOKENS, $listed);
        self::assertSame(
            [
                self::OMAR_SAME_SECOND,
                ...array_map(self::extraTokenId(...), range(1, 23)),
                self::OMAR_OLDER,
                ...array_map(self::extraTokenId(...), range(24, 48)),
            ],
            $listed,
        );
    }

    #[Test]
    public function anyPersonsTokenIsFoundByIdOnlyInItsOrganization(): void
    {
        $found = $this->asAcmeFoods(fn (): array => [
            $this->tokens()->byId(self::LENA_AT_ACME)?->userId()->toString(),
            $this->tokens()->byId(strtoupper(self::OMAR_LIVE))?->id(),
            $this->tokens()->byId(self::OMAR_EXPIRED)?->id(),
            $this->tokens()->byId(self::OMAR_AT_BETA),
            $this->tokens()->byId('0197a3c0-0000-7000-8000-000000000cff'),
            $this->tokens()->byId("not-a-token-id'"),
        ]);

        self::assertSame([self::LENA, self::OMAR_LIVE, self::OMAR_EXPIRED, null, null, null], $found);
        self::assertSame(self::OMAR, $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): ?string => $this->tokens()->byId(self::OMAR_AT_BETA)?->userId()->toString()));
    }

    /**
     * OMAR_LIVE and OMAR_SAME_SECOND expire in the same second, and a page of two ends between them, so only the id in
     * the cursor tells the next page where to start. The revoked token is otherwise live; Lena's is listed with Omar's.
     */
    #[Test]
    public function theOrganizationsLiveTokensArePagedSoonestToExpireFirstAndTheLowerIdFirstOnATie(): void
    {
        $issuedAt = new \DateTimeImmutable(self::ISSUED_AT);
        $this->transactions()->committedAs(self::ACME_FOODS, function () use ($issuedAt): void {
            $this->add(self::OMAR_REVOKED, self::ACME_FOODS, self::OMAR, $issuedAt->modify('+1 hour'));
            self::assertTrue($this->tokens()->revoke(self::OMAR_REVOKED, $issuedAt->modify('+2 hours')));
        });
        $tieExpiresAt = new \DateTimeImmutable('2099-06-13 10:00:00 UTC');
        $lenasExpiresAt = new \DateTimeImmutable('2099-06-14 10:00:00 UTC');

        $pages = $this->asAcmeFoods(fn (): array => [
            $this->pageIdsOf($issuedAt, 2),
            $this->pageIdsOf($issuedAt, 2, $tieExpiresAt, self::OMAR_LIVE),
            $this->pageIdsOf($issuedAt, 2, $lenasExpiresAt, self::LENA_AT_ACME),
            $this->pageIdsOf($issuedAt, 10),
            $this->pageIdsOf(new \DateTimeImmutable('2099-06-12 10:00:00 UTC'), 10),
        ]);
        $betaPage = $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): array => $this->pageIdsOf($issuedAt, 10));

        self::assertSame(
            [
                [self::OMAR_OLDER, self::OMAR_LIVE],
                [self::OMAR_SAME_SECOND, self::LENA_AT_ACME],
                [],
                [self::OMAR_OLDER, self::OMAR_LIVE, self::OMAR_SAME_SECOND, self::LENA_AT_ACME],
                [self::OMAR_LIVE, self::OMAR_SAME_SECOND, self::LENA_AT_ACME],
            ],
            $pages,
        );
        self::assertSame([self::OMAR_AT_BETA], $betaPage);
    }

    /**
     * @return iterable<string, array{int, ?string, ?string}>
     */
    public static function pagesThatAreNotValid(): iterable
    {
        yield 'no token' => [0, null, null];
        yield 'the expiry only' => [2, '2099-06-13 10:00:00 UTC', null];
        yield 'the id only' => [2, null, self::OMAR_LIVE];
        yield 'an id that is no uuid' => [2, '2099-06-13 10:00:00 UTC', "not-a-token-id'"];
    }

    #[Test]
    #[DataProvider('pagesThatAreNotValid')]
    public function aPageOfNoTokenOrWithHalfACursorIsRefused(int $limit, ?string $afterExpiresAt, ?string $afterId): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->asAcmeFoods(fn (): array => $this->tokens()->livePage(new \DateTimeImmutable(self::ISSUED_AT), $limit, null === $afterExpiresAt ? null : new \DateTimeImmutable($afterExpiresAt), $afterId));
    }

    #[Test]
    public function withoutATenantTheOrganizationsPageAndTheLookUpByIdRefuse(): void
    {
        $refusals = [];
        foreach ([
            'livePage' => fn (): array => $this->tokens()->livePage(new \DateTimeImmutable(self::ISSUED_AT), 10),
            'byId' => fn (): ?ApiToken => $this->tokens()->byId(self::OMAR_LIVE),
        ] as $method => $call) {
            try {
                $call();
            } catch (TenantNotSet) {
                $refusals[] = $method;
            }
        }

        self::assertSame(['livePage', 'byId'], $refusals);
    }

    #[Test]
    public function withoutATenantTheLiveListRefuses(): void
    {
        $this->expectException(TenantNotSet::class);

        $this->tokens()->liveForUser(new UserId(self::OMAR), new \DateTimeImmutable(self::ISSUED_AT));
    }

    #[Test]
    public function aSecondRevokeChangesNothingAndTheFirstTimeStays(): void
    {
        [$first, $second, $revokedAt, $revokedCount] = $this->asAcmeFoods(fn (): array => [
            $this->tokens()->revoke(self::OMAR_LIVE, new \DateTimeImmutable('2099-03-20 09:00:00 UTC')),
            $this->tokens()->revoke(self::OMAR_LIVE, new \DateTimeImmutable('2099-03-21 09:00:00 UTC')),
            $this->utcTextOf('revoked_at', self::OMAR_LIVE),
            $this->connection()->fetchOne('SELECT count(*) FROM idn_api_token WHERE revoked_at IS NOT NULL'),
        ]);

        self::assertTrue($first);
        self::assertFalse($second);
        self::assertSame('2099-03-20 09:00:00', $revokedAt);
        self::assertSame(1, $revokedCount);
    }

    #[Test]
    public function anotherOrganizationsTokenIsNeitherRevokedNorMarkedUsed(): void
    {
        $at = new \DateTimeImmutable('2099-03-20 09:00:00 UTC');

        self::assertSame([false, false], $this->transactions()->committedAs(self::ACME_FOODS, fn (): array => [
            $this->tokens()->revoke(self::OMAR_AT_BETA, $at),
            $this->tokens()->markUsed(self::OMAR_AT_BETA, $at),
        ]));
        self::assertSame(
            ['revoked_at' => null, 'last_used_at' => null],
            $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): array|false => $this->connection()->fetchAssociative(
                'SELECT revoked_at, last_used_at FROM idn_api_token WHERE id = ?',
                [self::OMAR_AT_BETA],
            )),
        );
    }

    #[Test]
    public function anUnknownOrMalformedIdIsNeitherRevokedNorMarkedUsed(): void
    {
        $at = new \DateTimeImmutable('2099-03-20 09:00:00 UTC');

        self::assertSame([false, false, false, false, 0], $this->asAcmeFoods(fn (): array => [
            $this->tokens()->revoke('0197a3c0-0000-7000-8000-000000000cff', $at),
            $this->tokens()->markUsed('0197a3c0-0000-7000-8000-000000000cff', $at),
            $this->tokens()->revoke("not-a-token-id'", $at),
            $this->tokens()->markUsed("not-a-token-id'", $at),
            $this->connection()->fetchOne('SELECT count(*) FROM idn_api_token WHERE revoked_at IS NOT NULL OR last_used_at IS NOT NULL'),
        ]));
    }

    #[Test]
    public function aUseIsWrittenAtMostOnceAMinuteAndAFractionOfASecondIsCutNotRounded(): void
    {
        $firstUse = new \DateTimeImmutable('2099-03-20 09:00:00 UTC');

        $outcomes = $this->asAcmeFoods(fn (): array => [
            'first use' => [$this->tokens()->markUsed(self::OMAR_LIVE, $firstUse), $this->utcTextOf('last_used_at', self::OMAR_LIVE)],
            '59 seconds later' => [$this->tokens()->markUsed(self::OMAR_LIVE, $firstUse->modify('+59 seconds')), $this->utcTextOf('last_used_at', self::OMAR_LIVE)],
            'a minute later' => [$this->tokens()->markUsed(self::OMAR_LIVE, $firstUse->modify('+60 seconds')), $this->utcTextOf('last_used_at', self::OMAR_LIVE)],
            '61.9 seconds later' => [$this->tokens()->markUsed(self::OMAR_LIVE, $firstUse->modify('+61 seconds +900 milliseconds')), $this->utcTextOf('last_used_at', self::OMAR_LIVE)],
            'the other token' => [null, $this->utcTextOf('last_used_at', self::OMAR_SAME_SECOND)],
        ]);

        self::assertSame(
            [
                'first use' => [true, '2099-03-20 09:00:00'],
                '59 seconds later' => [false, '2099-03-20 09:00:00'],
                'a minute later' => [false, '2099-03-20 09:00:00'],
                '61.9 seconds later' => [true, '2099-03-20 09:01:01'],
                'the other token' => [null, null],
            ],
            $outcomes,
        );
    }

    #[Test]
    public function withoutATenantRevokeMarkUsedAndTheLiveCountRefuseAndChangeNothing(): void
    {
        $at = new \DateTimeImmutable('2099-03-20 09:00:00 UTC');
        $refusals = [];
        $calls = [
            'revoke' => fn (): bool => $this->tokens()->revoke(self::OMAR_LIVE, $at),
            'markUsed' => fn (): bool => $this->tokens()->markUsed(self::OMAR_LIVE, $at),
            'countLiveOf' => fn (): int => $this->tokens()->countLiveOf(new UserId(self::OMAR), $at),
        ];
        foreach ($calls as $method => $call) {
            try {
                $call();
            } catch (TenantNotSet) {
                $refusals[] = $method;
            }
        }

        self::assertSame(['revoke', 'markUsed', 'countLiveOf'], $refusals);
        self::assertSame(
            ['revoked_at' => null, 'last_used_at' => null],
            $this->asAcmeFoods(fn (): array|false => $this->connection()->fetchAssociative('SELECT revoked_at, last_used_at FROM idn_api_token WHERE id = ?', [self::OMAR_LIVE])),
        );
    }

    /**
     * OMAR_OLDER expires on 12 Jun 2099 at 10:00, the start of the range; OMAR_LIVE and OMAR_SAME_SECOND in one second
     * on 13 Jun; Lena's on 14 Jun at 10:00, the end of the first range; the revoked one on 13 Jun at 11:00; Beta's on
     * 15 Jun. A page of two ends between the tied tokens, so the lower id goes first.
     */
    #[Test]
    public function expiryWarningsAreClaimedOncePerLiveTokenInTheRangeSoonestFirstAndOnlyInTheTenant(): void
    {
        $issuedAt = new \DateTimeImmutable(self::ISSUED_AT);
        $this->transactions()->committedAs(self::ACME_FOODS, function () use ($issuedAt): void {
            $this->add(self::OMAR_REVOKED, self::ACME_FOODS, self::OMAR, $issuedAt->modify('+1 hour'));
            self::assertTrue($this->tokens()->revoke(self::OMAR_REVOKED, $issuedAt->modify('+2 hours')));
        });
        $from = new \DateTimeImmutable('2099-06-12 10:00:00 UTC');
        $before = new \DateTimeImmutable('2099-06-14 10:00:00 UTC');
        $warnedAt = new \DateTimeImmutable('2099-06-05 06:30:00 UTC');

        $claims = $this->transactions()->committedAs(self::ACME_FOODS, fn (): array => [
            'first page' => $this->tokens()->claimExpiryWarnings($from, $before, $warnedAt, 2),
            'second page' => $this->tokens()->claimExpiryWarnings($from, $before, $warnedAt->modify('+1 minute'), 10),
            'again' => $this->tokens()->claimExpiryWarnings($from, $before, $warnedAt->modify('+2 minutes'), 10),
            'the next day' => $this->tokens()->claimExpiryWarnings($before, $before->modify('+2 days'), $warnedAt, 10),
        ]);
        $betaClaim = $this->transactions()->committedAs(self::BETA_BAKERY, fn (): array => $this->tokens()->claimExpiryWarnings($before, $before->modify('+2 days'), $warnedAt, 10));

        self::assertSame(
            [
                'first page' => [self::OMAR_OLDER, self::OMAR_LIVE],
                'second page' => [self::OMAR_SAME_SECOND],
                'again' => [],
                'the next day' => [self::LENA_AT_ACME],
            ],
            array_map(static fn (array $claimed): array => array_map(static fn (ExpiringApiToken $token): string => $token->id, $claimed), $claims),
        );
        self::assertSame([self::OMAR_AT_BETA], array_map(static fn (ExpiringApiToken $token): string => $token->id, $betaClaim));
        $older = $claims['first page'][0];
        self::assertSame(self::OMAR, $older->ownerId->toString());
        self::assertSame('Claude Code', $older->name);
        self::assertSame(substr($this->plainTokens[self::OMAR_OLDER], 0, 11), $older->prefix);
        self::assertEquals(new \DateTimeImmutable('2099-06-12 10:00:00 UTC'), $older->expiresAt);
        self::assertSame(
            [
                self::OMAR_LIVE => '2099-06-05 06:30:00',
                self::OMAR_SAME_SECOND => '2099-06-05 06:31:00',
                self::OMAR_OLDER => '2099-06-05 06:30:00',
                self::OMAR_EXPIRED => null,
                self::LENA_AT_ACME => '2099-06-05 06:30:00',
                self::OMAR_REVOKED => null,
            ],
            $this->asAcmeFoods(fn (): array => $this->connection()->fetchAllKeyValue(
                "SELECT id, to_char(expiry_warned_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') FROM idn_api_token ORDER BY id",
            )),
        );
    }

    #[Test]
    public function aClaimOfNoTokenOrOfAnEmptyRangeIsRefusedAndWithoutATenantToo(): void
    {
        $from = new \DateTimeImmutable('2099-06-12 10:00:00 UTC');
        $refusals = [];
        $calls = [
            'no token' => fn (): array => $this->tokens()->claimExpiryWarnings($from, $from->modify('+1 day'), $from, 0),
            'an empty range' => fn (): array => $this->tokens()->claimExpiryWarnings($from, $from, $from, 10),
        ];
        foreach ($calls as $case => $call) {
            try {
                $this->asAcmeFoods($call);
            } catch (\InvalidArgumentException) {
                $refusals[] = $case;
            }
        }
        try {
            $this->tokens()->claimExpiryWarnings($from, $from->modify('+1 day'), $from, 10);
        } catch (TenantNotSet) {
            $refusals[] = 'without a tenant';
        }

        self::assertSame(['no token', 'an empty range', 'without a tenant'], $refusals);
        self::assertSame(0, $this->asAcmeFoods(fn (): mixed => $this->connection()->fetchOne('SELECT count(*) FROM idn_api_token WHERE expiry_warned_at IS NOT NULL')));
    }

    #[Test]
    public function aLiveTokenIsResolvedToItsIdsBeforeAnyTenantAndOthersAreNot(): void
    {
        $owner = $this->tokens()->ownerOfLiveToken($this->hashOf(self::OMAR_AT_BETA));

        self::assertNotNull($owner);
        self::assertSame(self::OMAR_AT_BETA, $owner->tokenId);
        self::assertSame(self::BETA_BAKERY, $owner->organizationId->toString());
        self::assertSame(self::OMAR, $owner->userId->toString());
        self::assertSame(self::OMAR_LIVE, $this->tokens()->ownerOfLiveToken($this->hashOf(self::OMAR_LIVE))?->tokenId);
        self::assertNull($this->tokens()->ownerOfLiveToken($this->hashOf(self::OMAR_EXPIRED)));
        self::assertNull($this->tokens()->ownerOfLiveToken(hash('sha256', $this->plainTokens[self::OMAR_LIVE], true)));
        self::assertNull($this->tokens()->ownerOfLiveToken(new HmacApiTokenHashing(self::PEPPER.'-other')->hash($this->plainTokens[self::OMAR_LIVE])));
    }

    #[Test]
    public function aRevokedTokenIsNoLongerResolved(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn (): bool => $this->tokens()->revoke(self::OMAR_LIVE, new \DateTimeImmutable('2099-03-20 09:00:00 UTC')));

        self::assertNull($this->tokens()->ownerOfLiveToken($this->hashOf(self::OMAR_LIVE)));
        self::assertSame(self::OMAR_SAME_SECOND, $this->tokens()->ownerOfLiveToken($this->hashOf(self::OMAR_SAME_SECOND))?->tokenId);
    }

    /**
     * Omar leaves Acme Foods and stays in Beta Bakery, and Lena stays in Acme Foods: only Omar's Acme Foods tokens
     * stop, and their rows stay as they were.
     */
    #[Test]
    public function aLeaversTokensOfTheOrganizationTheyLeftAreNoLongerResolvedAndKeepTheirRows(): void
    {
        $rowsOf = fn (): array => $this->asAcmeFoods(fn (): array => $this->connection()->fetchAllAssociative(
            'SELECT id, user_id, revoked_at, last_used_at FROM idn_api_token WHERE user_id = ? ORDER BY id',
            [self::OMAR],
        ));
        self::assertSame(self::OMAR_LIVE, $this->tokens()->ownerOfLiveToken($this->hashOf(self::OMAR_LIVE))?->tokenId);
        $rowsBefore = $rowsOf();
        self::assertCount(4, $rowsBefore);

        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->removeMembership(self::ACME_FOODS, self::OMAR));

        self::assertNull($this->tokens()->ownerOfLiveToken($this->hashOf(self::OMAR_LIVE)));
        self::assertNull($this->tokens()->ownerOfLiveToken($this->hashOf(self::OMAR_SAME_SECOND)));
        self::assertSame(self::OMAR_AT_BETA, $this->tokens()->ownerOfLiveToken($this->hashOf(self::OMAR_AT_BETA))?->tokenId);
        self::assertSame(self::LENA_AT_ACME, $this->tokens()->ownerOfLiveToken($this->hashOf(self::LENA_AT_ACME))?->tokenId);
        self::assertSame($rowsBefore, $rowsOf());
    }

    #[Test]
    public function aTokenOfAPersonWhoWasNeverAMemberOfItsOrganizationIsNotResolved(): void
    {
        $this->transactions()->committedAs(self::BETA_BAKERY, fn () => $this->add(self::LENA_AT_BETA, self::BETA_BAKERY, self::LENA, new \DateTimeImmutable(self::ISSUED_AT)));

        self::assertNull($this->tokens()->ownerOfLiveToken($this->hashOf(self::LENA_AT_BETA)));
        self::assertSame(self::LENA_AT_ACME, $this->tokens()->ownerOfLiveToken($this->hashOf(self::LENA_AT_ACME))?->tokenId);
    }

    #[Test]
    public function eachOrganizationSeesOnlyItsOwnTokenRowsAndNoneWithoutATenant(): void
    {
        $visibleTokens = fn (string $organizationId): array => $this->transactions()->rolledBackAs(
            $organizationId,
            fn (): array => $this->connection()->fetchFirstColumn('SELECT id FROM idn_api_token ORDER BY id'),
        );

        self::assertSame([self::OMAR_LIVE, self::OMAR_SAME_SECOND, self::OMAR_OLDER, self::OMAR_EXPIRED, self::LENA_AT_ACME], $visibleTokens(self::ACME_FOODS));
        self::assertSame([self::OMAR_AT_BETA], $visibleTokens(self::BETA_BAKERY));
        self::assertSame(0, $this->connection()->fetchOne('SELECT count(*) FROM idn_api_token'));
    }

    /**
     * The app may change only the revocation and the last use, so a token's scopes, owner and expiry stay as issued.
     */
    #[Test]
    public function theAppCannotChangeWhatATokenMayDoOrHowLongItLasts(): void
    {
        foreach (["scopes = '{read,tickets:move}'", "expires_at = expires_at + interval '1 year'", 'user_id = organization_id', "name = 'renamed'"] as $change) {
            try {
                $this->asAcmeFoods(fn (): int|string => $this->connection()->executeStatement('UPDATE idn_api_token SET '.$change.' WHERE id = ?', [self::OMAR_LIVE]));
                self::fail('The app changed a token: '.$change);
            } catch (DriverException $refusal) {
                self::assertSame('42501', $refusal->getSQLState(), $change);
                self::assertStringContainsString('permission denied for table idn_api_token', $refusal->getMessage(), $change);
            }
        }
    }

    #[Test]
    public function aTokenMayHoldNotebooksWriteAlone(): void
    {
        $issued = ApiToken::issue(self::OMAR_NOTEBOOKS, new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), 'Claude Code', [ApiTokenScope::NotebooksWrite], new HmacApiTokenHashing(self::PEPPER), new \DateTimeImmutable(self::ISSUED_AT), ApiToken::DEFAULT_LIFETIME_DAYS);

        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->tokens()->add($issued->token()));

        self::assertSame('{notebooks:write}', $this->asAcmeFoods(fn (): mixed => $this->connection()->fetchOne('SELECT scopes::text FROM idn_api_token WHERE id = ?', [self::OMAR_NOTEBOOKS])));
    }

    /**
     * Written past ApiTokenScope, as only the constraint stands between other SQL and the table. The same row with a
     * known scope goes in, so the refusals come from the scope alone.
     */
    #[Test]
    public function theDatabaseRefusesAScopeThatIsNotOneOfTheCases(): void
    {
        self::assertSame(1, $this->asAcmeFoods(fn (): int|string => $this->insertRawToken(self::OMAR_REFUSED_SCOPE, '{read,notebooks:write}')));

        foreach (['{read,admin}', '{notebooks:read}', '{NOTEBOOKS:WRITE}'] as $scopes) {
            try {
                $this->asAcmeFoods(fn (): int|string => $this->insertRawToken(self::OMAR_REFUSED_SCOPE, $scopes));
                self::fail('The database took the scopes '.$scopes);
            } catch (DriverException $refusal) {
                self::assertSame('23514', $refusal->getSQLState(), $scopes);
                self::assertStringContainsString('idn_api_token_scopes_check', $refusal->getMessage(), $scopes);
            }
        }

        self::assertSame(0, $this->asAcmeFoods(fn (): mixed => $this->connection()->fetchOne('SELECT count(*) FROM idn_api_token WHERE id = ?', [self::OMAR_REFUSED_SCOPE])));
    }

    /**
     * @param string $scopes a PostgreSQL array literal
     */
    private function insertRawToken(string $id, string $scopes): int|string
    {
        return $this->connection()->executeStatement(
            "INSERT INTO idn_api_token (organization_id, id, user_id, name, token_hash, prefix, scopes, created_at, expires_at)
             VALUES (?, ?, ?, 'Claude Code', decode(?, 'hex'), 'pr_abcdefgh', ?::text[], '2099-03-15 10:00:00+00', '2099-06-13 10:00:00+00')",
            [self::ACME_FOODS, $id, self::OMAR, hash('sha256', $id.$scopes), $scopes],
        );
    }

    private function add(string $id, string $organizationId, string $userId, \DateTimeImmutable $issuedAt, int $lifetimeDays = ApiToken::DEFAULT_LIFETIME_DAYS): void
    {
        $issued = ApiToken::issue($id, new OrganizationId($organizationId), new UserId($userId), 'Claude Code', ApiTokenScope::claudeCodeDefaults(), new HmacApiTokenHashing(self::PEPPER), $issuedAt, $lifetimeDays);
        $this->tokens()->add($issued->token());
        $this->plainTokens[$id] = $issued->reveal();
    }

    private function addMembership(string $organizationId, string $userId): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO org_membership (organization_id, user_id, org_roles, joined_at) VALUES (?, ?, '{}', '2099-03-01 10:00:00+00')",
            [$organizationId, $userId],
        );
    }

    /**
     * As a person leaves: their membership row is deleted.
     */
    private function removeMembership(string $organizationId, string $userId): void
    {
        self::assertSame(1, $this->connection()->executeStatement('DELETE FROM org_membership WHERE organization_id = ? AND user_id = ?', [$organizationId, $userId]));
    }

    private function removeMembershipsOf(string ...$organizationIds): void
    {
        foreach ($organizationIds as $organizationId) {
            $this->transactions()->committedAs($organizationId, fn (): int|string => $this->connection()->executeStatement('DELETE FROM org_membership WHERE organization_id = ?', [$organizationId]));
        }
    }

    /**
     * @return list<string>
     */
    private function liveIdsOf(string $userId, \DateTimeImmutable $at): array
    {
        return array_map(static fn (ApiToken $token): string => $token->id(), $this->tokens()->liveForUser(new UserId($userId), $at));
    }

    /**
     * @return list<string>
     */
    private function pageIdsOf(\DateTimeImmutable $at, int $limit, ?\DateTimeImmutable $afterExpiresAt = null, ?string $afterId = null): array
    {
        return array_map(static fn (ApiToken $token): string => $token->id(), $this->tokens()->livePage($at, $limit, $afterExpiresAt, $afterId));
    }

    private static function extraTokenId(int $hoursBack): string
    {
        return \sprintf('0197a3c0-0000-7000-8001-0000000000%02d', $hoursBack);
    }

    private function hashOf(string $tokenId): string
    {
        return new HmacApiTokenHashing(self::PEPPER)->hash($this->plainTokens[$tokenId]);
    }

    private function utcTextOf(string $column, string $tokenId): mixed
    {
        return $this->connection()->fetchOne(
            \sprintf("SELECT to_char(%s AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') FROM idn_api_token WHERE id = ?", $column),
            [$tokenId],
        );
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function asAcmeFoods(\Closure $work): mixed
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, $work);
    }

    /**
     * Through the migrator, which owns the table and so may delete; FORCE ROW LEVEL SECURITY binds it too.
     */
    private function removeTokensOf(string ...$organizationIds): void
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        foreach ($organizationIds as $organizationId) {
            $tenantContext->set(new OrganizationId($organizationId));
            try {
                self::getContainer()->get('doctrine.dbal.migrator_connection')->transactional(
                    static fn (Connection $migrator): int|string => $migrator->executeStatement('DELETE FROM idn_api_token WHERE organization_id = ?', [$organizationId]),
                );
            } finally {
                $tenantContext->clear();
            }
        }
    }

    private function tokens(): DoctrineApiTokens
    {
        return new DoctrineApiTokens($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }
}
