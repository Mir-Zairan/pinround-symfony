<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Domain;

use App\Ticketing\Domain\InvalidTicketKey;
use App\Ticketing\Domain\ProjectKey;
use App\Ticketing\Domain\TicketKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TicketKeyTest extends TestCase
{
    #[Test]
    public function theKeyIsTheProjectKeyAHyphenAndTheNumber(): void
    {
        $key = new TicketKey(new ProjectKey('SHOP'), 12);

        self::assertSame(['SHOP-12', 'SHOP', 12], [$key->toString(), $key->projectKey()->toString(), $key->number()]);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function numbersBelowOne(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-3];
    }

    #[Test]
    #[DataProvider('numbersBelowOne')]
    public function aNumberBelowOneIsRefused(int $number): void
    {
        $this->expectException(InvalidTicketKey::class);
        new TicketKey(new ProjectKey('SHOP'), $number);
    }

    #[Test]
    public function aKeyReadBackFromItsTextEqualsTheKeyItCameFrom(): void
    {
        $key = TicketKey::fromString('SHOP2-41');

        self::assertSame(['SHOP2', 41], [$key->projectKey()->toString(), $key->number()]);
        self::assertTrue($key->equals(new TicketKey(new ProjectKey('SHOP2'), 41)));
        self::assertFalse($key->equals(new TicketKey(new ProjectKey('SHOP2'), 42)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function textsThatAreNoKey(): iterable
    {
        yield 'no number' => ['SHOP-'];
        yield 'no hyphen' => ['SHOP12'];
        yield 'number zero' => ['SHOP-0'];
        yield 'leading zero' => ['SHOP-012'];
        yield 'lowercase project key' => ['shop-12'];
        yield 'trailing newline' => ["SHOP-12\n"];
        yield 'a uuid' => ['0197a3c0-0000-7000-8000-00000000e721'];
    }

    #[Test]
    #[DataProvider('textsThatAreNoKey')]
    public function aTextThatIsNoKeyIsRefused(string $text): void
    {
        $this->expectException(InvalidTicketKey::class);
        $this->expectExceptionMessageIsOrContains('A ticket key is a project key, a hyphen and a number, such as SHOP-12.');
        TicketKey::fromString($text);
    }
}
