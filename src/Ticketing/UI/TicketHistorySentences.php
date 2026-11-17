<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\SharedKernel\Domain\UserId;
use App\Ticketing\Application\ReviewChange;
use App\Ticketing\Application\TicketHistoryEntry;
use App\Workflow\Api\NowNextContext;

/**
 * Words the ticket page's What happened (FR-TKT-12): each move and each checklist or verdict change as a plain sentence
 * with first names, step names, pin numbers and area names, never an id or a code, and the ticket's creation.
 */
final readonly class TicketHistorySentences
{
    /** For a step id the organization no longer has at all; a removed step keeps its name. */
    public const string UNKNOWN_STEP = 'a step that is gone';

    /** For a pin or area Review could not name when the change was recorded. */
    public const string UNKNOWN_PIN = 'a pin';
    public const string UNKNOWN_AREA = 'an area';

    /**
     * Review's codes (ChecklistCommands::STATE_*, VerdictCommands::PIN_* and AREA_*), written out here because
     * Ticketing_UI may not use Review's Api.
     */
    private const array CHECK_ITEM_STATES = ['fixed' => 'Fixed', 'ok' => 'Checked OK', 'not_fixed' => 'Not fixed'];
    private const array PIN_VERDICTS = ['fixed' => 'Fixed', 'still_wrong' => 'Still wrong'];
    private const array AREA_VERDICTS = ['pass' => 'Pass', 'needs_fix' => 'Needs fix', 'question' => 'Question'];

    /** @var array<string, string> step names by lower-case step id */
    private array $stepNames;

    /**
     * @param array<string, string> $displayNames display names by user id string; only the first word is used
     * @param array<string, string> $stepNames    step names by step id, in any case
     */
    public function __construct(private array $displayNames, array $stepNames)
    {
        $this->stepNames = array_change_key_case($stepNames, \CASE_LOWER);
    }

    /**
     * The people the entries name, once each, so the page can read their names with its other people.
     *
     * @param list<TicketHistoryEntry> $entries
     *
     * @return list<UserId>
     */
    public static function peopleOf(array $entries): array
    {
        $people = [];
        foreach ($entries as $entry) {
            foreach ([$entry->actorId, $entry->givenToId] as $userId) {
                if (null !== $userId) {
                    $people[$userId->toString()] = $userId;
                }
            }
        }

        return array_values($people);
    }

    /**
     * The prototype's words, "Created by Lena", rather than the moves' "Lena moved it…" form.
     */
    public function createdSentenceOf(UserId $reporterId): string
    {
        return 'Created by '.$this->firstNameOf($reporterId);
    }

    public function sentenceOf(TicketHistoryEntry $entry): string
    {
        $actor = $this->subjectOf($entry->actorId);
        if (null !== $entry->reviewChange) {
            return $actor.' '.self::reviewChangeWordsOf($entry->reviewChange);
        }
        if (null === $entry->toStepId) {
            throw new \LogicException('A move entry names the step it entered.');
        }
        if ($entry->isGive()) {
            if (null !== $entry->givenToId && $entry->givenToId->equals($entry->actorId)) {
                return $actor.' took it';
            }

            return $actor.' gave it to '.(null === $entry->givenToId ? PersonNames::UNKNOWN_PERSON : $this->firstNameOf($entry->givenToId));
        }
        $toStep = $this->stepNameOf($entry->toStepId);
        if (null === $entry->fromStepId || 0 === strcasecmp($entry->fromStepId, $entry->toStepId)) {
            return $actor.' moved it to '.$toStep;
        }

        return $actor.' moved it from '.$this->stepNameOf($entry->fromStepId).' to '.$toStep;
    }

    /**
     * The words after the actor, as the round pages label the states and verdicts (ticket-page.md, Rounds). An outcome
     * code these words do not know reads as a change with no outcome, never as the code.
     */
    private static function reviewChangeWordsOf(ReviewChange $change): string
    {
        $pin = null === $change->pinNumber ? self::UNKNOWN_PIN : 'pin '.$change->pinNumber;
        $area = null === $change->areaName ? self::UNKNOWN_AREA : 'area '.$change->areaName;

        return match ($change->kind) {
            ReviewChange::CHECK_ITEM_CHANGED => isset(self::CHECK_ITEM_STATES[$change->outcome])
                ? \sprintf('set %s on the checklist to %s', $pin, self::CHECK_ITEM_STATES[$change->outcome])
                : \sprintf('changed %s on the checklist', $pin),
            ReviewChange::PIN_VERDICT_SET => isset(self::PIN_VERDICTS[$change->outcome])
                ? \sprintf('set %s in %s to %s', $pin, $area, self::PIN_VERDICTS[$change->outcome])
                : \sprintf('changed the verdict on %s in %s', $pin, $area),
            ReviewChange::PIN_VERDICT_UNDONE => \sprintf('undid the verdict on %s in %s', $pin, $area),
            ReviewChange::AREA_VERDICT_SAVED => isset(self::AREA_VERDICTS[$change->outcome])
                ? \sprintf('gave %s: %s', $area, self::AREA_VERDICTS[$change->outcome])
                : \sprintf('changed the verdict on %s', $area),
            default => throw new \LogicException(\sprintf('A review change of kind "%s" has no words.', $change->kind)),
        };
    }

    /**
     * A name is kept as the person wrote it, even in lower case; only the fallback words start the sentence capitalized.
     */
    private function subjectOf(UserId $userId): string
    {
        return $this->knownFirstNameOf($userId) ?? ucfirst(PersonNames::UNKNOWN_PERSON);
    }

    private function firstNameOf(UserId $userId): string
    {
        return $this->knownFirstNameOf($userId) ?? PersonNames::UNKNOWN_PERSON;
    }

    private function knownFirstNameOf(UserId $userId): ?string
    {
        return NowNextContext::firstNameFrom($this->displayNames[$userId->toString()] ?? '');
    }

    private function stepNameOf(string $stepId): string
    {
        return $this->stepNames[strtolower($stepId)] ?? self::UNKNOWN_STEP;
    }
}
