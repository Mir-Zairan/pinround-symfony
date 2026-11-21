<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Api\Event\ApiTokenIssued;
use App\Identity\Application\ApiTokenRefused;
use App\Identity\Application\IssueApiToken;
use App\Identity\Application\NewApiToken;
use App\Identity\Application\TooManyApiTokens;
use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\Domain\InvalidApiToken;
use App\Identity\Domain\IssuedApiToken;
use App\Identity\Infrastructure\Security\HmacApiTokenHashing;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
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

    private RecordingIdentityEvents $events;

    protected function setUp(): void
    {
        $this->events = new RecordingIdentityEvents();
    }

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
    public function anIssuedTokenPublishesApiTokenIssuedWithItsIdsScopesAndExpiryOnly(): void
    {
        $issued = $this->issueForOmar($this->tokensOfAcmeFoods(), 'Claude Code');

        self::assertEquals(
            [new ApiTokenIssued(self::ACME_FOODS, $issued->token()->id(), self::OMAR, ['read', 'rounds:write'], new \DateTimeImmutable('2027-04-14 10:00:00 UTC'), self::OMAR, new \DateTimeImmutable(self::NOW))],
            $this->events->publishedEvents,
        );
        $serialized = serialize($this->events->publishedEvents);
        self::assertStringNotContainsString($issued->reveal(), $serialized);
        self::assertStringNotContainsString($issued->token()->tokenHash(), $serialized);
        self::assertStringNotContainsString($issued->token()->prefix(), $serialized);
        self::assertStringNotContainsString('Claude Code', $serialized);
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
        self::assertSame([], $this->events->publishedEvents);
    }

    /**
     * A refresh replaces the access token every hour; nobody acted, so the audit log must not say the person made one.
     */
    #[Test]
    public function aRenewedOAuthAccessTokenIsAddedUnderTheLockAndPublishesNothing(): void
    {
        $tokens = $this->tokensOfAcmeFoods();

        $renewed = $this->renewForOmar($tokens);

        self::assertSame(['lockIssuingFor', 'countLiveOf', 'add'], $tokens->calls);
        self::assertSame([$renewed->token()->id()], $tokens->ids());
        self::assertSame('Claude', $renewed->token()->name());
        self::assertSame([], $this->events->publishedEvents);
    }

    #[Test]
    public function aRenewalOverTheCapIsRefusedAndAddsNothing(): void
    {
        $tokens = $this->tokensOfAcmeFoods();
        $this->seedLiveTokens($tokens, self::ACME_FOODS, self::OMAR, IssueApiToken::MAX_LIVE_TOKENS_PER_USER);
        $idsBefore = $tokens->ids();

        try {
            $this->renewForOmar($tokens);
            self::fail('A 21st live token was renewed.');
        } catch (TooManyApiTokens $refusal) {
            self::assertSame('You have 20 tokens that still work. Revoke one before you make another.', $refusal->getMessage());
        }

        self::assertSame(['lockIssuingFor', 'countLiveOf'], $tokens->calls);
        self::assertSame($idsBefore, $tokens->ids());
        self::assertSame([], $this->events->publishedEvents);
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
        self::assertSame([], $this->events->publishedEvents);
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
        self::assertSame([], $this->events->publishedEvents);
    }

    #[Test]
    public function theFakeRefusesACountWithoutTheLockOfThatPerson(): void
    {
        $tokens = $this->tokensOfAcmeFoods();
        $tokens->lockIssuingFor(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('Live tokens were counted before the issuing lock of the person was taken.');

        $tokens->countLiveOf(new UserId(self::OMAR), new \DateTimeImmutable(self::NOW));
    }

    /**
     * The page's checkboxes send the names in any order, and a crafted form may repeat one.
     */
    #[Test]
    public function scopeNamesBecomeTheTokensScopesOnceEachInTheOrderOfTheCases(): void
    {
        $tokens = $this->tokensOfAcmeFoods();

        $newToken = $this->issueWithScopeNamesForOmar($tokens, 'Claude Code', ['tickets:move', 'read', 'tickets:move']);

        self::assertSame(['read', 'tickets:move'], $newToken->token->scopeNames);
        self::assertSame([$newToken->token->id], $tokens->ids());
        self::assertSame('Claude Code', $newToken->token->name);
        self::assertMatchesRegularExpression('/\Apr_[A-Za-z0-9_-]{43}\z/', $newToken->reveal());
        self::assertSame(substr($newToken->reveal(), 0, 11), $newToken->token->prefix);
        self::assertEquals(new \DateTimeImmutable(self::NOW), $newToken->token->createdAt);
        self::assertEquals(new \DateTimeImmutable('2027-04-14 10:00:00 UTC'), $newToken->token->expiresAt);
        self::assertNull($newToken->token->lastUsedAt);
        $stored = $tokens->byIdForOwner($newToken->token->id, new UserId(self::OMAR));
        self::assertNotNull($stored);
        self::assertSame(new HmacApiTokenHashing(self::PEPPER)->hash($newToken->reveal()), $stored->tokenHash());
        self::assertEquals(
            [new ApiTokenIssued(self::ACME_FOODS, $newToken->token->id, self::OMAR, ['read', 'tickets:move'], new \DateTimeImmutable('2027-04-14 10:00:00 UTC'), self::OMAR, new \DateTimeImmutable(self::NOW))],
            $this->events->publishedEvents,
        );
    }

    #[Test]
    public function theNewTokenIsNeitherDumpedNorSerializedWithItsPlainText(): void
    {
        $newToken = $this->issueWithScopeNamesForOmar($this->tokensOfAcmeFoods(), 'Claude Code', ['read']);

        self::assertStringNotContainsString($newToken->reveal(), print_r($newToken, true));
        self::assertStringNotContainsString($newToken->reveal(), var_export($newToken->__debugInfo(), true));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('A new API token is never serialized.');

        serialize($newToken);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function scopeNamesNoTokenMayHave(): iterable
    {
        yield 'an unknown name' => [['read', 'admin']];
        yield 'a known name in capitals' => [['READ']];
        yield 'a known name with a capital' => [['Read']];
        yield 'a known name with a space' => [[' read']];
        yield 'an empty name' => [['']];
        yield 'more names than there are scopes, all known' => [array_fill(0, \count(ApiTokenScope::cases()) + 1, 'read')];
    }

    /**
     * @param list<string> $scopeNames
     */
    #[Test]
    #[DataProvider('scopeNamesNoTokenMayHave')]
    public function aScopeNameNoTokenMayHaveIsRefusedInThePagesWordsBeforeTheLock(array $scopeNames): void
    {
        $tokens = $this->tokensOfAcmeFoods();

        try {
            $this->issueWithScopeNamesForOmar($tokens, 'Claude Code', $scopeNames);
            self::fail('A token with the scope names '.implode(', ', $scopeNames).' was issued.');
        } catch (ApiTokenRefused $refusal) {
            self::assertSame('Choose only the things listed on this page.', $refusal->getMessage());
        }

        self::assertSame([], $tokens->calls);
        self::assertSame([], $tokens->ids());
        self::assertSame([], $this->events->publishedEvents);
    }

    /**
     * @return iterable<string, array{string, list<string>, int, string}>
     */
    public static function choicesApiTokenIssueRefuses(): iterable
    {
        yield 'no scope' => ['Claude Code', [], 30, 'Choose at least one thing the token may do.'];
        yield 'a blank name' => [" \u{200B} ", ['read'], 30, 'Give the token a name.'];
        yield 'a name with a line separator' => ["Omar\u{2028}laptop", ['read'], 30, 'A token name is one line of plain text.'];
        yield 'a name with an escape character' => ["Omar \e[31mlaptop", ['read'], 30, 'A token name is one line of plain text.'];
        yield 'a name of 101 characters' => [str_repeat('é', 101), ['read'], 30, 'A token name has at most 100 characters.'];
        yield 'no days' => ['Claude Code', ['read'], 0, 'A token lasts from 1 to 365 days.'];
        yield 'more than a year' => ['Claude Code', ['read'], 366, 'A token lasts from 1 to 365 days.'];
    }

    /**
     * @param list<string> $scopeNames
     */
    #[Test]
    #[DataProvider('choicesApiTokenIssueRefuses')]
    public function aChoiceTheTokenRefusesIsRefusedWithItsMessageAndNothingIsAdded(string $name, array $scopeNames, int $lifetimeDays, string $message): void
    {
        $tokens = $this->tokensOfAcmeFoods();

        try {
            new IssueApiToken($tokens, new HmacApiTokenHashing(self::PEPPER), $this->events)->issueWithScopeNames(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), $name, $scopeNames, $lifetimeDays, new \DateTimeImmutable(self::NOW));
            self::fail('The token was issued.');
        } catch (ApiTokenRefused $refusal) {
            self::assertSame($message, $refusal->getMessage());
            self::assertInstanceOf(InvalidApiToken::class, $refusal->getPrevious());
        }

        self::assertSame([], $tokens->calls);
        self::assertSame([], $tokens->ids());
        self::assertSame([], $this->events->publishedEvents);
    }

    #[Test]
    public function aNameOfAHundredCharactersOfTwoBytesEachIsKept(): void
    {
        $newToken = $this->issueWithScopeNamesForOmar($this->tokensOfAcmeFoods(), str_repeat('é', 100), ['read']);

        self::assertSame(str_repeat('é', 100), $newToken->token->name);
    }

    #[Test]
    public function overTheCapTheRefusalIsTheSameAsWithScopesAndNothingIsAdded(): void
    {
        $tokens = $this->tokensOfAcmeFoods();
        $this->seedLiveTokens($tokens, self::ACME_FOODS, self::OMAR, IssueApiToken::MAX_LIVE_TOKENS_PER_USER);
        $idsBefore = $tokens->ids();

        try {
            $this->issueWithScopeNamesForOmar($tokens, 'One too many', ['read']);
            self::fail('A 21st live token was issued.');
        } catch (TooManyApiTokens $refusal) {
            self::assertSame('You have 20 tokens that still work. Revoke one before you make another.', $refusal->getMessage());
        }

        self::assertSame($idsBefore, $tokens->ids());
        self::assertSame([], $this->events->publishedEvents);
    }

    /**
     * @param list<string> $scopeNames
     */
    private function issueWithScopeNamesForOmar(InMemoryApiTokens $tokens, string $name, array $scopeNames): NewApiToken
    {
        return new IssueApiToken($tokens, new HmacApiTokenHashing(self::PEPPER), $this->events)->issueWithScopeNames(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), $name, $scopeNames, 30, new \DateTimeImmutable(self::NOW));
    }

    private function issueForOmar(InMemoryApiTokens $tokens, string $name): IssuedApiToken
    {
        $issue = new IssueApiToken($tokens, new HmacApiTokenHashing(self::PEPPER), $this->events);

        return $issue(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), $name, ApiTokenScope::claudeCodeDefaults(), 30, new \DateTimeImmutable(self::NOW));
    }

    private function renewForOmar(InMemoryApiTokens $tokens): IssuedApiToken
    {
        return new IssueApiToken($tokens, new HmacApiTokenHashing(self::PEPPER), $this->events)
            ->renewForOAuthClient(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR), 'Claude', [ApiTokenScope::Read], new \DateTimeImmutable(self::NOW));
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
