<?php

declare(strict_types=1);

namespace App\Review\UI;

/**
 * A draft round form whose CSRF token is not valid, thrown inside the action's transaction so nothing of it is kept.
 */
final class DraftFormExpired extends \RuntimeException
{
}
