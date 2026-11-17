<?php

declare(strict_types=1);

namespace App\Review\Domain;

/**
 * The stored round is no longer at the version it was read at: another request changed it in between, so nothing of
 * this change was written. The caller reads the round again before it tries again.
 */
final class RoundChanged extends \RuntimeException
{
}
