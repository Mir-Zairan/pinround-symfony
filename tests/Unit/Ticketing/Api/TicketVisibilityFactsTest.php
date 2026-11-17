<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketVisibilityFacts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * SharedKernel's TicketTurnSnapshot cannot see these constants and checks their literal values, so a renamed or added
 * visibility must fail here rather than at the first move of such a ticket. ofSnapshot is checked with a different
 * person in every column, so a dropped or swapped column fails.
 */
final class TicketVisibilityFactsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function visibilities(): iterable
    {
        foreach (new \ReflectionClass(TicketVisibilityFacts::class)->getReflectionConstants() as $constant) {
            $visibility = $constant->getValue();
            self::assertIsString($visibility);
            yield $constant->getName() => [$visibility];
        }
    }

    #[Test]
    public function theVisibilitiesAreForEveryoneAndTeamOnly(): void
    {
        self::assertSame(['FOR_EVERYONE', 'TEAM_ONLY'], array_keys(iterator_to_array(self::visibilities())));
    }

    #[Test]
    #[DataProvider('visibilities')]
    public function aTicketTurnSnapshotAcceptsEveryVisibility(string $visibility): void
    {
        self::assertSame($visibility, self::snapshotWith($visibility)->visibility);
    }

    #[Test]
    public function aTicketTurnSnapshotRefusesAVisibilityThatIsNoConstant(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A ticket visibility must be all or team.');
        self::snapshotWith('org');
    }

    #[Test]
    public function ofSnapshotCarriesTheOrganizationAndEveryColumnOfTheSnapshot(): void
    {
        $acmeFoods = new OrganizationId('0197a3c0-0000-7000-8000-00000000c10a');
        $shop = new TeamId('0197a3c0-0000-7000-8000-00000000c1c2');
        $person = static fn (string $suffix): UserId => new UserId('0197a3c0-0000-7000-8000-00000000c2'.$suffix);
        $snapshot = new TicketTurnSnapshot(
            new TicketId('0197a3c0-0000-7000-8000-00000000c1d2'),
            '0197a3c0-0000-7000-8000-00000000c1e2',
            '0197a3c0-0000-7000-8000-00000000c1f2',
            'review',
            'check',
            $shop,
            $person('a1'),
            'Lena',
            [],
            [],
            TicketVisibilityFacts::TEAM_ONLY,
            'major',
            null,
            $person('b1'),
            $person('b2'),
            $person('b3'),
            $person('b4'),
            $person('b5'),
            $person('b6'),
        );

        self::assertEquals(
            new TicketVisibilityFacts($acmeFoods, TicketVisibilityFacts::TEAM_ONLY, $shop, $person('b1'), $person('b2'), $person('b3'), $person('b4'), $person('b5'), $person('b6')),
            TicketVisibilityFacts::ofSnapshot($acmeFoods, $snapshot),
        );
    }

    #[Test]
    public function ofSnapshotKeepsTheEmptyColumnsEmpty(): void
    {
        $acmeFoods = new OrganizationId('0197a3c0-0000-7000-8000-00000000c10a');

        self::assertEquals(
            new TicketVisibilityFacts($acmeFoods, TicketVisibilityFacts::FOR_EVERYONE, new TeamId('0197a3c0-0000-7000-8000-00000000c1c1'), new UserId('0197a3c0-0000-7000-8000-00000000c1b3')),
            TicketVisibilityFacts::ofSnapshot($acmeFoods, self::snapshotWith(TicketVisibilityFacts::FOR_EVERYONE)),
        );
    }

    private static function snapshotWith(string $visibility): TicketTurnSnapshot
    {
        return new TicketTurnSnapshot(
            new TicketId('0197a3c0-0000-7000-8000-00000000c1d1'),
            '0197a3c0-0000-7000-8000-00000000c1e1',
            '0197a3c0-0000-7000-8000-00000000c1f1',
            'todo',
            'todo',
            new TeamId('0197a3c0-0000-7000-8000-00000000c1c1'),
            new UserId('0197a3c0-0000-7000-8000-00000000c1b1'),
            'Lena',
            [],
            [],
            $visibility,
            'major',
            null,
            new UserId('0197a3c0-0000-7000-8000-00000000c1b3'),
        );
    }
}
