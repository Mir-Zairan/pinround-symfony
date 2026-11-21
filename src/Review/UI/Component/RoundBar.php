<?php

declare(strict_types=1);

namespace App\Review\UI\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The ticket page's round bar (P14.T7, ticket-page.md, Rounds): "Round N is open · Ready for <name> · Open round" while
 * a shared round of the ticket is open. An eager Turbo Frame that RoundsController's round bar route fills, so the
 * ticket page itself reads no round; Ticketing's header state embeds it by name with the ticket's key, so a move's
 * Turbo Stream and a live refresh, which both replace that state, load the bar again.
 */
#[AsTwigComponent(name: 'RoundBar', template: '@Review/components/RoundBar.html.twig')]
final class RoundBar
{
    public const string FRAME_ID = 'ticket-round-bar';
    /** With the open round's number. */
    public const string ROUND_OPEN = 'Round %d is open';
    /** With the first name of the person the round is ready for. */
    public const string READY_FOR = 'Ready for %s';
    public const string READY_FOR_YOU = 'Ready for you';
    public const string OPEN_ROUND = 'Open round';

    /** The ticket's key. */
    public string $key = '';
}
