<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\ListFilter;
use App\Ticketing\Api\TicketVisibility;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ListFilterTest extends TestCase
{
    private const string PROJECT = '0197a3c0-0000-7000-8000-00000000c011';

    #[Test]
    public function byDefaultItShowsEveryProjectWithoutDone(): void
    {
        $filter = new ListFilter(self::visibility());

        self::assertSame([null, false], [$filter->projectId, $filter->showsDone]);
    }

    #[Test]
    public function itKeepsOneProjectAndDone(): void
    {
        $filter = new ListFilter(self::visibility(), self::PROJECT, true);

        self::assertSame([self::PROJECT, true], [$filter->projectId, $filter->showsDone]);
    }

    #[Test]
    public function aProjectIdThatIsNoUuidIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ListFilter(self::visibility(), 'SHOP');
    }

    private static function visibility(): TicketVisibility
    {
        return TicketVisibility::for(new OrganizationId('0197a3c0-0000-7000-8000-00000000c0a1'), new UserId('0197a3c0-0000-7000-8000-00000000c0b1'), [], false, null);
    }
}
