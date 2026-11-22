<?php

declare(strict_types=1);

namespace App\Review\UI\Checklist;

use App\Review\Api\CheckItemView;
use App\Review\Api\ChecklistCommands;
use App\Review\Application\Checklist\CheckItemPicture;
use App\Review\Application\Checklist\RoundChecklist;

/**
 * The check items of one round as the Rounds section shows them to one viewer, with "k of n checked OK" and one row per
 * pin with the buttons that viewer may use: the checklist round's card (P05.T6, ticket-page.md, Rounds), whose items are
 * the report's pins, or the "Checklist" part of a findings round's card under its pictures (ADR-0044, FR-RND-17).
 */
final readonly class ChecklistCard
{
    /** The checklist from the report has no round number (ADR-0048, note of 2026-11-22). */
    public const string HEADLINE = 'Checklist from the report';
    public const string DESCRIPTION = 'Every pin from the report must be fixed by the developer and checked by the tester.';
    public const string FINDINGS_HEADLINE = 'Checklist';
    public const string FINDINGS_DESCRIPTION = 'Every pin of these findings must be fixed by the developer and checked by the tester.';
    public const string NO_NOTE = '(no note)';

    public const string MARK_FIXED_ID_PREFIX = 'checklist-fixed-';
    public const string OK_ID_PREFIX = 'checklist-ok-';
    /** With the item's id: its pin's row in the round page's side panel, which a change's redirect names as fragment. */
    public const string ROUND_PIN_ID_PREFIX = 'round-check-';

    /** What a change's Turbo Stream replaces. */
    public string $id;
    /**
     * The checklist round's: "round-<round id>-heading", which RoundCard's article is labelled by, as RoundCards names
     * the card. A findings round's: "checklist-<round id>-heading", as the round's own heading labels its article.
     */
    public string $headingId;
    /** 3 for the checklist round's card heading; 4 under a findings round's own h3. */
    public int $headingLevel;
    /** HEADLINE or FINDINGS_HEADLINE. */
    public string $headline;
    /** DESCRIPTION or FINDINGS_DESCRIPTION. */
    public string $description;
    /** For example "1 of 2 checked OK". */
    public string $status;
    /** Whether any row shows a button, so the card needs a form token. */
    public bool $hasControls;

    /**
     * @param string                       $roundId    the round's id, lowercase
     * @param non-empty-list<ChecklistRow> $rows       in the checklist's order
     * @param bool                         $isFindings true for a findings round's items, false for the checklist round's
     */
    public function __construct(
        public string $roundId,
        int $checkedOkCount,
        public array $rows,
        public bool $isFindings = false,
    ) {
        $this->id = 'checklist-'.$roundId;
        $this->headingId = ($isFindings ? $this->id : 'round-'.$roundId).'-heading';
        $this->headingLevel = $isFindings ? 4 : 3;
        $this->headline = $isFindings ? self::FINDINGS_HEADLINE : self::HEADLINE;
        $this->description = $isFindings ? self::FINDINGS_DESCRIPTION : self::DESCRIPTION;
        $this->status = \sprintf('%d of %d checked OK', $checkedOkCount, \count($rows));
        $this->hasControls = [] !== array_filter($rows, static fn (ChecklistRow $row): bool => $row->hasControls());
    }

    /**
     * The checklist round's card, or the "Checklist" part of a findings round's card.
     *
     * @param bool $mayMarkFixed ChecklistPermission MARK_FIXED, granted on the ticket's items alike
     * @param bool $mayCheck     ChecklistPermission CHECK, granted on the ticket's items alike; it also undoes a check
     *
     * @throws \LogicException for an item state ChecklistCommands does not name, or an item without its picture
     */
    public static function of(RoundChecklist $roundChecklist, bool $mayMarkFixed, bool $mayCheck): self
    {
        $checklist = $roundChecklist->checklist;

        return new self($checklist->roundId, $checklist->checkedOkCount, self::rowsOf($roundChecklist, $mayMarkFixed, $mayCheck), $roundChecklist->isFindings);
    }

    /**
     * @return non-empty-list<ChecklistRow>
     */
    private static function rowsOf(RoundChecklist $roundChecklist, bool $mayMarkFixed, bool $mayCheck): array
    {
        return array_map(static function (CheckItemView $item) use ($roundChecklist, $mayMarkFixed, $mayCheck): ChecklistRow {
            [$label, $class] = match ($item->state) {
                ChecklistCommands::STATE_OPEN => ['Open', 'check-state--open'],
                ChecklistCommands::STATE_FIXED => ['Fixed · to check', 'check-state--fixed'],
                ChecklistCommands::STATE_OK => ['Checked OK', 'check-state--ok'],
                ChecklistCommands::STATE_NOT_FIXED => ['Not fixed', 'check-state--not-fixed'],
                default => throw new \LogicException(\sprintf('A check item has no state "%s".', $item->state)),
            };
            $picture = $roundChecklist->picturesByItemId[$item->itemId]
                ?? throw new \LogicException(\sprintf('Check item %s has no picture.', $item->itemId));

            return new ChecklistRow(
                $item->itemId,
                $item->pinNumber,
                self::pinLabelOf($picture, $item->pinNumber),
                '' === $item->note ? self::NO_NOTE : $item->note,
                $label,
                $class,
                $mayMarkFixed && \in_array($item->state, [ChecklistCommands::STATE_OPEN, ChecklistCommands::STATE_NOT_FIXED], true),
                $mayCheck && ChecklistCommands::STATE_FIXED === $item->state,
                $mayCheck && \in_array($item->state, [ChecklistCommands::STATE_OK, ChecklistCommands::STATE_NOT_FIXED], true),
                $picture->position,
            );
        }, $roundChecklist->checklist->items);
    }

    /**
     * "Screenshot 2 · pin 1", or "Screenshot 2 · Checkout · pin 1" for a picture with a title: the number keeps two
     * pictures with one title apart, as RoundCards numbers a draft's pictures.
     */
    private static function pinLabelOf(CheckItemPicture $picture, int $pinNumber): string
    {
        return null === $picture->title
            ? \sprintf('Screenshot %d · pin %d', $picture->position + 1, $pinNumber)
            : \sprintf('Screenshot %d · %s · pin %d', $picture->position + 1, $picture->title, $pinNumber);
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
