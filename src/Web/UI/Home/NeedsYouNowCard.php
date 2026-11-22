<?php

declare(strict_types=1);

namespace App\Web\UI\Home;

/**
 * One card of Home's "Needs you now" (home.md, Needs you now): the ticket, the task it asks of the viewer, why it ranks
 * where it does and how long it waits.
 */
final readonly class NeedsYouNowCard
{
    /**
     * @param string             $url          the ticket page
     * @param string             $task         what the viewer does, for example "Review Omar’s code"
     * @param string             $openLabel    the button's text: "Check it" for a new ticket to check, else "Open ticket"
     * @param list<string>       $reasons      who is blocked and the urgent priority, for example ["Lena is blocked
     *                                         until you answer", "Critical"]; the template adds the wait
     * @param int                $waitDays     whole days since the turn came to the viewer, 0 or more
     * @param \DateTimeImmutable $waitingSince when the turn came to the viewer
     * @param string             $waitText     "today", "waiting 1 day" or "waiting 12 days"
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $url,
        public string $task,
        public string $openLabel,
        public array $reasons,
        public int $waitDays,
        public \DateTimeImmutable $waitingSince,
        public string $waitText,
    ) {
    }
}
