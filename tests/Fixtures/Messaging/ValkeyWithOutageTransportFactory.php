<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messaging;

use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;

/**
 * Builds the test-only transports with the valkey-outage:// DSN (messenger.yaml, when@test).
 *
 * @implements TransportFactoryInterface<ValkeyWithOutageTransport>
 */
final class ValkeyWithOutageTransportFactory implements TransportFactoryInterface
{
    public function createTransport(#[\SensitiveParameter] string $dsn, #[\SensitiveParameter] array $options, SerializerInterface $serializer): ValkeyWithOutageTransport
    {
        // Through the serializer, as a real queue stores the message.
        return new ValkeyWithOutageTransport(new InMemoryTransport($serializer));
    }

    public function supports(#[\SensitiveParameter] string $dsn, #[\SensitiveParameter] array $options): bool
    {
        return str_starts_with($dsn, 'valkey-outage://');
    }
}
