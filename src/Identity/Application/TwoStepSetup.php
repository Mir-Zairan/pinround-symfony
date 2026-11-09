<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * What the two-step login page shows. The key and the link are set only while it awaits a code.
 */
final readonly class TwoStepSetup
{
    /**
     * @param string|null $manualKey       the seed for typing into the app, in groups of 4 characters
     * @param string|null $provisioningUri the otpauth:// link for the QR code; it holds the seed
     */
    public function __construct(
        public TwoStepLoginState $state,
        #[\SensitiveParameter]
        public ?string $manualKey = null,
        #[\SensitiveParameter]
        public ?string $provisioningUri = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['state' => $this->state, 'manualKey' => '[redacted]', 'provisioningUri' => '[redacted]'];
    }
}
