<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Infrastructure\Identity;

use App\Organization\Api\MemberEmailQueries;
use App\Organization\Infrastructure\Identity\IdentityMemberEmailQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Ticketing\UI\FixedMembershipQueries;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IdentityMemberEmailQueriesTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000073a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000073a2';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000073b1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000073b2';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000073b3';
    private const string NOBODY_KNOWS = '0197a3c0-0000-7000-8000-0000000073b4';

    private FixedMembershipQueries $memberships;
    private AddressedUserQueries $users;

    protected function setUp(): void
    {
        // Mia is a member of Beta Bakery only; NOBODY_KNOWS is a member Identity does not know (or has blocked).
        $this->memberships = new FixedMembershipQueries([
            self::ACME_FOODS => [self::LENA, self::OMAR, self::NOBODY_KNOWS],
            self::BETA_BAKERY => [self::MIA],
        ]);
        $this->users = new AddressedUserQueries([
            self::OMAR => 'omar@acme-foods.example',
            self::MIA => 'mia@beta-bakery.example',
            self::LENA => 'lena@acme-foods.example',
        ]);
    }

    #[Test]
    public function membersGetTheirAddressesInIdOrderAndOthersAreLeftOut(): void
    {
        $emails = $this->emailsOf(self::ACME_FOODS, [self::OMAR, self::MIA, self::NOBODY_KNOWS, self::LENA]);

        self::assertSame([self::LENA => 'lena@acme-foods.example', self::OMAR => 'omar@acme-foods.example'], $emails);
        self::assertSame([[self::LENA, self::OMAR, self::NOBODY_KNOWS]], $this->users->emailsOfReads);
    }

    #[Test]
    public function noMemberAmongThemReadsNoAddresses(): void
    {
        self::assertSame([], $this->emailsOf(self::BETA_BAKERY, [self::LENA, self::OMAR]));
        self::assertSame([], $this->emailsOf(self::ACME_FOODS, []));
        self::assertSame([], $this->users->emailsOfReads);
        self::assertSame([[self::LENA, self::OMAR]], $this->memberships->membersAmongReads);
    }

    #[Test]
    public function moreThanTheCapIsRefusedBeforeAnyRead(): void
    {
        try {
            $this->emailsOf(self::ACME_FOODS, array_fill(0, MemberEmailQueries::MAX_USER_IDS + 1, self::LENA));
            self::fail('101 ids should be refused.');
        } catch (\InvalidArgumentException $refusal) {
            self::assertSame('Ask for the email addresses of at most 100 people at once.', $refusal->getMessage());
        }
        self::assertSame([], $this->memberships->membersAmongReads);
        self::assertSame([], $this->users->emailsOfReads);
        self::assertSame([self::LENA => 'lena@acme-foods.example'], $this->emailsOf(self::ACME_FOODS, array_fill(0, MemberEmailQueries::MAX_USER_IDS, self::LENA)));
    }

    /**
     * @param list<string> $userIds
     *
     * @return array<string, string>
     */
    private function emailsOf(string $organizationId, array $userIds): array
    {
        $queries = new IdentityMemberEmailQueries($this->memberships, $this->users);

        return $queries->emailsOf(new OrganizationId($organizationId), array_map(static fn (string $userId): UserId => new UserId($userId), $userIds));
    }
}
