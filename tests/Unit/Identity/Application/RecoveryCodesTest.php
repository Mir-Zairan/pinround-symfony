<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\RecoveryCodes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecoveryCodesTest extends TestCase
{
    // Two groups of 5 from Crockford's base32 in lower case: digits and letters without i, l, o and u.
    private const string FORMAT = '/^[0-9a-hjkmnp-tv-z]{5}-[0-9a-hjkmnp-tv-z]{5}$/';

    #[Test]
    public function thereAreTenDifferentCodesInTwoGroupsOfFiveUnambiguousCharacters(): void
    {
        $codes = RecoveryCodes::generate()->reveal();

        self::assertCount(10, $codes);
        self::assertSame($codes, array_values(array_unique($codes)));
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression(self::FORMAT, $code);
        }
    }

    #[Test]
    public function everyCharacterOfTheAlphabetTurnsUpAndNoOtherOne(): void
    {
        // 50 sets hold 5,000 characters; a character of 32 is missing from all of them with a chance below 1e-60.
        $seen = [];
        for ($set = 0; $set < 50; ++$set) {
            foreach (RecoveryCodes::generate()->reveal() as $code) {
                foreach (str_split(str_replace('-', '', $code)) as $character) {
                    $seen[$character] = true;
                }
            }
        }

        $characters = array_keys($seen);
        sort($characters);
        self::assertSame(str_split('0123456789abcdefghjkmnpqrstvwxyz'), array_map(strval(...), $characters));
    }

    #[Test]
    public function twoSetsShareNoCode(): void
    {
        self::assertSame([], array_intersect(RecoveryCodes::generate()->reveal(), RecoveryCodes::generate()->reveal()));
    }

    #[Test]
    public function theCodesNeitherDumpNorSerialize(): void
    {
        $recoveryCodes = RecoveryCodes::generate();

        self::assertStringNotContainsString($recoveryCodes->reveal()[0], print_r($recoveryCodes, true));
        $this->expectException(\LogicException::class);
        serialize($recoveryCodes);
    }
}
