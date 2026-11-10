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
     * @param array<string, string> $firstNames         display names by user id string; only the first word is used
     * @param UserId|null           $viewerId           the person looking; their own items read "you"
     * @param UserId|null           $askerId            who asked the open question; null while none is open
     * @param bool                  $isReviewBeforeTest the team's rule review_before_test
     */
    public function __construct(
        public string $stepName,
        public array $firstNames = [],
        public ?UserId $viewerId = null,
        public ?UserId $askerId = null,
        public bool $isReviewBeforeTest = true,
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
