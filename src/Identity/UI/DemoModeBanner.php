<?php

declare(strict_types=1);

namespace App\Identity\UI;

use App\Identity\Application\RecentAuth;
use Twig\Attribute\AsTwigFunction;

/**
 * Whether the page shell shows "Demo mode: two-step login is skipped." (ADR-0049). It asks RecentAuth, the one reader
 * of PINROUND_DEMO_SKIP_2FA, so the banner shows exactly while two-step login is really skipped. Holds no state, so it
 * is safe in worker mode.
 */
final readonly class DemoModeBanner
{
    public function __construct(private RecentAuth $recentAuth)
    {
    }

    #[AsTwigFunction('is_two_step_login_skipped_for_demo')]
    public function isTwoStepLoginSkippedForDemo(): bool
    {
        return $this->recentAuth->isTwoStepLoginSkippedForDemo();
    }
}
