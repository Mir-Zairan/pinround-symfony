<?php

declare(strict_types=1);

namespace App\Web\Infrastructure\Security;

use Symfony\Contracts\Service\ResetInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * The Content-Security-Policy nonce of the current request: templates print it with `csp_nonce()`, and the
 * security-headers subscriber puts the same value in the header. The kernel resets it between requests, so
 * worker mode never reuses a nonce (ADR-0008).
 */
final class CspNonce implements ResetInterface
{
    private const int RANDOM_BYTES = 16;

    private ?string $nonce = null;

    /**
     * @return string base64 of 128 random bits, valid as a CSP nonce-source and as an HTML attribute value
     */
    #[AsTwigFunction('csp_nonce')]
    public function current(): string
    {
        return $this->nonce ??= base64_encode(random_bytes(self::RANDOM_BYTES));
    }

    public function reset(): void
    {
        $this->nonce = null;
    }
}
