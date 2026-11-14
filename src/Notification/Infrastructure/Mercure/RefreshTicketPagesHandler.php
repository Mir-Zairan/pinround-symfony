<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Mercure;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Publishes the refresh signal of a ticket in worker-notify, at most once per WINDOW_SECONDS per ticket
 * (realtime-and-notifications.md, Coalescing). A refresh publishes only when it takes the ticket's window lock on the
 * state Valkey; one that finds it taken queues one delayed try, so a burst of moves gives one refresh as it starts and
 * one after it, and the last change of a burst is never left unpublished.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class RefreshTicketPagesHandler
{
    public const float WINDOW_SECONDS = 2.0;

    public const int WINDOW_MILLISECONDS = 2000;

    /** The SSE event name the live-updates controller listens for. */
    public const string SIGNAL_TYPE = 'refresh';

    /**
     * An SSE event with no data is never dispatched by the browser, so the signal carries an empty object, and nothing
     * else: the page fetches what changed with the viewer's own session.
     */
    public const string SIGNAL_DATA = '{}';

    public function __construct(
        private HubInterface $hub,
        private LockFactory $lockFactory,
        #[Autowire(service: 'command.bus')]
        private MessageBusInterface $commandBus,
    ) {
    }

    /**
     * @throws \Symfony\Component\Lock\Exception\LockAcquiringException when the state Valkey cannot be reached
     * @throws \Symfony\Component\Mercure\Exception\RuntimeException    when the hub refuses the update, so it is retried
     */
    public function __invoke(RefreshTicketPages $refresh): void
    {
        $ticketId = new TicketId($refresh->ticketId);
        if ($this->takesWindowOf($ticketId)) {
            $this->hub->publish(new Update(
                RealtimeTopics::ofTicket(new OrganizationId($refresh->organizationId), $ticketId),
                self::SIGNAL_DATA,
                private: true,
                type: self::SIGNAL_TYPE,
            ));

            return;
        }

        $nextAttempt = match ($refresh->attempt) {
            RefreshAttempt::Leading => $this->takesTrailingTryOf($ticketId) ? RefreshAttempt::Trailing : null,
            RefreshAttempt::Trailing => RefreshAttempt::LastTrailing,
            RefreshAttempt::LastTrailing => null,
        };
        if (null !== $nextAttempt) {
            $this->commandBus->dispatch(
                new RefreshTicketPages($refresh->organizationId, $refresh->ticketId, $nextAttempt),
                [new DelayStamp(self::WINDOW_MILLISECONDS)],
            );
        }
    }

    private function takesWindowOf(TicketId $ticketId): bool
    {
        return $this->takesLock('realtime-refresh:'.$ticketId->toString());
    }

    /** Held while a Trailing try waits, so a burst queues one, not one per change. */
    private function takesTrailingTryOf(TicketId $ticketId): bool
    {
        return $this->takesLock('realtime-refresh-trailing:'.$ticketId->toString());
    }

    private function takesLock(string $resource): bool
    {
        // Not released: the lock is the window, and it ends when its time to live runs out.
        return $this->lockFactory->createLock($resource, self::WINDOW_SECONDS, autoRelease: false)->acquire();
    }
}
