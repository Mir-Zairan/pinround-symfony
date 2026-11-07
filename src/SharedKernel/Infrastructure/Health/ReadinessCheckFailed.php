<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Health;

/**
 * A dependency answered, but not in the state the app needs (a migration not executed, a partition missing), or a
 * check has nothing to reach (an empty endpoint).
 */
final class ReadinessCheckFailed extends \RuntimeException
{
}
