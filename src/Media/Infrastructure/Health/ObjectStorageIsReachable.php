<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Health;

use App\SharedKernel\Infrastructure\Health\ReadinessCheck;
use App\SharedKernel\Infrastructure\Health\ReadinessCheckFailed;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The object storage endpoint Media talks to (OBJECT_STORAGE_ENDPOINT, the internal one, not the browser-facing
 * OBJECT_STORAGE_PUBLIC_URL) accepts a TCP connection within a second. No credentials and no request: an S3 API
 * has no anonymous health route common to every provider.
 *
 * The second bounds the connect only: resolving a host name is a blocking system lookup that no PHP timeout covers,
 * so a slow resolver can hold the readiness route longer (operations.md, Health).
 */
final readonly class ObjectStorageIsReachable implements ReadinessCheck
{
    private const float CONNECT_TIMEOUT_SECONDS = 1.0;

    private const array DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    public function __construct(#[Autowire(env: 'OBJECT_STORAGE_ENDPOINT')] private string $endpoint)
    {
    }

    public function name(): string
    {
        return 'object_storage';
    }

    public function verify(): void
    {
        $endpointParts = parse_url($this->endpoint);
        $host = false === $endpointParts ? '' : $endpointParts['host'] ?? '';
        $port = false === $endpointParts ? null : $endpointParts['port'] ?? self::DEFAULT_PORTS[strtolower($endpointParts['scheme'] ?? '')] ?? null;
        if ('' === $host || null === $port) {
            throw new ReadinessCheckFailed('OBJECT_STORAGE_ENDPOINT is empty or is not an http(s) URL with a host.');
        }

        // An IPv6 host comes back from parse_url in brackets, as the tcp:// address needs it.
        // The @ keeps the connect warning out of the error handler; the false return is the failure.
        $socket = @stream_socket_client(\sprintf('tcp://%s:%d', $host, $port), $errorCode, $errorMessage, self::CONNECT_TIMEOUT_SECONDS);
        if (false === $socket) {
            throw new ReadinessCheckFailed('The object storage endpoint refused or did not accept a connection in time.');
        }
        fclose($socket);
    }
}
