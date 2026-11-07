<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\PasswordHash;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PasswordHashTest extends TestCase
{
    private const string ARGON2ID_HASH = '$argon2id$v=19$m=65536,t=4,p=1$NUkxU2sxQlFxbXpDaUZUQQ$suVp+0jzKZuqeONGVmUghGQxxI2vd02CMokdMtaQcfA';

    #[Test]
    public function itKeepsTheEncodedHash(): void
    {
        self::assertSame(self::ARGON2ID_HASH, new PasswordHash(self::ARGON2ID_HASH)->encoded());
    }

    #[Test]
    public function itRefusesAValueThatIsNotInCryptForm(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PasswordHash('lena-knows-this-one');
    }

    #[Test]
    public function itCannotBeTurnedIntoAString(): void
    {
        self::assertFalse(new \ReflectionClass(PasswordHash::class)->hasMethod('__toString'));
    }
}
