<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Health;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One thing GET /health/ready proves before the app takes traffic (ADR-0029). The module that owns what is checked
 * implements it; autoconfiguration tags every implementation, and the readiness route runs them by tag priority
 * and stops at the first failure.
 */
#[AutoconfigureTag]
interface ReadinessCheck
{
    /**
     * Short and constant (for example `database`): the warning a failed check logs names it, so it must never hold
     * a host, a DSN or anything read at run time.
     */
    public function name(): string;

    /**
     * Returns when the dependency answered within about a second; throws otherwise. The exception message is never
     * logged or shown.
     */
    public function verify(): void;
}
