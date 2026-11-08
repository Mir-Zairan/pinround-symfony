<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\Application;

use App\Organization\Api\ActorContext;
use App\Organization\Api\Roles;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Web\Application\ShowHomeFocus;
use App\Web\Domain\HomeFocus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ShowHomeFocusTest extends TestCase
{
    private const string SARA = '0197a3c0-0000-7000-8000-0000000007a1';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000007b1';

    private InMemoryHomeFocusPreferences $preferences;

    protected function setUp(): void
    {
        $this->preferences = new InMemoryHomeFocusPreferences();
    }

    #[Test]
    public function withNothingSavedHomeOpensOnAll(): void
    {
        self::assertSame(HomeFocus::All, $this->shownFocus(new Roles(true, true, [], [], [])));
    }

    #[Test]
    public function aSavedChipStillOfferedIsShown(): void
    {
        $this->save(HomeFocus::Admin);

        self::assertSame(HomeFocus::Admin, $this->shownFocus(new Roles(true, false, [], [], [])));
    }

    #[Test]
    public function aSavedChipNoLongerOfferedFallsBackToAllAndStaysSaved(): void
    {
        $this->save(HomeFocus::Admin);

        self::assertSame(HomeFocus::All, $this->shownFocus(new Roles(false, true, [], [], [])));
        self::assertSame(HomeFocus::Admin, $this->preferences->focusOf(new UserId(self::SARA)));
    }

    private function save(HomeFocus $focus): void
    {
        $this->preferences->remember(new UserId(self::SARA), $focus, new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));
    }

    private function shownFocus(Roles $roles): HomeFocus
    {
        return new ShowHomeFocus($this->preferences)(new ActorContext(new UserId(self::SARA), new OrganizationId(self::ACME_FOODS), $roles));
    }
}
