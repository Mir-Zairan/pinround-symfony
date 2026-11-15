<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\SharedKernel\Domain\UserId;
use App\Ticketing\Application\TicketHistoryEntry;
use App\Workflow\Api\NowNextContext;

/**
 * Words the ticket page's What happened (FR-TKT-12): each move as a plain sentence with first names and step names,
 * never an id or a verb code, and the ticket's creation.
 */
final readonly class TicketHistorySentences
{
    /** For a step id the organization no longer has at all; a removed step keeps its name. */
    public const string UNKNOWN_STEP = 'a step that is gone';

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
