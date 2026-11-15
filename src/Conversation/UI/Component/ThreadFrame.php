<?php

declare(strict_types=1);

namespace App\Conversation\UI\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The ticket page's Questions and comments section: its heading and a lazy Turbo Frame that ThreadController's thread
 * route fills, so the ticket page itself reads nothing of the thread (performance-and-scale.md, the page's query
 * budget). Without JavaScript the frame holds a link to the thread's own page.
 */
#[AsTwigComponent(name: 'ThreadFrame', template: '@Conversation/components/ThreadFrame.html.twig')]
final class ThreadFrame
{
    public const string SHOW_THREAD = 'Show questions and comments';

    /** The ticket's key. */
    public string $key = '';
}
