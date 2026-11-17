<?php

declare(strict_types=1);

namespace App\Review\Domain;

/**
 * What the owner of a draft round chose when the ticket left them (ADR-0017, hand-off): share it with the team or keep
 * it private. Asked once per draft.
 */
enum DraftDecision: string
{
    case Shared = 'shared';
    case KeptPrivate = 'kept_private';
}
