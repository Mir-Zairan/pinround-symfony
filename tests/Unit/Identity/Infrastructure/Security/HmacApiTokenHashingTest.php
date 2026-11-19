<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Security;

use App\Identity\Infrastructure\Security\HmacApiTokenHashing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HmacApiTokenHashingTest extends TestCase
{
    private const string PEPPER = 'unit-test-pepper-of-at-least-32-bytes';

    #[Test]
    public function theHashIsTheRawHmacSha256OfTheTokenUnderThePepper(): void
    {
        $hash = new HmacApiTokenHashing(self::PEPPER)->hash('pr_example');

        self::assertSame(hash_hmac('sha256', 'pr_example', self::PEPPER, true), $hash);
        self::assertSame(32, \strlen($hash));
        self::assertSame($hash, new HmacApiTokenHashing(self::PEPPER)->hash('pr_example'));
        self::assertNotSame($hash, new HmacApiTokenHashing(self::PEPPER)->hash('pr_examplf'));
    }

    #[Test]
    public function aPepperOfExactlyThirtyTwoBytesIsTaken(): void
    {
        self::assertSame(32, \strlen(new HmacApiTokenHashing(str_repeat('p', 32))->hash('pr_example')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unusablePeppers(): iterable
    {
        yield 'empty' => [''];
        yield 'one byte short' => [str_repeat('p', 31)];
    }

    #[Test]
    #[DataProvider('unusablePeppers')]
    public function anEmptyOrShortPepperIsRefusedWithoutShowingIt(string $pepper): void
    {
        try {
            new HmacApiTokenHashing($pepper);
        } catch (\InvalidArgumentException $refusal) {
            self::assertSame('API_TOKEN_PEPPER must be at least 32 bytes; set it from the secret store.', $refusal->getMessage());

            return;
        }

        self::fail('A pepper shorter than 32 bytes was taken.');
    }
}
