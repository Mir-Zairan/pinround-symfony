<?php

declare(strict_types=1);

namespace App\Review\Domain;

/**
 * Round 0 is the report, Round 1 the checklist made from its pins, later rounds are reviews; a draft is a person's
 * private self-check until they share it (ADR-0017).
 */
enum RoundKind: string
{
    case Report = 'report';
    case Checklist = 'checklist';
    case Review = 'review';
    case Draft = 'draft';
}
