<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Console;

/**
 * A demo seed step cannot run. The message is safe to print: it never holds a secret.
 */
final class DemoSeedRefused extends \RuntimeException
{
}
