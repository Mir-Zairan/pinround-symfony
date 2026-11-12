<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Application;

use App\Ticketing\Application\NextTicketNumber;
use App\Ticketing\Domain\ProjectId;

/**
 * Hands out the given number to every caller and records which projects asked.
 */
final class FixedNextTicketNumber implements NextTicketNumber
{
    /** @var list<string> project ids, in call order */
    public array $takenFor = [];

    /**
     * @param positive-int $number
     */
    public function __construct(private readonly int $number)
    {
    }

    public function takeFor(ProjectId $projectId): int
    {
        $this->takenFor[] = $projectId->toString();

        return $this->number;
    }
}
