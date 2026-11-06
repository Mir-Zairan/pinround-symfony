<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Doctrine;

/**
 * Whether a lazy service such as the entity manager has been built. One that is not built yet holds nothing, so
 * code that only clears or inspects it skips it rather than build it (which would cost every request or message).
 */
final class LazyObjectState
{
    public static function isBuilt(object $service): bool
    {
        return !new \ReflectionClass($service)->isUninitializedLazyObject($service);
    }
}
