<?php

declare(strict_types=1);

namespace App\SharedKernel\UI;

use Symfony\Contracts\Service\ResetInterface;

/**
 * Origins the page being rendered adds to its Content-Security-Policy connect-src, for this request only: Ollama on
 * the laptop, for a person whose organization runs AI there (ADR-0019). The security-headers subscriber reads them on
 * the response, so a page that never asks keeps the plain policy. The kernel resets them between requests, so worker
 * mode never carries one into the next request (ADR-0008).
 */
final class CspConnectSources implements ResetInterface
{
    // A plain origin only: no wildcard, path, second source or "; directive" can reach the header.
    public const string ORIGIN_PATTERN = '#^https?://[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?(?::[0-9]{1,5})?\z#';

    /** @var list<string> */
    private array $origins = [];

    /**
     * @throws \InvalidArgumentException when $origin is not an http or https origin with a plain lowercase host
     */
    public function allow(string $origin): void
    {
        if (1 !== preg_match(self::ORIGIN_PATTERN, $origin)) {
            throw new \InvalidArgumentException('A connect-src origin must be http or https with a plain lowercase host and no path.');
        }
        if (!\in_array($origin, $this->origins, true)) {
            $this->origins[] = $origin;
        }
    }

    /**
     * @return list<string> in the order they were first allowed, each once
     */
    public function origins(): array
    {
        return $this->origins;
    }

    public function reset(): void
    {
        $this->origins = [];
    }
}
