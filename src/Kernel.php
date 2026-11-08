<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /** The environments where the hackathon flag may be on (ADR-0012, Hackathon exception). */
    private const array ENVIRONMENTS_ALLOWING_THE_ADMIN_MFA_SKIP = ['dev', 'test'];

    /**
     * @throws \RuntimeException when APP_HACKATHON_SKIP_ADMIN_MFA is on outside dev and test; the kernel stays shut down
     */
    public function boot(): void
    {
        $wasBooted = $this->booted;
        parent::boot();
        if (!$wasBooted) {
            $this->refuseTheAdminMfaSkipOutsideDevAndTest();
        }
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }

    /**
     * Reads the flag through the container parameter AdminAreaVoter gets, so both see the same value.
     */
    private function refuseTheAdminMfaSkipOutsideDevAndTest(): void
    {
        if (\in_array($this->environment, self::ENVIRONMENTS_ALLOWING_THE_ADMIN_MFA_SKIP, true)
            || true !== $this->getContainer()->getParameter('app.hackathon_skip_admin_mfa')) {
            return;
        }

        $this->shutdown();

        throw new \RuntimeException(\sprintf('APP_HACKATHON_SKIP_ADMIN_MFA is on, but the %s environment refuses it: it lets admins skip two-step login and is allowed only in dev and test (ADR-0012, Hackathon exception). Unset it and start again.', $this->environment));
    }
}
