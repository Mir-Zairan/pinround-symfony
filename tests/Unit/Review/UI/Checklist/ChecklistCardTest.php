<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI\Checklist;

use App\Review\Api\CheckItemView;
use App\Review\Api\ChecklistCommands;
use App\Review\Api\ChecklistView;
use App\Review\UI\Checklist\ChecklistCard;
use App\Review\UI\Checklist\ChecklistRow;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ChecklistCard's rows and where focus goes after a change, on a checklist whose items are named by their pin number.
 */
final class ChecklistCardTest extends TestCase
{
    private const string ROUND = '0197a3c0-0000-7000-8000-000000002c01';

    #[Test]
    public function eachStateHasItsChipAndTheButtonsOfThePersonWhoActsOnIt(): void
    {
        $checklist = self::checklistOf([
            1 => ChecklistCommands::STATE_OPEN,
            2 => ChecklistCommands::STATE_FIXED,
            3 => ChecklistCommands::STATE_OK,
            4 => ChecklistCommands::STATE_NOT_FIXED,
        ]);

        $card = ChecklistCard::of($checklist, true, true);

        self::assertSame(
            [
                ['Open', 'check-state--open', true, false],
                ['Fixed · to check', 'check-state--fixed', false, true],
                ['Checked OK', 'check-state--ok', false, false],
                ['Not fixed', 'check-state--not-fixed', true, false],
            ],
            array_map(static fn (ChecklistRow $row): array => [$row->stateLabel, $row->stateClass, $row->mayMarkFixed, $row->mayCheck], $card->rows),
        );
        self::assertSame('1 of 4 checked OK', $card->status);
        self::assertSame('Round 1 · Checklist from the report', $card->headline);
    }

    /**
     * A person who is both the ticket's assignee and its tester marks pin 2 fixed: the row now offers its own OK, and
     * focus stays on it, not on pin 1's or pin 3's Mark fixed.
     */
    #[Test]
    public function focusStaysOnTheChangedRowWhenItStillHasAButton(): void
    {
        $card = ChecklistCard::of(self::checklistOf([
            1 => ChecklistCommands::STATE_OPEN,
            2 => ChecklistCommands::STATE_FIXED,
            3 => ChecklistCommands::STATE_OPEN,
        ]), true, true);

        self::assertSame(ChecklistCard::OK_ID_PREFIX.self::itemId(2), $card->focusedControlIdAfterChangeOf(self::itemId(2)));
    }

    #[Test]
    public function focusGoesToALaterRowsButtonWhenTheChangedRowHasNone(): void
    {
        $card = ChecklistCard::of(self::checklistOf([
            1 => ChecklistCommands::STATE_OPEN,
            2 => ChecklistCommands::STATE_FIXED,
            3 => ChecklistCommands::STATE_OK,
            4 => ChecklistCommands::STATE_NOT_FIXED,
        ]), true, false);

        self::assertSame(ChecklistCard::MARK_FIXED_ID_PREFIX.self::itemId(4), $card->focusedControlIdAfterChangeOf(self::itemId(2)));
    }

    #[Test]
    public function focusGoesToTheCardsFirstButtonWhenNoLaterRowHasOne(): void
    {
        $card = ChecklistCard::of(self::checklistOf([
            1 => ChecklistCommands::STATE_OK,
            2 => ChecklistCommands::STATE_FIXED,
            3 => ChecklistCommands::STATE_FIXED,
            4 => ChecklistCommands::STATE_OPEN,
        ]), false, true);

        self::assertSame(ChecklistCard::OK_ID_PREFIX.self::itemId(2), $card->focusedControlIdAfterChangeOf(self::itemId(4)));
    }

    #[Test]
    public function focusGoesToTheHeadingWhenTheCardHasNoButtonLeft(): void
    {
        $card = ChecklistCard::of(self::checklistOf([1 => ChecklistCommands::STATE_FIXED, 2 => ChecklistCommands::STATE_OK]), true, false);

        self::assertFalse($card->hasControls);
        self::assertSame('round-'.self::ROUND.'-heading', $card->focusedControlIdAfterChangeOf(self::itemId(1)));
    }

    #[Test]
    public function anItemOfNoRowFocusesTheCardsFirstButton(): void
    {
        $card = ChecklistCard::of(self::checklistOf([1 => ChecklistCommands::STATE_OK, 2 => ChecklistCommands::STATE_OPEN]), true, false);

        self::assertSame(ChecklistCard::MARK_FIXED_ID_PREFIX.self::itemId(2), $card->focusedControlIdAfterChangeOf('0197a3c0-0000-7000-8000-000000002cff'));
    }

    #[Test]
    public function anItemStateTheCommandsDoNotNameIsALogicError(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A check item has no state "reopened".');

        ChecklistCard::of(self::checklistOf([1 => ChecklistCommands::STATE_OPEN, 2 => 'reopened']), true, true);
    }

    /**
     * @param non-empty-array<int, string> $statesByPinNumber
     */
    private static function checklistOf(array $statesByPinNumber): ChecklistView
    {
        return new ChecklistView(self::ROUND, 1, array_map(
            static fn (int $pinNumber, string $state): CheckItemView => new CheckItemView(self::itemId($pinNumber), $pinNumber, 'Note '.$pinNumber, $state),
            array_keys($statesByPinNumber),
            $statesByPinNumber,
        ));
    }

    private static function itemId(int $pinNumber): string
    {
        return \sprintf('0197a3c0-0000-7000-8000-%012d', 2100 + $pinNumber);
    }
}
