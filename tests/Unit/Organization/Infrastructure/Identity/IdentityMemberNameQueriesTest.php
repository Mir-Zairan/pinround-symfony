<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Infrastructure\Identity;

use App\Organization\Api\MemberNameQueries;
use App\Organization\Infrastructure\Identity\IdentityMemberNameQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Ticketing\UI\FixedMembershipQueries;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IdentityMemberNameQueriesTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000071a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000071a2';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000071b1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000071b2';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000071b3';
    private const string NOBODY_KNOWS = '0197a3c0-0000-7000-8000-0000000071b4';

    private FixedMembershipQueries $memberships;
    private NamedUserQueries $users;

    protected function setUp(): void
    {
        // Mia is a member of Beta Bakery only; NOBODY_KNOWS is a member Identity does not know.
        $this->memberships = new FixedMembershipQueries([
            self::ACME_FOODS => [self::LENA, self::OMAR, self::NOBODY_KNOWS],
            self::BETA_BAKERY => [self::MIA],
        ]);
        $this->users = new NamedUserQueries([self::OMAR => 'Omar Haddad', self::MIA => 'Mia Berg', self::LENA => 'Lena Novak']);
    }

    #[Test]
    public function membersGetTheirNamesInIdOrderAndOthersAreLeftOut(): void
    {
        $names = $this->namesOf(self::ACME_FOODS, [self::OMAR, self::MIA, self::NOBODY_KNOWS, self::LENA]);

        self::assertSame([self::LENA => 'Lena Novak', self::OMAR => 'Omar Haddad'], $names);
        self::assertSame([[self::LENA, self::OMAR, self::NOBODY_KNOWS]], $this->users->byIdsReads);
    }

    #[Test]
    public function noMemberAmongThemReadsNoNames(): void
    {
        self::assertSame([], $this->namesOf(self::BETA_BAKERY, [self::LENA, self::OMAR]));
        self::assertSame([], $this->namesOf(self::ACME_FOODS, []));
        self::assertSame([], $this->users->byIdsReads);
        self::assertSame([[self::LENA, self::OMAR]], $this->memberships->membersAmongReads);
    }

    #[Test]
    public function moreThanTheCapIsRefusedBeforeAnyRead(): void
    {
        try {
            $this->namesOf(self::ACME_FOODS, array_fill(0, MemberNameQueries::MAX_USER_IDS + 1, self::LENA));
            self::fail('101 ids should be refused.');
        } catch (\InvalidArgumentException $refusal) {
            self::assertSame('Ask for the names of at most 100 people at once.', $refusal->getMessage());
        }
        self::assertSame([], $this->memberships->membersAmongReads);
        self::assertSame([], $this->users->byIdsReads);
        self::assertSame([self::LENA => 'Lena Novak'], $this->namesOf(self::ACME_FOODS, array_fill(0, MemberNameQueries::MAX_USER_IDS, self::LENA)));
    }

    /**
     * @param list<string> $userIds
     *
     * @return array<string, string>
     */
    private function namesOf(string $organizationId, array $userIds): array
    {
        $queries = new IdentityMemberNameQueries($this->memberships, $this->users);

        return $queries->displayNamesOf(new OrganizationId($organizationId), array_map(static fn (string $userId): UserId => new UserId($userId), $userIds));
    }
}
