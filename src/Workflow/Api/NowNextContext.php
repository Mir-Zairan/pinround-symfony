<?php

declare(strict_types=1);

namespace App\Workflow\Api;

use App\SharedKernel\Domain\UserId;

/**
 * What the sentences need besides the ticket and its turn: the names, the viewer and one rule. The caller reads it.
 */
final readonly class NowNextContext
{
    /**
     * @param string                $stepName           the name of the ticket's current step, never its code
     * @param array<string, string> $firstNames         display names by user id string; only the first word is used.
     *                                                  Include the ticket's people and the team's lead, developers and
     *                                                  testers: a reviewer or tester the team picks is named from here
     * @param TeamFacts             $team               for the reviewer and tester the team would pick when the ticket
     *                                                  has none yet
     * @param bool                  $isReviewBeforeTest the team's rule review_before_test
     * @param UserId|null           $viewerId           the person looking; their own items read "you". No page passes
     *                                                  it yet: the lists keep "you" in their own rows, and the ticket
     *                                                  page names everyone by first name
     * @param UserId|null           $askerId            who asked the open question; null while none is open
     */
    public function __construct(
        public string $stepName,
        public array $firstNames,
        public TeamFacts $team,
        public bool $isReviewBeforeTest,
        public ?UserId $viewerId = null,
        public ?UserId $askerId = null,
    ) {
    }

    /**
     * @return string|null the first name, or null when the id has no name in this context
     */
    public function firstNameOf(UserId $userId): ?string
    {
        $displayName = trim($this->firstNames[$userId->toString()] ?? '');
        if ('' === $displayName) {
            return null;
        }

        return explode(' ', $displayName, 2)[0];
    }
}
