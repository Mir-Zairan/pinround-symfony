<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Mercure;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Publishes the refresh signal of a ticket in worker-notify, at most once per WINDOW_SECONDS per ticket
 * (realtime-and-notifications.md, Coalescing). A refresh publishes only when it takes the ticket's window lock on the
 * state Valkey; one that finds it taken queues one try delayed until that window ends, so a burst of moves gives one
 * refresh as it starts and one as its window ends, and the last change of a burst is never left unpublished.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class RefreshTicketPagesHandler
{
    public const int WINDOW_MILLISECONDS = 2000;

    public const float WINDOW_SECONDS = self::WINDOW_MILLISECONDS / 1000;

    /**
     * Added to the time a window has left, so a try lands after the store has ended it even when the store's clock runs
     * a little behind this process's.
     */
    public const int WINDOW_END_MARGIN_MILLISECONDS = 50;

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
        private LockLifetimes $lockLifetimes,
    ) {
    }

    /**
     * @throws \Symfony\Component\Lock\Exception\LockAcquiringException  when the state Valkey cannot be reached
     * @throws \Symfony\Component\Mercure\Exception\RuntimeException     when the hub refuses the update, so it is retried
     * @throws \Symfony\Component\Messenger\Exception\ExceptionInterface when the delayed try cannot be queued, so it is retried
     */
    public function __invoke(RefreshTicketPages $refresh): void
    {
        $ticketId = new TicketId($refresh->ticketId);
        $window = $this->windowOf($ticketId);
        if ($window->acquire()) {
            try {
                $this->hub->publish(new Update(
                    RealtimeTopics::ofTicket(new OrganizationId($refresh->organizationId), $ticketId),
                    self::SIGNAL_DATA,
                    private: true,
                    type: self::SIGNAL_TYPE,
                ));
            } catch (\Throwable $publishFailure) {
                // Messenger retries this message; a window still held would turn that retry into a silent no-op.
                $window->release();

                throw $publishFailure;
            }

            return;
        }

        $trailingTry = $this->trailingTryOf($ticketId);
        $tookTrailingTry = RefreshAttempt::Leading === $refresh->attempt && $trailingTry->acquire();
        $nextAttempt = match ($refresh->attempt) {
            RefreshAttempt::Leading => $tookTrailingTry ? RefreshAttempt::Trailing : null,
            RefreshAttempt::Trailing => RefreshAttempt::LastTrailing,
            RefreshAttempt::LastTrailing => null,
        };
        if (null === $nextAttempt) {
            return;
        }

        try {
            $this->commandBus->dispatch(
                new RefreshTicketPages($refresh->organizationId, $refresh->ticketId, $nextAttempt),
                [new DelayStamp($this->millisecondsUntilWindowEndsOf($ticketId))],
            );
        } catch (\Throwable $dispatchFailure) {
            // A trailing try still held with none queued would make Messenger's retry queue nothing, losing the burst's last change.
            if ($tookTrailingTry) {
                $trailingTry->release();
            }

            throw $dispatchFailure;
        }
    }

    private function windowOf(TicketId $ticketId): LockInterface
    {
        return $this->windowLock(self::windowResourceOf($ticketId));
    }

    /** A store that cannot say waits a full window, which ends no sooner than the one held. */
    private function millisecondsUntilWindowEndsOf(TicketId $ticketId): int
    {
        $millisecondsLeft = $this->lockLifetimes->millisecondsLeftOf(self::windowResourceOf($ticketId));
        if (null === $millisecondsLeft) {
            return self::WINDOW_MILLISECONDS;
        }

        return min($millisecondsLeft, self::WINDOW_MILLISECONDS) + self::WINDOW_END_MARGIN_MILLISECONDS;
    }

    private static function windowResourceOf(TicketId $ticketId): string
    {
        return 'realtime-refresh:'.$ticketId->toString();
    }

    /** Held while a Trailing try waits, so a burst queues one, not one per change. */
    private function trailingTryOf(TicketId $ticketId): LockInterface
    {
        return $this->windowLock('realtime-refresh-trailing:'.$ticketId->toString());
    }

    private function windowLock(string $resource): LockInterface
    {
        // The lock is the window: released only when the publish or the dispatch fails (so Messenger's retry can take it again), otherwise it ends when its time to live runs out.
        return $this->lockFactory->createLock($resource, self::WINDOW_SECONDS, autoRelease: false);
    }
}
