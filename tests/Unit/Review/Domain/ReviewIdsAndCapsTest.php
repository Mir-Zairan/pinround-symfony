<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Domain;

use App\Review\Api\DraftScreenshotCommands;
use App\Review\Api\RoundCommands;
use App\Review\Domain\AiPinState;
use App\Review\Domain\AreaId;
use App\Review\Domain\AreaVerdict;
use App\Review\Domain\CheckItemId;
use App\Review\Domain\CheckItemState;
use App\Review\Domain\DraftDecision;
use App\Review\Domain\Pin;
use App\Review\Domain\PinId;
use App\Review\Domain\PinSource;
use App\Review\Domain\PinVerdict;
use App\Review\Domain\RoundId;
use App\Review\Domain\RoundKind;
use App\Review\Domain\RoundState;
use App\Review\Domain\Screenshot;
use App\Review\Domain\ScreenshotId;
use App\Review\Domain\ScreenshotVerdict;
use App\Review\Domain\VariantLabel;
use App\Review\UI\Media\RoundMediaTarget;
use App\Review\UI\PinView;
use App\Review\UI\ScreenshotView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReviewIdsAndCapsTest extends TestCase
{
    private const string VALID = '0197A3C0-0000-7000-8000-000000000E01';

    /**
     * @return iterable<string, array{class-string<RoundId|ScreenshotId|PinId|AreaId|CheckItemId>, string}>
     */
    public static function idClasses(): iterable
    {
        yield 'round' => [RoundId::class, 'A round id must be a canonical UUIDv7.'];
        yield 'screenshot' => [ScreenshotId::class, 'A screenshot id must be a canonical UUIDv7.'];
        yield 'pin' => [PinId::class, 'A pin id must be a canonical UUIDv7.'];
        yield 'area' => [AreaId::class, 'An area id must be a canonical UUIDv7.'];
        yield 'check item' => [CheckItemId::class, 'A check item id must be a canonical UUIDv7.'];
    }

    /**
     * @return iterable<string, array{class-string<RoundId|ScreenshotId|PinId|AreaId|CheckItemId>}>
     */
    public static function idClassNames(): iterable
    {
        foreach (self::idClasses() as $name => [$idClass]) {
            yield $name => [$idClass];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function valuesThatAreNotUuidV7(): iterable
    {
        yield 'empty' => [''];
        yield 'version 4' => ['0197a3c0-0000-4000-8000-000000000e01'];
        yield 'variant bits 00xx' => ['0197a3c0-0000-7000-0000-000000000e01'];
        yield 'trailing newline' => ['0197a3c0-0000-7000-8000-000000000e01'."\n"];
        yield 'no hyphens' => ['0197a3c0000070008000000000000e01'];
        yield 'braces' => ['{0197a3c0-0000-7000-8000-000000000e01}'];
    }

    /**
     * @param class-string<RoundId|ScreenshotId|PinId|AreaId|CheckItemId> $idClass
     */
    #[Test]
    #[DataProvider('idClassNames')]
    public function anIdIsKeptInLowercaseAndEqualsTheSameValueInAnyCase(string $idClass): void
    {
        $id = new $idClass(self::VALID);

        self::assertSame('0197a3c0-0000-7000-8000-000000000e01', $id->toString());
        self::assertTrue($id->equals(new $idClass('0197a3c0-0000-7000-8000-000000000e01')));
        self::assertFalse($id->equals(new $idClass('0197a3c0-0000-7000-8000-000000000e02')));
    }

    /**
     * @param class-string<RoundId|ScreenshotId|PinId|AreaId|CheckItemId> $idClass
     */
    #[Test]
    #[DataProvider('idClasses')]
    public function anIdRefusesEveryValueThatIsNotACanonicalUuidV7(string $idClass, string $message): void
    {
        $refused = 0;
        foreach (self::valuesThatAreNotUuidV7() as [$value]) {
            try {
                new $idClass($value);
                self::fail(\sprintf('%s accepted %s.', $idClass, var_export($value, true)));
            } catch (\InvalidArgumentException $invalid) {
                self::assertSame($message, $invalid->getMessage());
                ++$refused;
            }
        }

        self::assertSame(6, $refused);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function capsOfDomainAndComponents(): iterable
    {
        yield 'screenshot title' => [Screenshot::TITLE_MAX_LENGTH, ScreenshotView::TITLE_MAX_LENGTH];
        yield 'screenshot summary' => [Screenshot::SUMMARY_MAX_LENGTH, ScreenshotView::SUMMARY_MAX_LENGTH];
        yield 'pin note' => [Pin::NOTE_MAX_LENGTH, PinView::NOTE_MAX_LENGTH];
        yield 'pins on a screenshot, as the round commands take them' => [Screenshot::MAX_PINS, RoundCommands::MAX_PINS_PER_SCREENSHOT];
        yield 'screenshot title, as the round commands take it' => [Screenshot::TITLE_MAX_LENGTH, RoundCommands::MAX_TITLE_LENGTH];
        yield 'screenshot summary, as the round commands take it' => [Screenshot::SUMMARY_MAX_LENGTH, RoundCommands::MAX_SUMMARY_LENGTH];
        yield 'pin note, as the round commands take it' => [Pin::NOTE_MAX_LENGTH, RoundCommands::MAX_PIN_NOTE_LENGTH];
    }

    #[Test]
    #[DataProvider('capsOfDomainAndComponents')]
    public function theDomainCapEqualsTheCapOfTheComponent(int $domainCap, int $componentCap): void
    {
        self::assertSame($componentCap, $domainCap);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function uploadTargetKindsOfApiAndComponent(): iterable
    {
        yield 'a draft round, as add_screenshot finds it' => [DraftScreenshotCommands::UPLOAD_TARGET_KIND, RoundMediaTarget::KIND];
    }

    #[Test]
    #[DataProvider('uploadTargetKindsOfApiAndComponent')]
    public function theUploadTargetKindOfTheApiEqualsTheKindOfTheRoundMediaTarget(string $apiKind, string $componentKind): void
    {
        self::assertSame($componentKind, $apiKind);
    }

    #[Test]
    public function theEnumsKeepTheValuesTheTablesStore(): void
    {
        self::assertSame(['report', 'checklist', 'review', 'draft'], self::valuesOf(RoundKind::cases()));
        self::assertSame(['open', 'closed'], self::valuesOf(RoundState::cases()));
        self::assertSame(['human', 'ai'], self::valuesOf(PinSource::cases()));
        self::assertSame(['suggested', 'kept', 'dismissed'], self::valuesOf(AiPinState::cases()));
        self::assertSame(['fixed', 'still_wrong'], self::valuesOf(PinVerdict::cases()));
        self::assertSame(['looks_right', 'not_right'], self::valuesOf(ScreenshotVerdict::cases()));
        self::assertSame(['before', 'after'], self::valuesOf(VariantLabel::cases()));
        self::assertSame(['pass', 'needs_fix', 'question'], self::valuesOf(AreaVerdict::cases()));
        self::assertSame(['open', 'fixed', 'ok', 'not_fixed'], self::valuesOf(CheckItemState::cases()));
        self::assertSame(['shared', 'kept_private'], self::valuesOf(DraftDecision::cases()));
    }

    /**
     * @param list<\BackedEnum> $cases
     *
     * @return list<int|string>
     */
    private static function valuesOf(array $cases): array
    {
        return array_map(static fn (\BackedEnum $case): int|string => $case->value, $cases);
    }
}
