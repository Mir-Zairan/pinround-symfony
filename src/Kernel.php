<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public const string DEMO_SWITCH_OUTSIDE_DEV = 'PINROUND_DEMO_SKIP_2FA is on, but APP_ENV is not dev. The switch skips two-step login, so it works only on a local demo in dev. Turn it off (PINROUND_DEMO_SKIP_2FA=0) or run with APP_ENV=dev.';

    private const string DEMO_SWITCH = 'PINROUND_DEMO_SKIP_2FA';
    private const string DEMO_ENVIRONMENT = 'dev';

    /**
     * @throws \RuntimeException with DEMO_SWITCH_OUTSIDE_DEV when the demo switch is on outside dev (ADR-0049)
     */
    public function __construct(string $environment, bool $debug)
    {
        if (self::DEMO_ENVIRONMENT !== $environment && self::isDemoSwitchOn()) {
            throw new \RuntimeException(self::DEMO_SWITCH_OUTSIDE_DEV);
        }

        parent::__construct($environment, $debug);
    }

    /**
     * Reads the variable where Symfony's env() does and parses it as its bool processor does, so this check and
     * RecentAuth agree on what "on" is. The container is not built yet, so env() cannot be asked.
     */
    private static function isDemoSwitchOn(): bool
    {
        $value = $_ENV[self::DEMO_SWITCH] ?? $_SERVER[self::DEMO_SWITCH] ?? getenv(self::DEMO_SWITCH);

        return \is_scalar($value)
            && (filter_var($value, \FILTER_VALIDATE_BOOL)
                || (bool) filter_var($value, \FILTER_VALIDATE_INT)
                || (bool) filter_var($value, \FILTER_VALIDATE_FLOAT));
    }

    /**
     * @return list<string> An array of allowed values for APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
