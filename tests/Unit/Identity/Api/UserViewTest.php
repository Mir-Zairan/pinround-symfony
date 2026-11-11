<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Api;

use App\Identity\Api\UserView;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class UserViewTest extends TestCase
{
    #[Test]
    #[TestWith(['Lena Fischer', 'Lena'])]
    #[TestWith(['  Omar  Haddad ', 'Omar'])]
    #[TestWith(['Mia', 'Mia'])]
    #[TestWith(['', ''])]
    public function theFirstNameIsTheFirstWordOfTheDisplayName(string $displayName, string $firstName): void
    {
        self::assertSame($firstName, new UserView(new UserId('0197a3c0-0000-7000-8000-0000000000c1'), $displayName)->firstName());
    }
}
