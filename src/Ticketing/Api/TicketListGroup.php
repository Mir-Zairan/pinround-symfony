<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * One group of the tickets list (ADR-0014) with its steps.
 */
final readonly class TicketListGroup
{
    /**
     * Workflow's Group label and list hint by group code, in list order; this Api may not use Workflow's Domain, so
     * TicketListGroupTest keeps the copy equal to Group::inListOrder().
     *
     * @var array<string, array{string, string|null}>
     */
    public const array IN_LIST_ORDER = [
        'wait' => ['Waiting', 'Someone must answer first'],
        'check' => ['Checking', 'Being checked'],
        'doing' => ['Doing', 'Being built'],
        'todo' => ['To do', 'Not started'],
        'done' => ['Done', null],
    ];

    public const string DONE = 'done';

    /**
     * @param string               $code  one of the IN_LIST_ORDER keys
     * @param list<TicketListStep> $steps in the order the team page shows them
     *
     * @throws \InvalidArgumentException when the code is not a group code
     */
    public function __construct(public string $code, public array $steps)
    {
        if (!\array_key_exists($code, self::IN_LIST_ORDER)) {
            throw new \InvalidArgumentException('A list group\'s code is one of the five group codes.');
        }
    }

    public function label(): string
    {
        return self::IN_LIST_ORDER[$this->code][0];
    }

    /**
     * The one-line hint under the group header; null for Done.
     */
    public function hint(): ?string
    {
        return self::IN_LIST_ORDER[$this->code][1];
    }
}
