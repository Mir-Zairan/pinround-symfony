<?php

declare(strict_types=1);

namespace App\SharedKernel\UI;

/**
 * What a person is told when their draft may not be shared because another shared round of the ticket is still open
 * (ADR-0048, P14.T5): it names who gives the verdict. It is here because Ticketing's hand-off dialog and Review's draft
 * card both say it and may not see each other; each finds the person (the round's or whoever's turn it is) itself.
 */
final class RoundStillOpenReason
{
    /** Also Review's own text (RoundRefused::ROUND_STILL_OPEN), which names nobody. */
    private const string NOBODY_NAMED = 'Round %d is still open. Wait for its verdict before you share.';
    private const string VERDICT_GIVER_NAMED = 'Round %d is still open. %s gives a verdict first.';
    private const string READER_GIVES_THE_VERDICT = 'Round %d is still open. Give your verdict first.';

    /**
     * "Round 2 is still open. Lena gives a verdict first."; the reason that names nobody when the person is not known,
     * such as a turn that is a label's, or a name that is empty.
     *
     * @param int $roundNumber the open round's number, from 1
     */
    public static function of(int $roundNumber, ?string $verdictGiverFirstName): string
    {
        if (null === $verdictGiverFirstName || '' === trim($verdictGiverFirstName)) {
            return \sprintf(self::NOBODY_NAMED, $roundNumber);
        }

        return \sprintf(self::VERDICT_GIVER_NAMED, $roundNumber, $verdictGiverFirstName);
    }

    /**
     * For the person who gives the verdict themselves, such as a lead whose "Put on hold" leaves the round open.
     *
     * @param int $roundNumber the open round's number, from 1
     */
    public static function forTheVerdictGiver(int $roundNumber): string
    {
        return \sprintf(self::READER_GIVES_THE_VERDICT, $roundNumber);
    }
}
