<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Console;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One part of the demo data, owned by the module that knows its tables. `app:demo:seed` runs every step, highest
 * `#[AsTaggedItem(priority: ...)]` first, so a step that needs another module's rows takes a lower priority.
 */
#[AutoconfigureTag('app.demo_seed_step')]
interface DemoSeedStep
{
    /**
     * Creates what is missing and leaves what exists as it is, so a second run changes nothing.
     *
     * @return string one line for the console: what was created and what was kept
     *
     * @throws DemoSeedRefused when the step cannot run; it has created nothing then
     */
    public function seed(): string;
}
