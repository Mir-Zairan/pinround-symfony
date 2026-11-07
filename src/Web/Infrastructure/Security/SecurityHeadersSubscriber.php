<?php

declare(strict_types=1);

namespace App\Web\Infrastructure\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Puts the headers of the CSP example in docs/02-architecture/security.md on every main-request response. It
 * replaces any value a controller set, so no response can loosen the policy by accident.
 *
 * The origins of OBJECT_STORAGE_PUBLIC_URL (the bucket's own URL) and MERCURE_PUBLIC_URL (the hub browsers
 * subscribe to) join img-src and connect-src. An empty value adds no source; a value that is not http(s) with a
 * plain host throws \InvalidArgumentException.
 */
final readonly class SecurityHeadersSubscriber
{
    // A plain host only: no wildcard, no second source and no "; directive" can reach the header.
    private const string PUBLIC_URL_PATTERN = '#^(?<origin>https?://[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?(?::[0-9]{1,5})?)(?:[/?\#][^\s;,]*)?\z#';

    private ?string $objectStorageOrigin;
    private ?string $mercureOrigin;

    public function __construct(
        private CspNonce $cspNonce,
        #[Autowire(env: 'OBJECT_STORAGE_PUBLIC_URL')]
        string $objectStoragePublicUrl,
        #[Autowire(env: 'MERCURE_PUBLIC_URL')]
        string $mercurePublicUrl,
        #[Autowire(param: 'kernel.environment')]
        private string $kernelEnvironment,
    ) {
        $this->objectStorageOrigin = self::originOf($objectStoragePublicUrl, 'OBJECT_STORAGE_PUBLIC_URL');
        $this->mercureOrigin = self::originOf($mercurePublicUrl, 'MERCURE_PUBLIC_URL');
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function addSecurityHeaders(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('Content-Security-Policy', $this->contentSecurityPolicy());
        if ('prod' === $this->kernelEnvironment) {
            $headers->set('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
        }
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
    }

    private function contentSecurityPolicy(): string
    {
        $nonceSource = "'nonce-".$this->cspNonce->current()."'";

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' ".$nonceSource,
            "style-src 'self' ".$nonceSource,
            self::directive('img-src', "'self'", $this->objectStorageOrigin, 'data:'),
            self::directive('connect-src', "'self'", $this->mercureOrigin),
            "font-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'none'",
            "form-action 'self'",
            "object-src 'none'",
        ]);
    }

    private static function directive(string $name, ?string ...$sources): string
    {
        return implode(' ', [$name, ...array_filter($sources, static fn (?string $source): bool => null !== $source)]);
    }

    private static function originOf(string $publicUrl, string $settingName): ?string
    {
        if ('' === $publicUrl) {
            return null;
        }

        if (1 !== preg_match(self::PUBLIC_URL_PATTERN, $publicUrl, $match)) {
            throw new \InvalidArgumentException($settingName.' must be an http or https URL with a plain host (no wildcard), or empty.');
        }

        return $match['origin'];
    }
}
