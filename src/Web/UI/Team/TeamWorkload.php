<?php

declare(strict_types=1);

namespace App\Web\UI\Team;

/**
 * The Team page's People section: its rows and the load a full bar stands for.
 */
final readonly class TeamWorkload
{
    /**
     * @param list<PersonWorkload> $people   by load, highest first, then by name
     * @param int                  $meterMax the highest load in the team, and 1 when nothing waits on anyone, so a bar
     *                                       is load ÷ highest load and an empty team draws empty bars
     */
    public function __construct(public array $people, public int $meterMax)
    {
    }
}
