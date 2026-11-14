<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\Insight\Api\TurnGroupCursor;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\ListFilter;

/**
 * The Whose turn page's next-page TurnGroupCursor as the "Next page" link carries it, in AFTER_QUERY:
 * "<oldest wait in Unix seconds>.person.<user id>" or "<oldest wait in Unix seconds>.label.<label>". The turn read
 * model keeps waits in whole seconds, so the seconds name the exact wait. It is not signed: it holds only what the
 * viewer already saw on the last card, and a forged one only moves where the page starts.
 */
final class WhoseTurnCursorParameter
{
    public const string AFTER_QUERY = 'after';

    private const string AFTER = '/\A(\d{1,11})\.(person|label)\.(.+)\z/s';

    public static function valueOf(TurnGroupCursor $cursor): string
    {
        return $cursor->oldestWaitingSince->getTimestamp()
            .(null === $cursor->turnUserId ? '.label.'.$cursor->turnLabel : '.person.'.$cursor->turnUserId->toString());
    }

    /**
     * @return TurnGroupCursor|null null when the value is not one valueOf makes; a label is checked by
     *                              HomeQueries::whoseTurn
     */
    public static function cursorOf(string $after): ?TurnGroupCursor
    {
        if (1 !== preg_match(self::AFTER, $after, $parts)) {
            return null;
        }
        $oldestWaitingSince = new \DateTimeImmutable('@'.$parts[1]);
        if ('label' === $parts[2]) {
            return new TurnGroupCursor($oldestWaitingSince, null, $parts[3]);
        }

        if (!ListFilter::isCanonicalUuid($parts[3])) {
            return null;
        }
        try {
            return new TurnGroupCursor($oldestWaitingSince, new UserId($parts[3]), null);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
