<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\Workflow\Api\NeedsYouKind;

/**
 * The short task of each turn kind as the whose-turn view lists it, one per kind with no names: PlainVerbFor's text
 * for a ticket with no asker, no assignee and in a doing step (Ticketing_UI may not import Workflow_Application, so
 * the texts are kept here and TurnKindVerbsTest holds them equal to PlainVerbFor's).
 */
final class TurnKindVerbs
{
    /**
     * @param string $kind a NeedsYouKind value, as WaitingTicket::$kind carries it
     *
     * @throws \ValueError for a kind that is not a NeedsYouKind value
     */
    public static function of(string $kind): string
    {
        return match (NeedsYouKind::from($kind)) {
            NeedsYouKind::Answer => 'Answer the question',
            NeedsYouKind::Check => 'Check the new ticket',
            NeedsYouKind::Review => 'Review the code',
            NeedsYouKind::Test => 'Test it',
            NeedsYouKind::Assign => 'Pick a developer',
            NeedsYouKind::Input => 'Give input',
            NeedsYouKind::Decide => 'Decide: go on or stop',
            NeedsYouKind::Build => 'Build it',
        };
    }
}
