<?php

declare(strict_types=1);

namespace App\Review\UI\RoundPage;

/**
 * What the side panel offers once every area of the round has a verdict (phase 5 design notes): a link to the ticket's
 * own verb or to the question composer. It never moves the ticket itself; the person makes the move there.
 */
final readonly class NextVerbOffer
{
    /**
     * @param string $sentence    for example "Every area passed."
     * @param string $linkLabel   for example "Pass it on SHOP-1" or "Ask Lena"
     * @param bool   $asksTheLead true when the link opens the question composer, false when it opens the ticket's Your
     *                            next step box
     */
    public function __construct(
        public string $sentence,
        public string $linkLabel,
        public bool $asksTheLead,
    ) {
    }
}
