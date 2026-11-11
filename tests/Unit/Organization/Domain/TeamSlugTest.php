<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\Domain;

use App\Organization\Domain\InvalidTeamSlug;
use App\Organization\Domain\TeamSlug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TeamSlugTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function namesAndTheirSlugs(): iterable
    {
        yield 'spaces' => ['Shop Web', 'shop-web'];
        yield 'letter case' => ['SHOP', 'shop'];
        yield 'leading and trailing spaces' => ["  Shop\n", 'shop'];
        yield 'punctuation runs become one hyphen' => ['Shop & Web -- Checkout!', 'shop-web-checkout'];
        yield 'hyphens at the ends go' => ['--Shop--', 'shop'];
        yield 'digits stay' => ['Team 42', 'team-42'];
        yield 'accents' => ['Café Crème', 'cafe-creme'];
        yield 'letters that become two' => ['Straße Æsir Þing Œuvre', 'strasse-aesir-thing-oeuvre'];
        yield 'Latin Extended-A' => ['Łódź Kraków', 'lodz-krakow'];
        yield 'other scripts become a hyphen' => ['Shop 東京 Web', 'shop-web'];
        yield 'exactly fifty' => [str_repeat('a', 50), str_repeat('a', 50)];
        yield 'cut to fifty' => [str_repeat('a', 51), str_repeat('a', 50)];
        yield 'no hyphen left at the cut' => [str_repeat('a', 49).' bcd', str_repeat('a', 49)];
    }

    #[Test]
    #[DataProvider('namesAndTheirSlugs')]
    public function aNameBecomesLowercaseLettersAndDigitsJoinedByHyphens(string $name, string $slug): void
    {
        self::assertSame($slug, TeamSlug::fromName($name)->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesWithNothingLeft(): iterable
    {
        yield 'empty' => [''];
        yield 'only punctuation' => ['!?-'];
        yield 'only another script' => ['東京'];
    }

    #[Test]
    #[DataProvider('namesWithNothingLeft')]
    public function aNameWithNothingLeftBecomesTeam(string $name): void
    {
        self::assertSame('team', TeamSlug::fromName($name)->toString());
    }

    /**
     * × and ÷ are the only characters from U+00C0 to U+017F that are no letters.
     */
    #[Test]
    public function everyLatin1AndLatinExtendedALetterBecomesPlainLowercaseLetters(): void
    {
        $unmapped = [];
        foreach (range(0xC0, 0x17F) as $codePoint) {
            $letter = mb_chr($codePoint, 'UTF-8');
            if (\in_array($letter, ['×', '÷'], true)) {
                continue;
            }
            if (1 !== preg_match('/^[a-z]{1,2}\z/', TeamSlug::TRANSLITERATIONS[$letter] ?? '')) {
                $unmapped[] = $letter;
            }
        }

        self::assertSame([], $unmapped);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function takenSlugsAndTheFirstFree(): iterable
    {
        yield 'none taken' => [[], 'shop-web'];
        yield 'only longer slugs taken' => [['shop-web-checkout'], 'shop-web'];
        yield 'the slug taken' => [['shop-web'], 'shop-web-2'];
        yield 'the slug and -2 taken' => [['shop-web-2', 'shop-web'], 'shop-web-3'];
        yield 'a gap is filled' => [['shop-web', 'shop-web-3'], 'shop-web-2'];
        yield 'a suffix taken without the slug' => [['shop-web-2'], 'shop-web'];
    }

    /**
     * @param list<string> $taken
     */
    #[Test]
    #[DataProvider('takenSlugsAndTheFirstFree')]
    public function firstFreeAmongTriesTheSlugThenEachSuffixFromTwo(array $taken, string $free): void
    {
        $takenSlugs = array_map(static fn (string $slug): TeamSlug => new TeamSlug($slug), $taken);

        self::assertSame($free, new TeamSlug('shop-web')->firstFreeAmong($takenSlugs)->toString());
    }

    #[Test]
    public function aFiftyCharacterSlugStillTakesASuffix(): void
    {
        $base = TeamSlug::fromName(str_repeat('a', 60));

        self::assertSame(str_repeat('a', 50).'-2', $base->firstFreeAmong([$base])->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notASlug(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['Shop'];
        yield 'leading hyphen' => ['-shop'];
        yield 'trailing hyphen' => ['shop-'];
        yield 'double hyphen' => ['shop--web'];
        yield 'space' => ['shop web'];
        yield 'LIKE wildcard' => ['shop_web'];
        yield 'accent' => ['café'];
        yield 'sixty-four characters' => [str_repeat('a', 64)];
    }

    #[Test]
    #[DataProvider('notASlug')]
    public function itRejectsAnythingButLowercaseLettersAndDigitsJoinedBySingleHyphens(string $value): void
    {
        $this->expectException(InvalidTeamSlug::class);
        $this->expectExceptionMessageIsOrContains('A team slug is lowercase letters and digits joined by single hyphens, at most 63 characters.');

        new TeamSlug($value);
    }

    #[Test]
    public function sixtyThreeCharactersAreAllowed(): void
    {
        self::assertSame(str_repeat('a', 63), new TeamSlug(str_repeat('a', 63))->toString());
    }
}
