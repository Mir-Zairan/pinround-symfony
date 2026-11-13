<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\UserId;

/**
 * The team's rules do not let the ticket go to that step, so it was not moved. The rule's note travels as its template
 * and people, as this Api may not hold Workflow's RuleNote; the caller rebuilds the note to show it.
 */
final class MoveRefused extends \DomainException
{
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
}
