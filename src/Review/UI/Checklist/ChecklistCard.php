<?php

declare(strict_types=1);

namespace App\Review\UI\Checklist;

use App\Review\Api\CheckItemView;
use App\Review\Api\ChecklistCommands;
use App\Review\Api\ChecklistView;

/**
 * The checklist round as the Rounds section shows it to one viewer (P05.T6, ticket-page.md, Rounds): its header with
 * "k of n checked OK", and one row per pin of the report with the buttons that viewer may use.
 */
final readonly class ChecklistCard
{
    public const string DESCRIPTION = 'Every pin from the report must be fixed by the developer and checked by the tester.';
    public const string NO_NOTE = '(no note)';

    public const string MARK_FIXED_ID_PREFIX = 'checklist-fixed-';
    public const string OK_ID_PREFIX = 'checklist-ok-';

    /** What a change's Turbo Stream replaces. */
    public string $id;
    /** The id RoundCard's article is labelled by: "round-<round id>-heading", as RoundCards names the card. */
    public string $headingId;
    /** For example "Round 1 · Checklist from the report". */
    public string $headline;
    /** For example "1 of 2 checked OK". */
    public string $status;
    /** Whether any row shows a button, so the card needs a form token. */
    public bool $hasControls;

    /**
     * @param string                       $roundId the checklist round's id, lowercase
     * @param non-empty-list<ChecklistRow> $rows    in the checklist's order
     */
    public function __construct(
        public string $roundId,
        int $number,
        int $checkedOkCount,
        public array $rows,
    ) {
        $this->id = 'checklist-'.$roundId;
        $this->headingId = 'round-'.$roundId.'-heading';
        $this->headline = \sprintf('Round %d · Checklist from the report', $number);
        $this->status = \sprintf('%d of %d checked OK', $checkedOkCount, \count($rows));
        $this->hasControls = [] !== array_filter($rows, static fn (ChecklistRow $row): bool => null !== $row->firstControlId());
    }

    /**
     * @param bool $mayMarkFixed ChecklistPermission MARK_FIXED, granted on the ticket's items alike
     * @param bool $mayCheck     ChecklistPermission CHECK, granted on the ticket's items alike
     *
     * @throws \LogicException for an item state ChecklistCommands does not name
     */
    public static function of(ChecklistView $checklist, bool $mayMarkFixed, bool $mayCheck): self
    {
        $rows = array_map(static function (CheckItemView $item) use ($mayMarkFixed, $mayCheck): ChecklistRow {
            [$label, $class] = match ($item->state) {
                ChecklistCommands::STATE_OPEN => ['Open', 'check-state--open'],
                ChecklistCommands::STATE_FIXED => ['Fixed · to check', 'check-state--fixed'],
                ChecklistCommands::STATE_OK => ['Checked OK', 'check-state--ok'],
                ChecklistCommands::STATE_NOT_FIXED => ['Not fixed', 'check-state--not-fixed'],
                default => throw new \LogicException(\sprintf('A check item has no state "%s".', $item->state)),
            };

            return new ChecklistRow(
                $item->itemId,
                $item->pinNumber,
                '' === $item->note ? self::NO_NOTE : $item->note,
                $label,
                $class,
                $mayMarkFixed && \in_array($item->state, [ChecklistCommands::STATE_OPEN, ChecklistCommands::STATE_NOT_FIXED], true),
                $mayCheck && ChecklistCommands::STATE_FIXED === $item->state,
            );
        }, $checklist->items);

        return new self($checklist->roundId, $checklist->number, $checklist->checkedOkCount, $rows);
    }

    /**
     * Where focus goes once a change replaced the card, as the button clicked went with it: the first button of the
     * changed row or of a later one, else the card's first button, else its heading.
     */
    public function focusedControlIdAfterChangeOf(string $itemId): string
    {
        $firstControlId = null;
        $isPastTheChangedRow = false;
        foreach ($this->rows as $row) {
            $controlId = $row->firstControlId();
            $firstControlId ??= $controlId;
            $isPastTheChangedRow = $isPastTheChangedRow || $row->itemId === $itemId;
            if ($isPastTheChangedRow && null !== $controlId) {
                return $controlId;
            }
        }

        return $firstControlId ?? $this->headingId;
    }
}
