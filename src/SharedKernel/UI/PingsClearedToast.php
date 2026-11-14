<?php

declare(strict_types=1);

namespace App\SharedKernel\UI;

/**
 * The suffix an action's toast gets when it cleared the actor's pings on the ticket (FR-PNG-05). It is here because
 * Ticketing's and Conversation's UI both add it and may not see each other.
 */
final class PingsClearedToast
{
    /**
     * "Code approved. Lena’s turn to test. 2 pings on this ticket cleared."; the toast as it is when none were cleared.
     *
     * @param int $clearedCount what PingClearing::clearForActor returned for the action
     *
     * @throws \InvalidArgumentException for a count below 0
     */
    public static function withClearedPings(string $toast, int $clearedCount): string
    {
        if ($clearedCount < 0) {
            throw new \InvalidArgumentException(\sprintf('A cleared ping count is 0 or more, %d given.', $clearedCount));
        }
        if (0 === $clearedCount) {
            return $toast;
        }

        return \sprintf(1 === $clearedCount ? '%s %d ping on this ticket cleared.' : '%s %d pings on this ticket cleared.', $toast, $clearedCount);
    }
}
