<?php

declare(strict_types=1);

namespace App\Review\Domain;

/**
 * Where one line of a checklist round stands: open until someone marks it fixed, then confirmed OK or sent back as not
 * fixed, from where it can be marked fixed again (CheckItem). Undoing a check, OK or not fixed, puts it back to fixed.
 */
enum CheckItemState: string
{
    case Open = 'open';
    case Fixed = 'fixed';
    case Ok = 'ok';
    case NotFixed = 'not_fixed';
}
