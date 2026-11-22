<?php

declare(strict_types=1);

namespace App\Review\Domain;

/**
 * Round 0 is the report and later rounds are reviews; the checklist made from the report's pins is shared with no
 * number (ADR-0048, note of 2026-11-22); a draft is a person's private self-check until they share it (ADR-0017).
 */
enum RoundKind: string
{
    case Report = 'report';
    case Checklist = 'checklist';
    case Review = 'review';
    case Draft = 'draft';
}
