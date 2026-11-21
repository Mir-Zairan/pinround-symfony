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
     * @param TeamFacts|null        $team               for the reviewer and tester the team would pick when the ticket
     *                                                  has none yet; null in a context made for Now only
     *                                                  ({@see self::forNowOnly}), which Next refuses
     * @param bool                  $isReviewBeforeTest the team's rule review_before_test
     * @param UserId|null           $viewerId           the person looking; their own items read "you". My reports
     *                                                  passes it for its Now line; the ticket page passes none and names
     *                                                  everyone by first name
     * @param UserId|null           $askerId            who asked the open question; null while none is open
     */
    public function __construct(
        public string $stepName,
        public array $firstNames,
        public ?TeamFacts $team,
        public bool $isReviewBeforeTest,
        public ?UserId $viewerId = null,
        public ?UserId $askerId = null,
    ) {
    }

    /**
     * A context for the Now sentence alone, which reads the step, the names, the viewer and the asker but not the
     * team, so a caller that shows only Now (My reports, list_my_tickets) need not read the team. Next refuses it.
     *
     * @param array<string, string> $firstNames as the constructor takes them
     */
    public static function forNowOnly(string $stepName, array $firstNames, ?UserId $viewerId = null, ?UserId $askerId = null): self
    {
        return new self($stepName, $firstNames, null, false, $viewerId, $askerId);
    }

    /**
     * @throws \LogicException for a context made for Now only, so Next never names a made-up lead
     */
    public function teamForNext(): TeamFacts
    {
        return $this->team ?? throw new \LogicException('The Next sentence needs the team; this context was made for Now only.');
    }

    /**
     * @return string|null the first name, or null when the id has no name in this context
     */
    public function firstNameOf(UserId $userId): ?string
    {
        return self::firstNameFrom($this->firstNames[$userId->toString()] ?? '');
    }

    /**
     * The one rule for a first name, which the ticket page's pill and What happened share with these sentences.
     *
     * @return string|null the first word of the trimmed display name, or null for a blank one
     */
    public static function firstNameFrom(string $displayName): ?string
    {
        $trimmed = trim($displayName);

        return '' === $trimmed ? null : explode(' ', $trimmed, 2)[0];
    }
}
