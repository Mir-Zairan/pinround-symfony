<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Doctrine;

use App\Identity\Infrastructure\Doctrine\DoctrineUsers;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DoctrineUsersTest extends TestCase
{
    #[Test]
    public function eachNextIdentityIsANewUuidV7(): void
    {
        $users = new DoctrineUsers(self::createStub(EntityManagerInterface::class));

        $first = $users->nextIdentity();
        $second = $users->nextIdentity();

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first->toString());
        self::assertFalse($first->equals($second));
    }
}
