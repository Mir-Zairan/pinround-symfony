<?php

declare(strict_types=1);

namespace App\Review\UI\Component;

use App\Ticketing\Api\MoveOnForm;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The round page's move-on buttons (P14.T8, ADR-0048) for the person whose turn it is on an open hand-off round: the
 * ticket page's own move-on verbs, whose move is the round's verdict and closes it. Each is Ticketing's verb form
 * without Turbo, so the move redirects to the ticket page with its toast and the Share draft question, when the person
 * has a draft, is a page that works without script.
 */
#[AsTwigComponent(name: 'MoveOnButtons', template: '@Review/components/MoveOnButtons.html.twig')]
final class MoveOnButtons
{
    public const string LABEL = 'Your verdict';
    /** With the ticket's key and the round's number. */
    public const string SENTENCE = 'Move %s on to close Round %d.';

    public MoveOnForm $form;

    public int $roundNumber;

    public function sentence(): string
    {
        return \sprintf(self::SENTENCE, $this->form->ticketKey, $this->roundNumber);
    }
}
