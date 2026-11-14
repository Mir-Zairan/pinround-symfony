<?php

declare(strict_types=1);

namespace App\Conversation\UI;

/**
 * A thread form whose CSRF token is not valid, thrown inside the action's transaction so nothing of it is kept.
 */
final class ThreadFormExpired extends \RuntimeException
{
}
