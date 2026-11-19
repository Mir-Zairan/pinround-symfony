<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Application\Intake;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\IntakeCandidate;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Application\Intake\DuplicateSuggestions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DuplicateSuggestionsTest extends TestCase
{
    /**
     * P06.T2: the word rule runs on the search's top 20, not its top 3, so a lower-ranked ticket that passes still shows.
     */
    #[Test]
    public function theFifthRankedTicketShowsWhenTheFourAboveItFailTheWordRule(): void
    {
        $suggestions = new DuplicateSuggestions(new SimilarTicketsOnly([
            new IntakeCandidate('SHOP-1', 'Cart colours'),
            new IntakeCandidate('SHOP-2', 'Cart total'),
            new IntakeCandidate('SHOP-3', 'Total twice'),
            new IntakeCandidate('SHOP-4', 'Cart total counted wrong in seven different shops across europe today again'),
            new IntakeCandidate('SHOP-5', 'Cart total counted twice'),
        ]));

        self::assertSame(['SHOP-5'], array_map(static fn (IntakeCandidate $candidate): string => $candidate->key, $suggestions->for(self::reporter(), 'Cart total counted twice', '', [])));
    }

    #[Test]
    public function atMostThreeShowTheMostSharedWordsFirstAndAShortTitleAsksNothing(): void
    {
        $queries = new SimilarTicketsOnly([
            new IntakeCandidate('SHOP-1', 'Cart total counted'),
            new IntakeCandidate('SHOP-2', 'Cart total counted twice coupon'),
            new IntakeCandidate('SHOP-3', 'Cart total counted again'),
            new IntakeCandidate('SHOP-4', 'Cart total counted twice'),
            new IntakeCandidate('SHOP-5', 'Cart total counted here'),
        ]);
        $suggestions = new DuplicateSuggestions($queries);

        self::assertSame(['SHOP-2', 'SHOP-4', 'SHOP-1'], array_map(static fn (IntakeCandidate $candidate): string => $candidate->key, $suggestions->for(self::reporter(), 'Cart total counted twice', 'With a coupon.', [])));
        self::assertSame([], $suggestions->for(self::reporter(), 'Cart to ', '', []));
        self::assertSame(1, $queries->asked, 'The short title asked nothing.');
    }

    private static function reporter(): TicketVisibility
    {
        return TicketVisibility::for(new OrganizationId('0197a3c0-0000-7000-8000-0000000fb10a'), new UserId('0197a3c0-0000-7000-8000-0000000fb101'), [], false, null);
    }
}
