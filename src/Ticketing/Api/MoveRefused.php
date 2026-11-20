<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\UserId;

/**
 * The team's rules do not let the ticket go to that step, so it was not moved. The rule's note travels as its template
 * and people, as this Api may not hold Workflow's RuleNote; the caller rebuilds the note to show it.
 *
 * A move that would share the mover's draft while another shared round of the ticket stays open is refused with
 * roundStillOpen: its note is Review's reason, which names nobody, and openRoundNumber says which round, so a caller that
 * knows names may say who gives the verdict.
 */
final class MoveRefused extends \DomainException
{
    private ?int $openRoundNumber = null;
    private ?UserId $openRoundReadyForUserId = null;

    /**
     * @param string|null           $ruleNoteTemplate the note of the refusing rule with each person as a {placeholder}, or null when the rule has none
     * @param array<string, UserId> $ruleNotePeople   the person of each placeholder, keyed by its name without the braces
     */
    public function __construct(
        public readonly ?string $ruleNoteTemplate,
        public readonly array $ruleNotePeople = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct('The ticket was not moved.', 0, $previous);
    }

    /**
     * @param string      $reason                  the reason to show when no name is known, with no placeholder
     * @param int         $openRoundNumber         the round that stays open, from 1
     * @param UserId|null $openRoundReadyForUserId whose verdict it waits for, when Review knows it (the assignee for
     *                                             their findings round); null when it is whoever's turn it is
     */
    public static function roundStillOpen(string $reason, int $openRoundNumber, ?UserId $openRoundReadyForUserId, ?\Throwable $previous = null): self
    {
        $refused = new self($reason, [], $previous);
        $refused->openRoundNumber = $openRoundNumber;
        $refused->openRoundReadyForUserId = $openRoundReadyForUserId;

        return $refused;
    }

    /**
     * The shared round that blocks the share; null for any other refusal.
     */
    public function openRoundNumber(): ?int
    {
        return $this->openRoundNumber;
    }

    /**
     * Whose verdict that round waits for, when known (see roundStillOpen); null otherwise.
     */
    public function openRoundReadyForUserId(): ?UserId
    {
        return $this->openRoundReadyForUserId;
    }
}
