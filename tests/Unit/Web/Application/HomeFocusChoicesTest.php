<?php

declare(strict_types=1);

namespace App\Tests\Unit\Web\Application;

use App\Organization\Api\Roles;
use App\SharedKernel\Domain\TeamId;
use App\Web\Application\HomeFocusChoices;
use App\Web\Domain\HomeFocus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HomeFocusChoicesTest extends TestCase
{
    private const string SHOP = '0197a3c0-0000-7000-8000-0000000007d1';

    /**
     * @return iterable<string, array{Roles, list<HomeFocus>}>
     */
    public static function rolesAndTheirChips(): iterable
    {
        $shop = [new TeamId(self::SHOP)];

        yield 'member without org or team role' => [Roles::none(), [HomeFocus::All, HomeFocus::MyWork, HomeFocus::MyTeam]];
        yield 'team member' => [new Roles(false, false, [], $shop, []), [HomeFocus::All, HomeFocus::MyWork, HomeFocus::MyTeam]];
        yield 'manager' => [new Roles(false, true, [], [], []), [HomeFocus::All, HomeFocus::MyWork, HomeFocus::MyTeam, HomeFocus::AllTeams]];
        yield 'admin' => [new Roles(true, false, [], [], []), [HomeFocus::All, HomeFocus::MyWork, HomeFocus::MyTeam, HomeFocus::Admin]];
        yield 'admin and team lead' => [new Roles(true, false, $shop, [], []), [HomeFocus::All, HomeFocus::MyWork, HomeFocus::MyTeam, HomeFocus::Admin]];
        yield 'admin and manager' => [new Roles(true, true, [], [], []), [HomeFocus::All, HomeFocus::MyWork, HomeFocus::MyTeam, HomeFocus::AllTeams, HomeFocus::Admin]];
    }

    /**
     * @param list<HomeFocus> $expectedChips
     */
    #[Test]
    #[DataProvider('rolesAndTheirChips')]
    public function theChipsOfferedFollowTheRolesInDisplayOrder(Roles $roles, array $expectedChips): void
    {
        self::assertSame($expectedChips, HomeFocusChoices::offeredTo($roles));
        foreach (HomeFocus::cases() as $focus) {
            self::assertSame(\in_array($focus, $expectedChips, true), HomeFocusChoices::isOfferedTo($focus, $roles), $focus->value);
        }
    }
}
