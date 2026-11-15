<?php

declare(strict_types=1);

namespace App\Review\UI\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The ticket page's Rounds section (P05.T4, ticket-page.md, Rounds): its heading and a lazy Turbo Frame that
 * RoundsController's rounds route fills, so the ticket page itself reads no round (performance-and-scale.md, the page's
 * query budget). Ticketing's UI may not use Review's Api, so the ticket page embeds it by name, as it does Conversation's
 * ThreadFrame, and passes only the ticket's key. Without JavaScript the frame holds a link to the rounds' own page.
 */
#[AsTwigComponent(name: 'RoundsSection', template: '@Review/components/RoundsSection.html.twig')]
final class RoundsSection
{
    public const string FRAME_ID = 'ticket-rounds';
    public const string EMPTY = 'No rounds yet. The developer starts round 1 from Claude Code: it shoots the screens and puts them here.';
    public const string SHOW_ROUNDS = 'Show rounds';

    /** The ticket's key. */
    public string $key = '';
}
