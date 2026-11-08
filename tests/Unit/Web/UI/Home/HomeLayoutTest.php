<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\UI\Home;

use App\Organization\Api\Roles;
use App\SharedKernel\Domain\TeamId;
use App\Web\UI\Home\HomeLayout;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HomeLayoutTest extends TestCase
{
    private const string SHOP = '0194a000-0000-7000-8000-000000000001';

    /**
     * @return iterable<string, array{Roles, HomeLayout}>
     */
    public static function people(): iterable
    {
        $shop = new TeamId(self::SHOP);
        yield 'no role at all' => [Roles::none(), HomeLayout::Work];
        yield 'lead' => [new Roles(false, false, [$shop], [], []), HomeLayout::Work];
        yield 'member' => [new Roles(false, false, [], [$shop], []), HomeLayout::Work];
        yield 'viewer only' => [new Roles(false, false, [], [], [$shop]), HomeLayout::Work];
        yield 'admin who leads' => [new Roles(true, false, [$shop], [], []), HomeLayout::Work];
        yield 'manager who is a member' => [new Roles(false, true, [], [$shop], []), HomeLayout::Work];
        yield 'manager with no team' => [new Roles(false, true, [], [], []), HomeLayout::Manager];
        yield 'manager who is a viewer' => [new Roles(false, true, [], [], [$shop]), HomeLayout::Manager];
        yield 'admin and manager with no team' => [new Roles(true, true, [], [], []), HomeLayout::Manager];
        yield 'admin with no team' => [new Roles(true, false, [], [], []), HomeLayout::Admin];
        yield 'admin who is a viewer' => [new Roles(true, false, [], [], [$shop]), HomeLayout::Work];
    }

    #[Test]
    #[DataProvider('people')]
    public function theRolesDecideWhichHomeOpens(Roles $roles, HomeLayout $expected): void
    {
        self::assertSame($expected, HomeLayout::of($roles));
    }
}
