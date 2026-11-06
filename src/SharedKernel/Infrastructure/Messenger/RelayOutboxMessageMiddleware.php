<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Messenger;

use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * Moves a message the worker received from the outbox to its async_* transport, chosen by its class, a parent class
 * or an interface in outbox_relay.target_transports (ADR-0009). Nothing after it runs for that message, so no handler
 * runs in the relay. It sends before it returns, and the worker deletes the outbox row only after it returns: a
 * failed send leaves the message to the outbox retries. A crash between the send and the delete sends it again after
 * the outbox's redeliver timeout, so async handlers must be idempotent.
 */
final readonly class RelayOutboxMessageMiddleware implements MiddlewareInterface
{
    public const string OUTBOX_TRANSPORT = 'outbox';

    /**
     * Where the message was and how it failed there: the target transport starts its own count and adds its own ids.
     * Every other stamp travels on, the TenantStamp and the BusNameStamp included.
     */
    private const array STAMPS_LEFT_IN_THE_OUTBOX = [
        NonSendableStampInterface::class,
        TransportMessageIdStamp::class,
        RedeliveryStamp::class,
        DelayStamp::class,
        ErrorDetailsStamp::class,
        SentToFailureTransportStamp::class,
        TransportNamesStamp::class,
    ];

    /**
     * @param ContainerInterface    $transports       every Messenger transport by name
     * @param array<string, string> $targetTransports message class, parent class or interface => transport name
     */
    public function __construct(
        #[Autowire(service: 'messenger.receiver_locator')]
        private ContainerInterface $transports,
        #[Autowire(param: 'outbox_relay.target_transports')]
        private array $targetTransports,
    ) {
    }

    /**
     * @throws UnrecoverableMessageHandlingException when the message has no usable target transport; it goes to failed
     */
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if (self::OUTBOX_TRANSPORT !== $envelope->last(ReceivedStamp::class)?->getTransportName()) {
            return $stack->next()->handle($envelope, $stack);
        }

        $messageClass = $envelope->getMessage()::class;
        $this->targetSender($messageClass)->send($this->withoutOutboxStamps($envelope));

        return $envelope;
    }

    /**
     * @param class-string $messageClass
     */
    private function targetSender(string $messageClass): SenderInterface
    {
        $targetTransport = $this->targetTransportOf($messageClass);
        if (self::OUTBOX_TRANSPORT === $targetTransport) {
            throw new UnrecoverableMessageHandlingException(\sprintf('The outbox relay route of "%s" points back to the outbox.', $messageClass));
        }

        $sender = $this->transports->has($targetTransport) ? $this->transports->get($targetTransport) : null;
        if (!$sender instanceof SenderInterface) {
            throw new UnrecoverableMessageHandlingException(\sprintf('The outbox relay route of "%s" names "%s", which is no transport.', $messageClass, $targetTransport));
        }

        return $sender;
    }

    /**
     * @param class-string $messageClass
     */
    private function targetTransportOf(string $messageClass): string
    {
        foreach ([$messageClass, ...array_values(class_parents($messageClass)), ...array_values(class_implements($messageClass))] as $type) {
            if (isset($this->targetTransports[$type])) {
                return $this->targetTransports[$type];
            }
        }

        throw new UnrecoverableMessageHandlingException(\sprintf('"%s" has no outbox relay route in outbox_relay.target_transports.', $messageClass));
    }

    private function withoutOutboxStamps(Envelope $envelope): Envelope
    {
        foreach (self::STAMPS_LEFT_IN_THE_OUTBOX as $stampType) {
            $envelope = $envelope->withoutStampsOfType($stampType);
        }

        return $envelope;
    }
}
