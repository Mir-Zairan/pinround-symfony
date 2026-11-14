<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Infrastructure\Identity;

use App\Organization\Api\MemberTimeZoneQueries;
use App\Organization\Infrastructure\Identity\IdentityMemberTimeZoneQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Ticketing\UI\FixedMembershipQueries;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IdentityMemberTimeZoneQueriesTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000072a1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000072a2';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000072b1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000072b2';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000072b3';
    private const string NOBODY_KNOWS = '0197a3c0-0000-7000-8000-0000000072b4';

    private FixedMembershipQueries $memberships;
    private ZonedUserQueries $users;

    protected function setUp(): void
    {
        // Mia is a member of Beta Bakery only; NOBODY_KNOWS is a member Identity does not know.
        $this->memberships = new FixedMembershipQueries([
            self::ACME_FOODS => [self::LENA, self::OMAR, self::NOBODY_KNOWS],
            self::BETA_BAKERY => [self::MIA],
        ]);
        $this->users = new ZonedUserQueries([self::OMAR => 'Asia/Tokyo', self::MIA => 'America/New_York', self::LENA => 'Europe/Berlin']);
    }

    #[Test]
    public function membersGetTheirZonesInIdOrderAndOthersAreLeftOut(): void
    {
        $zones = $this->zoneNamesOf(self::ACME_FOODS, [self::OMAR, self::MIA, self::NOBODY_KNOWS, self::LENA]);

        self::assertSame([self::LENA => 'Europe/Berlin', self::OMAR => 'Asia/Tokyo'], $zones);
        self::assertSame([[self::LENA, self::OMAR, self::NOBODY_KNOWS]], $this->users->timeZonesOfReads);
    }

    #[Test]
    public function noMemberAmongThemReadsNoZones(): void
    {
        self::assertSame([], $this->zoneNamesOf(self::BETA_BAKERY, [self::LENA, self::OMAR]));
        self::assertSame([], $this->zoneNamesOf(self::ACME_FOODS, []));
        self::assertSame([], $this->users->timeZonesOfReads);
        self::assertSame([[self::LENA, self::OMAR]], $this->memberships->membersAmongReads);
    }

    #[Test]
    public function moreThanTheCapIsRefusedBeforeAnyRead(): void
    {
        try {
            $this->zoneNamesOf(self::ACME_FOODS, array_fill(0, MemberTimeZoneQueries::MAX_USER_IDS + 1, self::LENA));
            self::fail('101 ids should be refused.');
        } catch (\InvalidArgumentException $refusal) {
            self::assertSame('Ask for the time zones of at most 100 people at once.', $refusal->getMessage());
        }
        self::assertSame([], $this->memberships->membersAmongReads);
        self::assertSame([], $this->users->timeZonesOfReads);
        self::assertSame([self::LENA => 'Europe/Berlin'], $this->zoneNamesOf(self::ACME_FOODS, array_fill(0, MemberTimeZoneQueries::MAX_USER_IDS, self::LENA)));
    }

    /**
     * @param list<string> $userIds
     *
     * @return array<string, string>
     */
    private function zoneNamesOf(string $organizationId, array $userIds): array
    {
        $queries = new IdentityMemberTimeZoneQueries($this->memberships, $this->users);
        $zones = $queries->timeZonesOf(new OrganizationId($organizationId), array_map(static fn (string $userId): UserId => new UserId($userId), $userIds));

        return array_map(static fn (\DateTimeZone $zone): string => $zone->getName(), $zones);
    }
}
