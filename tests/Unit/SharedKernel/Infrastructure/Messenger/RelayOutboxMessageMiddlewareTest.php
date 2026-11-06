<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Messenger;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Messenger\RelayOutboxMessageMiddleware;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use App\Tests\Fixtures\Messaging\OutboxProbeRecorded;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ErrorDetailsStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;

/**
 * Each branch of the relay on its own, with in-memory transports: what it sends where, which stamps travel on, and
 * that a message it cannot place fails instead of being dropped or handled in the relay.
 */
final class RelayOutboxMessageMiddlewareTest extends TestCase
{
    private const string ORG_A = '0197a3c0-0000-7000-8000-00000000000a';

    private InMemoryTransport $asyncNotify;
    private InMemoryTransport $asyncMedia;
    private InMemoryTransport $outbox;
    private bool $wasNextMiddlewareCalled = false;

    protected function setUp(): void
    {
        $this->asyncNotify = new InMemoryTransport();
        $this->asyncMedia = new InMemoryTransport();
        $this->outbox = new InMemoryTransport();
    }

    #[Test]
    public function aMessageNotReceivedFromTheOutboxGoesOnUnchanged(): void
    {
        $relay = $this->relay([OutboxProbeRecorded::class => 'async_notify']);

        foreach ([[], [new ReceivedStamp('async_notify')]] as $stamps) {
            $this->wasNextMiddlewareCalled = false;
            $this->relayThrough($relay, new Envelope(new OutboxProbeRecorded('probe'), $stamps));

            self::assertTrue($this->wasNextMiddlewareCalled);
        }
        self::assertSame([], $this->asyncNotify->getSent());
    }

    #[Test]
    public function aMessageFromTheOutboxIsSentOnceToItsTransportWithItsOwnStampsOnly(): void
    {
        $relay = $this->relay([OutboxProbeRecorded::class => 'async_notify']);

        $this->relayThrough($relay, new Envelope(new OutboxProbeRecorded('probe'), [
            new TenantStamp(new OrganizationId(self::ORG_A)),
            new BusNameStamp('event.bus'),
            new TransportNamesStamp(['outbox']),
            new DelayStamp(5000),
            new RedeliveryStamp(2),
            new ErrorDetailsStamp(\RuntimeException::class, 0, 'Valkey was down.'),
            new SentToFailureTransportStamp('outbox'),
            new TransportMessageIdStamp('41'),
            new ReceivedStamp('failed'),
            new ReceivedStamp('outbox'),
        ]));

        self::assertFalse($this->wasNextMiddlewareCalled);
        $sent = $this->asyncNotify->getSent();
        self::assertCount(1, $sent);
        self::assertEquals(new OutboxProbeRecorded('probe'), $sent[0]->getMessage());
        // TransportMessageIdStamp: the one async_notify gave it on send, not the outbox's "41".
        self::assertEqualsCanonicalizing(
            [TenantStamp::class, BusNameStamp::class, TransportMessageIdStamp::class],
            array_keys($sent[0]->all()),
        );
        self::assertSame(1, $sent[0]->last(TransportMessageIdStamp::class)?->getId());
        self::assertSame(self::ORG_A, $sent[0]->last(TenantStamp::class)?->organizationId()->toString());
        self::assertSame([], $this->asyncMedia->getSent());
    }

    #[Test]
    public function theRouteOfTheClassWinsOverAParentClassWhichWinsOverAnInterface(): void
    {
        $this->relayFromOutbox($this->relay([\Countable::class => 'async_media', \ArrayIterator::class => 'async_notify']), new \RecursiveArrayIterator());
        $this->relayFromOutbox($this->relay([\Countable::class => 'async_notify', \RecursiveArrayIterator::class => 'async_media']), new \RecursiveArrayIterator());
        $this->relayFromOutbox($this->relay([\Countable::class => 'async_notify']), new \ArrayObject());

        self::assertCount(2, $this->asyncNotify->getSent());
        self::assertCount(1, $this->asyncMedia->getSent());
        self::assertInstanceOf(\ArrayObject::class, $this->asyncNotify->getSent()[1]->getMessage());
    }

    #[Test]
    public function aMessageWithoutARouteFailsForGood(): void
    {
        $this->expectRefusal('has no outbox relay route', [\stdClass::class => 'async_notify']);
    }

    #[Test]
    public function aRouteBackToTheOutboxFailsForGoodInsteadOfLooping(): void
    {
        $this->expectRefusal('points back to the outbox', [OutboxProbeRecorded::class => 'outbox']);
    }

    #[Test]
    public function aRouteToATransportThatDoesNotExistFailsForGood(): void
    {
        $this->expectRefusal('which is no transport', [OutboxProbeRecorded::class => 'async_missing']);
    }

    #[Test]
    public function aFailedSendIsThrownSoTheWorkerKeepsTheOutboxMessage(): void
    {
        $unreachable = new class implements SenderInterface {
            public function send(Envelope $envelope): Envelope
            {
                throw new TransportException('Valkey is unreachable.');
            }
        };
        $relay = new RelayOutboxMessageMiddleware(new ServiceLocator(['async_notify' => static fn (): SenderInterface => $unreachable]), [OutboxProbeRecorded::class => 'async_notify']);

        try {
            $this->relayFromOutbox($relay, new OutboxProbeRecorded('probe'));
            self::fail('The failed send was not thrown.');
        } catch (TransportException $failure) {
            self::assertSame('Valkey is unreachable.', $failure->getMessage());
        }
        self::assertFalse($this->wasNextMiddlewareCalled);
    }

    /**
     * @param array<string, string> $targetTransports
     */
    private function expectRefusal(string $reason, array $targetTransports): void
    {
        try {
            $this->relayFromOutbox($this->relay($targetTransports), new OutboxProbeRecorded('probe'));
            self::fail('The relay accepted the message.');
        } catch (UnrecoverableMessageHandlingException $refusal) {
            self::assertStringContainsString($reason, $refusal->getMessage());
        }

        self::assertFalse($this->wasNextMiddlewareCalled);
        self::assertSame([], $this->asyncNotify->getSent());
        self::assertSame([], $this->outbox->getSent());
    }

    /**
     * @param array<string, string> $targetTransports
     */
    private function relay(array $targetTransports): RelayOutboxMessageMiddleware
    {
        return new RelayOutboxMessageMiddleware(new ServiceLocator([
            'async_notify' => fn (): InMemoryTransport => $this->asyncNotify,
            'async_media' => fn (): InMemoryTransport => $this->asyncMedia,
            'outbox' => fn (): InMemoryTransport => $this->outbox,
        ]), $targetTransports);
    }

    private function relayFromOutbox(RelayOutboxMessageMiddleware $relay, object $message): void
    {
        $this->relayThrough($relay, new Envelope($message, [new ReceivedStamp('outbox')]));
    }

    private function relayThrough(RelayOutboxMessageMiddleware $relay, Envelope $envelope): void
    {
        $next = new readonly class(fn () => $this->wasNextMiddlewareCalled = true) implements MiddlewareInterface {
            public function __construct(private \Closure $onCall)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                ($this->onCall)();

                return $envelope;
            }
        };

        $relay->handle($envelope, new StackMiddleware($next));
    }
}
