<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\Email;
use App\Identity\Domain\InvalidEmail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EmailTest extends TestCase
{
    #[Test]
    public function itTrimsAndLowerCasesTheAddress(): void
    {
        self::assertSame('omar@acme-foods.example', new Email('  Omar@Acme-Foods.Example ')->toString());
    }

    #[Test]
    public function addressesTypedInAnotherCaseAreEqual(): void
    {
        $email = new Email('mia@acme-foods.example');

        self::assertTrue($email->equals(new Email('MIA@acme-foods.example')));
        self::assertFalse($email->equals(new Email('mia.tester@acme-foods.example')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAnAddress(): iterable
    {
        yield 'no at sign' => ['not-an-email'];
        yield 'empty' => [''];
        yield 'blank' => ['   '];
        yield 'no domain' => ['omar@'];
        yield 'two addresses' => ['omar@acme-foods.example, mia@acme-foods.example'];
    }

    #[Test]
    #[DataProvider('notAnAddress')]
    public function itRefusesAnythingThatIsNotAnAddress(string $value): void
    {
        $this->expectException(InvalidEmail::class);

        new Email($value);
    }
}
