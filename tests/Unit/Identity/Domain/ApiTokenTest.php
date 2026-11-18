<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

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

final class ApiTokenTest extends TestCase
{
    private const string TOKEN_ID = '0197a3c0-0000-7000-8000-000000000a01';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000000a11';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000000a21';
    private const string PEPPER = 'unit-test-pepper-of-at-least-32-bytes';
    private const string ISSUED_AT = '2027-03-15 10:00:00 UTC';

    #[Test]
    public function theTokenIsShownOnceAsPrAndThirtyTwoRandomBytesInBase64Url(): void
    {
        $issued = self::issue();
        $plainToken = $issued->reveal();

        self::assertMatchesRegularExpression('/^pr_[A-Za-z0-9_-]{43}\z/', $plainToken);
        self::assertSame(32, \strlen((string) base64_decode(strtr(substr($plainToken, 3), '-_', '+/'), true)));
        self::assertNotSame($plainToken, self::issue()->reveal());
    }

    #[Test]
    public function theEntityKeepsOnlyThePepperedHashAndTheEightCharacterDisplayPrefix(): void
    {
        $issued = self::issue();
        $token = $issued->token();
        $plainToken = $issued->reveal();

        self::assertSame(hash_hmac('sha256', $plainToken, self::PEPPER, true), $token->tokenHash());
        self::assertNotSame(hash('sha256', $plainToken, true), $token->tokenHash());
        self::assertSame(substr($plainToken, 0, 11), $token->prefix());
        self::assertMatchesRegularExpression('/^pr_[A-Za-z0-9_-]{8}\z/', $token->prefix());
        $everyField = var_export((array) $token, true);
        self::assertStringNotContainsString($plainToken, $everyField);
        self::assertStringNotContainsString(substr($plainToken, 3), $everyField);
    }

    #[Test]
    public function theHashChangesWithThePepper(): void
    {
        $plainToken = self::issue()->reveal();

        self::assertNotSame(
            new HmacApiTokenHashing(self::PEPPER)->hash($plainToken),
            new HmacApiTokenHashing(self::PEPPER.'-rotated')->hash($plainToken),
        );
    }

    #[Test]
    public function itKeepsTheOwnerTheTrimmedNameAndTheScopesOnceInCaseOrder(): void
    {
        $token = self::issue(name: "  Claude Code on Omar's laptop  ", scopes: [ApiTokenScope::RoundsWrite, ApiTokenScope::Read, ApiTokenScope::RoundsWrite])->token();

        self::assertSame(self::TOKEN_ID, $token->id());
        self::assertSame(self::ACME_FOODS, $token->organizationId()->toString());
        self::assertSame(self::OMAR, $token->userId()->toString());
        self::assertSame("Claude Code on Omar's laptop", $token->name());
        self::assertSame([ApiTokenScope::Read, ApiTokenScope::RoundsWrite], $token->scopes());
        self::assertNull($token->lastUsedAt());
        self::assertNull($token->revokedAt());
    }

    #[Test]
    public function itExpiresNinetyDaysAfterIssueByDefault(): void
    {
        $token = self::issue()->token();

        self::assertEquals(new \DateTimeImmutable(self::ISSUED_AT), $token->createdAt());
        self::assertEquals(new \DateTimeImmutable('2027-06-13 10:00:00 UTC'), $token->expiresAt());
    }

    #[Test]
    public function aLifetimeOfOneOrThreeHundredSixtyFiveDaysIsTaken(): void
    {
        self::assertEquals(new \DateTimeImmutable('2027-03-16 10:00:00 UTC'), self::issue(lifetimeDays: 1)->token()->expiresAt());
        self::assertEquals(new \DateTimeImmutable('2028-03-14 10:00:00 UTC'), self::issue(lifetimeDays: 365)->token()->expiresAt());
    }

    /**
     * A wall clock with daylight saving would make one of the days 23 or 25 hours; the expiry is counted in UTC days.
     */
    #[Test]
    public function theExpiryIsCountedInUtcDaysWhateverTheIssueTimeZone(): void
    {
        $issuedInBerlin = new \DateTimeImmutable('2027-03-15 11:00:00', new \DateTimeZone('Europe/Berlin'));

        $token = self::issue(issuedAt: $issuedInBerlin, lifetimeDays: 365)->token();

        self::assertSame(365 * 86400, $token->expiresAt()->getTimestamp() - $issuedInBerlin->getTimestamp());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function lifetimesOutsideTheCap(): iterable
    {
        yield 'a day past the year' => [366];
        yield 'zero' => [0];
        yield 'negative' => [-30];
    }

    #[Test]
    #[DataProvider('lifetimesOutsideTheCap')]
    public function aLifetimeOutsideOneToThreeHundredSixtyFiveDaysIsRefused(int $lifetimeDays): void
    {
        $this->expectException(InvalidApiToken::class);
        $this->expectExceptionMessageIsOrContains('A token lasts from 1 to 365 days.');

        self::issue(lifetimeDays: $lifetimeDays);
    }

    #[Test]
    public function noScopeIsRefused(): void
    {
        $this->expectException(InvalidApiToken::class);
        $this->expectExceptionMessageIsOrContains('Choose at least one thing the token may do.');

        self::issue(scopes: []);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedNames(): iterable
    {
        yield 'blank' => ["  \t ", 'Give the token a name.'];
        yield 'only no-break spaces' => ["\u{00A0}\u{00A0}", 'Give the token a name.'];
        yield 'only zero-width spaces' => ["\u{200B}\u{200B}", 'Give the token a name.'];
        yield 'an ideographic space and a byte order mark' => ["\u{3000}\u{FEFF}", 'Give the token a name.'];
        yield 'a right-to-left override' => ["laptop\u{202E}nimda", 'A token name is one line of plain text.'];
        yield 'a zero-width space inside' => ["lap\u{200B}top", 'A token name is one line of plain text.'];
        yield 'a newline' => ["laptop\nadmin", 'A token name is one line of plain text.'];
        yield 'a carriage return' => ["laptop\radmin", 'A token name is one line of plain text.'];
        yield 'an escape' => ["laptop\e[31m", 'A token name is one line of plain text.'];
        yield 'a line separator' => ["laptop\u{2028}admin", 'A token name is one line of plain text.'];
        yield 'invalid UTF-8' => ["laptop\xC3", 'A token name is one line of plain text.'];
        yield '101 characters' => [str_repeat('ä', 101), 'A token name has at most 100 characters.'];
    }

    #[Test]
    #[DataProvider('refusedNames')]
    public function aNameThatIsNotOneLineOfUpToOneHundredCharactersIsRefused(string $name, string $refusal): void
    {
        $this->expectException(InvalidApiToken::class);
        $this->expectExceptionMessageIsOrContains($refusal);

        self::issue(name: $name);
    }

    #[Test]
    public function aNameOfOneHundredMultibyteCharactersIsTaken(): void
    {
        self::assertSame(str_repeat('ä', 100), self::issue(name: str_repeat('ä', 100))->token()->name());
    }

    #[Test]
    public function aPastedNameLosesItsTrailingLineEnd(): void
    {
        self::assertSame('Claude Code', self::issue(name: "Claude Code\r\n")->token()->name());
    }

    #[Test]
    public function unicodeSpaceIsTrimmedFromTheEdgesButKeptInside(): void
    {
        self::assertSame("Claude\u{00A0}Code", self::issue(name: "\u{00A0}\u{3000}Claude\u{00A0}Code\u{2003} ")->token()->name());
    }

    /**
     * @param list<ApiTokenScope> $scopes
     */
    private static function issue(string $name = 'Claude Code', array $scopes = [ApiTokenScope::Read], ?\DateTimeImmutable $issuedAt = null, int $lifetimeDays = ApiToken::DEFAULT_LIFETIME_DAYS): IssuedApiToken
    {
        return ApiToken::issue(
            self::TOKEN_ID,
            new OrganizationId(self::ACME_FOODS),
            new UserId(self::OMAR),
            $name,
            $scopes,
            new HmacApiTokenHashing(self::PEPPER),
            $issuedAt ?? new \DateTimeImmutable(self::ISSUED_AT),
            $lifetimeDays,
        );
    }
}
