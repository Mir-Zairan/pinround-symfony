<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\Application;

use App\Organization\Api\ActorContext;
use App\Organization\Api\Roles;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Web\Application\ChooseHomeFocus;
use App\Web\Domain\HomeFocus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ChooseHomeFocusTest extends TestCase
{
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000007a2';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000007b1';

    private InMemoryHomeFocusPreferences $preferences;

    protected function setUp(): void
    {
        $this->preferences = new InMemoryHomeFocusPreferences();
    }

    #[Test]
    public function anOfferedChipIsSavedForTheActorAtTheGivenTime(): void
    {
        $at = new \DateTimeImmutable('2027-03-15 10:00:00 UTC');

        self::assertTrue(new ChooseHomeFocus($this->preferences)(self::actor(new Roles(false, true, [], [], [])), 'all_teams', $at));

        self::assertSame([self::OMAR => [HomeFocus::AllTeams, $at]], $this->preferences->saved);
    }

    #[Test]
    public function aSecondChoiceReplacesTheFirst(): void
    {
        $choose = new ChooseHomeFocus($this->preferences);
        $actor = self::actor(Roles::none());

        $choose($actor, 'my_team', new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));
        $choose($actor, 'my_work', new \DateTimeImmutable('2027-03-15 11:00:00 UTC'));

        self::assertSame(HomeFocus::MyWork, $this->preferences->focusOf(new UserId(self::OMAR)));
    }

    /**
     * @return iterable<string, array{Roles, string}>
     */
    public static function refusedChoices(): iterable
    {
        yield 'Admin by a non-admin' => [new Roles(false, true, [], [], []), 'admin'];
        yield 'All teams by a non-manager' => [new Roles(true, false, [], [], []), 'all_teams'];
        yield 'unknown value' => [new Roles(true, true, [], [], []), 'everything'];
        yield 'empty value' => [new Roles(true, true, [], [], []), ''];
        yield 'label instead of value' => [new Roles(true, true, [], [], []), 'My team'];
    }

    #[Test]
    #[DataProvider('refusedChoices')]
    public function aChipNotOfferedOrUnknownIsRefusedAndNothingIsSaved(Roles $roles, string $focus): void
    {
        self::assertFalse(new ChooseHomeFocus($this->preferences)(self::actor($roles), $focus, new \DateTimeImmutable('2027-03-15 10:00:00 UTC')));

        self::assertSame([], $this->preferences->saved);
    }

    private static function actor(Roles $roles): ActorContext
    {
        return new ActorContext(new UserId(self::OMAR), new OrganizationId(self::ACME_FOODS), $roles);
    }
}
