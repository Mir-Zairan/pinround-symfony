<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\IssueApiToken;
use App\Identity\Application\TooManyApiTokens;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\InvalidApiToken;
use App\Identity\Domain\IssuedApiToken;
use App\Identity\Infrastructure\Security\HmacApiTokenHashing;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The order IssueApiToken works in: the lock first, then the count, then the insert, as the race needs; the
 * integration test proves the lock itself on PostgreSQL.
 */
final class IssueApiTokenTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000ea1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-000000000eb1';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000ed1';
    private const string LENA = '0197a3c0-0000-7000-8000-000000000ed2';
    private const string PEPPER = 'unit-test-pepper-of-at-least-32-bytes';
    private const string NOW = '2027-03-15 10:00:00 UTC';
    // Before NOW, so a token expired by the given time still looks live to the database clock.
    private const string DATABASE_NOW = '2027-01-01 00:00:00 UTC';

    private int $seededTokens = 0;

    #[Test]
    public function theLockIsTakenBeforeTheCountAndTheTokenIsAddedAfterIt(): void
    {
        $tokens = $this->tokensOfAcmeFoods();

        $issued = $this->issueForOmar($tokens, 'Claude Code');

        self::assertSame(['lockIssuingFor', 'countLiveOf', 'add'], $tokens->calls);
        self::assertSame([$issued->token()->id()], $tokens->ids());
        self::assertSame('Claude Code', $issued->token()->name());
    }

    #[Test]
    public function withTwentyLiveTokensTheIssueIsRefusedAfterTheLockedCountAndNothingIsAdded(): void
    {
        $tokens = $this->tokensOfAcmeFoods();
        $this->seedLiveTokens($tokens, self::ACME_FOODS, self::OMAR, IssueApiToken::MAX_LIVE_TOKENS_PER_USER);
        $idsBefore = $tokens->ids();

        try {
            $this->issueForOmar($tokens, 'One too many');
            self::fail('A 21st live token was issued.');
        } catch (TooManyApiTokens $refusal) {
            self::assertSame('You have 20 tokens that still work. Revoke one before you make another.', $refusal->getMessage());
        }

        self::assertSame(['lockIssuingFor', 'countLiveOf'], $tokens->calls);
        self::assertSame($idsBefore, $tokens->ids());
    }

    #[Test]
    public function revokedExpiredAndOtherPeoplesOrOrganizationsTokensLeaveRoomUnderTheCap(): void
    {
        $tokens = $this->tokensOfAcmeFoods();
        $now = new \DateTimeImmutable(self::NOW);
        $this->seedLiveTokens($tokens, self::ACME_FOODS, self::OMAR, IssueApiToken::MAX_LIVE_TOKENS_PER_USER - 1);
        $tokens->seed($this->seededToken(self::ACME_FOODS, self::OMAR, $now->modify('-1 day')), $now->modify('-1 hour'));
        $tokens->seed($this->seededToken(self::ACME_FOODS, self::OMAR, $now->modify('-1 day'), 1));
        $this->seedLiveTokens($tokens, self::ACME_FOODS, self::LENA, 1);
        $this->seedLiveTokens($tokens, self::BETA_BAKERY, self::OMAR, 1);

        $issued = $this->issueForOmar($tokens, 'Claude Code');

        self::assertContains($issued->token()->id(), $tokens->ids());
        self::assertCount(IssueApiToken::MAX_LIVE_TOKENS_PER_USER + 4, $tokens->ids());
    }

    #[Test]
    public function withoutAnOpenTransactionTheIssueIsRefusedBeforeAnyCountAndNothingIsAdded(): void
    {
        $tokens = new InMemoryApiTokens(new OrganizationId(self::ACME_FOODS), new \DateTimeImmutable(self::DATABASE_NOW), isTransactionOpen: false);

        try {
            $this->issueForOmar($tokens, 'Claude Code');
            self::fail('A token was issued outside a transaction.');
        } catch (\LogicException $refusal) {
            self::assertSame('Issuing a token needs an open transaction; none is open.', $refusal->getMessage());
        }

        self::assertSame(['lockIssuingFor'], $tokens->calls);
        self::assertSame([], $tokens->ids());
    }

    #[Test]
    public function anInvalidNameIsRefusedBeforeTheLockAndNothingIsAdded(): void
    {
        $tokens = $this->tokensOfAcmeFoods();

        try {
            $this->issueForOmar($tokens, "\u{00A0}");
            self::fail('A token without a name was issued.');
        } catch (InvalidApiToken $refusal) {
            self::assertSame('Give the token a name.', $refusal->getMessage());
        }

        self::assertSame([], $tokens->calls);
        self::assertSame([], $tokens->ids());
    }

    #[Test]
    public function theFakeRefusesACountWithoutTheLockOfThatPerson(): void
    {
        $tokens = $this->tokensOfAcmeFoods();
        $tokens->lockIssuingFor(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Live tokens were counted before the issuing lock of the person was taken.');

        $tokens->countLiveOf(new UserId(self::OMAR), new \DateTimeImmutable(self::NOW));
    }

    private function issueForOmar(InMemoryApiTokens $tokens, string $name): IssuedApiToken
    {
        $issue = new IssueApiToken($tokens, new HmacApiTokenHashing(self::PEPPER));

        return $issue(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), $name, ApiTokenScope::claudeCodeDefaults(), 30, new \DateTimeImmutable(self::NOW));
    }

    private function tokensOfAcmeFoods(): InMemoryApiTokens
    {
        return new InMemoryApiTokens(new OrganizationId(self::ACME_FOODS), new \DateTimeImmutable(self::DATABASE_NOW));
    }

    private function seedLiveTokens(InMemoryApiTokens $tokens, string $organizationId, string $userId, int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $tokens->seed($this->seededToken($organizationId, $userId, new \DateTimeImmutable(self::NOW)->modify(\sprintf('-%d hours', $i + 1))));
        }
    }

    private function seededToken(string $organizationId, string $userId, \DateTimeImmutable $issuedAt, int $lifetimeDays = ApiToken::DEFAULT_LIFETIME_DAYS): ApiToken
    {
        $id = \sprintf('0197a3c0-0000-7000-8001-%012d', ++$this->seededTokens);

        return ApiToken::issue($id, new OrganizationId($organizationId), new UserId($userId), 'Seeded', ApiTokenScope::claudeCodeDefaults(), new HmacApiTokenHashing(self::PEPPER), $issuedAt, $lifetimeDays)->token();
    }
}
