<?php

declare(strict_types=1);

namespace App\Web\UI\Team;

/**
 * One row of the Team page's People section: a lead or member and the open tickets of the team that wait on them, as
 * the viewer may count them.
 */
final readonly class PersonWorkload
{
    /**
     * @param string $searchText the name in lower case, which the page's search matches the typed words against
     * @param string $jobLine    "Team lead · developer", "Tester", "Member" and the like
     * @param bool   $isViewer   whether the person is the one looking at the page, who reads "(you)" after the name
     */
    public function __construct(
        public string $name,
        public string $searchText,
        public string $jobLine,
        public bool $isViewer,
        public int $waitingTickets,
        public WorkloadLevel $level,
    ) {
    }
}
