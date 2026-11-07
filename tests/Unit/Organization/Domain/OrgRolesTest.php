<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Domain;

use App\Organization\Domain\OrgRole;
use App\Organization\Domain\OrgRoles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OrgRolesTest extends TestCase
{
    #[Test]
    public function aRoleGivenTwiceIsKeptOnceInTheEnumOrder(): void
    {
        $roles = new OrgRoles(OrgRole::Manager, OrgRole::Admin, OrgRole::Manager);

        self::assertSame([OrgRole::Admin, OrgRole::Manager], $roles->toList());
        self::assertSame(['admin', 'manager'], $roles->values());
    }

    #[Test]
    public function noRolesIsAPlainUserWhoIsNeitherAdminNorManager(): void
    {
        $roles = new OrgRoles();

        self::assertSame([], $roles->values());
        self::assertFalse($roles->isAdmin());
        self::assertFalse($roles->has(OrgRole::Manager));
    }

    #[Test]
    public function aManagerAloneIsNoAdmin(): void
    {
        $roles = new OrgRoles(OrgRole::Manager);

        self::assertTrue($roles->has(OrgRole::Manager));
        self::assertFalse($roles->has(OrgRole::Admin));
        self::assertFalse($roles->isAdmin());
    }

    #[Test]
    public function anAdminIsAdmin(): void
    {
        self::assertTrue(new OrgRoles(OrgRole::Admin)->isAdmin());
    }

    #[Test]
    public function rolesAreEqualWhateverTheOrderTheyWereGivenIn(): void
    {
        self::assertTrue(new OrgRoles(OrgRole::Manager, OrgRole::Admin)->equals(new OrgRoles(OrgRole::Admin, OrgRole::Manager)));
        self::assertFalse(new OrgRoles(OrgRole::Admin)->equals(new OrgRoles(OrgRole::Admin, OrgRole::Manager)));
    }

    #[Test]
    public function storedValuesBecomeRolesAndAnUnknownOneIsRefused(): void
    {
        self::assertSame([OrgRole::Admin, OrgRole::Manager], OrgRoles::fromValues(['manager', 'admin'])->toList());

        $this->expectException(\ValueError::class);
        OrgRoles::fromValues(['owner']);
    }
}
