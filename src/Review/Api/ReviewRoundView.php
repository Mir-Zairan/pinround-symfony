<?php

declare(strict_types=1);

namespace App\Review\Api;

use App\SharedKernel\Domain\UserId;

/**
 * One shared round of a ticket for the review round page: the header facts and its screen areas.
 */
final readonly class ReviewRoundView
{
    /**
     * @param int                $number          0 for the report, from 1 for the rounds after it
     * @param string             $kind            report, checklist, review or draft (a shared draft)
     * @param UserId             $ownerId         who made and shared the round
     * @param \DateTimeImmutable $sharedAt        when it was shared, the "shot" time of the page's header
     * @param string             $state           open or closed
     * @param list<AreaView>     $areas           by position; none for a round with no screen areas, such as the report
     * @param bool               $isSharedInAMove whether its owner shared it in a move, at hand-off; false for a draft
     *                                            shared with "Share now" and for every other kind
     */
    public function __construct(
        public string $id,
        public int $number,
        public string $kind,
        public UserId $ownerId,
        public \DateTimeImmutable $sharedAt,
        public string $state,
        public array $areas,
        public bool $isSharedInAMove,
    ) {
    }
}
