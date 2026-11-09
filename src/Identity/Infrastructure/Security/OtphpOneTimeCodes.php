<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\OneTimeCodes;
use OTPHP\TOTP;
use Psr\Clock\ClockInterface;

/**
 * OneTimeCodes with spomky-labs/otphp, with its defaults: SHA-1, 6 digits, 30-second steps from the Unix epoch, which
 * every common authenticator app expects.
 */
final readonly class OtphpOneTimeCodes implements OneTimeCodes
{
    public const string ISSUER = 'pinround';
    private const int SEED_BYTES = 20;

    /**
     * @param ClockInterface $clock only handed to otphp, which deprecates building a TOTP without one; every code here
     *                              is computed for an explicit time
     */
    public function __construct(private ClockInterface $clock)
    {
    }

    public function newSeed(): string
    {
        return TOTP::generate($this->clock, self::SEED_BYTES)->getSecret();
    }

    public function matchingStep(#[\SensitiveParameter] string $seed, #[\SensitiveParameter] string $code, \DateTimeImmutable $at): ?int
    {
        $totp = $this->totpOf($seed);
        $currentStep = intdiv($at->getTimestamp(), self::STEP_SECONDS);
        foreach ([$currentStep + 1, $currentStep, $currentStep - 1] as $step) {
            $stepStart = $step * self::STEP_SECONDS;
            if ($stepStart >= 0 && hash_equals($totp->at($stepStart), $code)) {
                return $step;
            }
        }

        return null;
    }

    public function provisioningUri(#[\SensitiveParameter] string $seed, string $accountName): string
    {
        if ('' === $accountName) {
            throw new \InvalidArgumentException('An authenticator app needs an account name.');
        }
        $totp = $this->totpOf($seed);
        $totp->setLabel($accountName);
        $totp->setIssuer(self::ISSUER);

        return $totp->getProvisioningUri();
    }

    /**
     * @throws \InvalidArgumentException when the seed is empty
     */
    private function totpOf(#[\SensitiveParameter] string $seed): TOTP
    {
        if ('' === $seed) {
            throw new \InvalidArgumentException('A one-time code needs a seed.');
        }

        return TOTP::createFromSecret($seed, $this->clock);
    }
}
