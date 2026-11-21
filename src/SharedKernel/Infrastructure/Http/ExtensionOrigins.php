<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;

/**
 * The browser extension origins of EXTENSION_ORIGINS (comma-separated, such as chrome-extension://<id>), the only
 * origins CORS lets read pinround's answers: /api/v1 (RestRequestGuard) and the OAuth endpoints the extension connects
 * through (OAuthEndpointCors). An unset or empty EXTENSION_ORIGINS allows no origin. Holds no state (ADR-0008).
 */
final readonly class ExtensionOrigins
{
    public function __construct(
        #[Autowire(env: 'default::EXTENSION_ORIGINS')]
        private ?string $extensionOrigins,
    ) {
    }

    /**
     * An exact match with one listed origin; never a prefix, a wildcard or a case-folded match.
     */
    public function allows(string $origin): bool
    {
        foreach (explode(',', $this->extensionOrigins ?? '') as $allowed) {
            $allowed = trim($allowed);
            if ('' !== $allowed && $allowed === $origin) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string|null the request's Origin when it sent exactly one and it is listed; null without one, with
     *                     several, or with one that is not listed
     */
    public function allowedOriginOf(Request $request): ?string
    {
        $origins = $request->headers->all('Origin');
        if (1 !== \count($origins) || !$this->allows((string) $origins[0])) {
            return null;
        }

        return (string) $origins[0];
    }
}
