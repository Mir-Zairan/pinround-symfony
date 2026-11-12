<?php

declare(strict_types=1);

namespace App\Tests\Integration\Messaging;

use App\Identity\Api\Event\UserRenamed;
use App\Identity\Infrastructure\Messenger\MessengerIdentityEvents;
use App\SharedKernel\Application\TenantContext;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * UserRenamed on the real event.bus, outbox and relay (messenger.yaml): it is written to the outbox in the transaction
 * of the new name, so Organization clears the person's cached teams only after that commit, in worker-notify.
 */
final class IdentityEventRoutingTest extends KernelTestCase
{
    use RunsTheWorkerUntilIdle;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000002701';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000002721';
    private const string MIA = '0197a3c0-0000-7000-8000-000000002722';

    protected function setUp(): void
    {
        $this->connection()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->connection()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
        parent::tearDown();
    }

    #[Test]
    public function aRolledBackRenameLeavesNoEventInTheOutbox(): void
    {
        $outboxRowsInsideTheTransaction = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): int {
            $this->identityEvents()->publish(new UserRenamed(self::OMAR));

            return $this->outboxRowCount();
        });

        self::assertSame(1, $outboxRowsInsideTheTransaction);
        self::assertSame(0, $this->outboxRowCount());
    }

    #[Test]
    public function theRelayedRenameClearsThePersonsCachedTeamsInWorkerNotifyAndNoOtherTeam(): void
    {
        $this->fillTeamEntry('omar-team', self::OMAR);
        $this->fillTeamEntry('mia-team', self::MIA);

        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->identityEvents()->publish(new UserRenamed(self::OMAR)));
        self::assertSame([true, true], $this->hasTeamEntries(), 'Nothing is cleared before the relay runs.');

        self::runTheWorkerUntilIdle('outbox');
        $relayed = $this->asyncNotify()->getSent();
        self::assertSame([UserRenamed::class], array_map(static fn (Envelope $envelope): string => $envelope->getMessage()::class, $relayed));
        self::assertSame([true, true], $this->hasTeamEntries(), 'Nothing is cleared before worker-notify handles it.');

        self::runTheWorkerUntilIdle('async_notify');

        self::assertSame([false, true], $this->hasTeamEntries());
    }

    /**
     * The relay delivers at least once, so worker-notify may get the same rename twice: the second is handled without
     * failing and leaves the person's team cleared and the other team cached.
     */
    #[Test]
    public function aRelayedRenameDeliveredTwiceIsHandledTwiceWithoutHarm(): void
    {
        $this->fillTeamEntry('omar-team', self::OMAR);
        $this->fillTeamEntry('mia-team', self::MIA);
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->identityEvents()->publish(new UserRenamed(self::OMAR)));
        self::runTheWorkerUntilIdle('outbox');
        $relayed = $this->asyncNotify()->getSent();
        self::assertCount(1, $relayed);

        $this->asyncNotify()->send($relayed[0]);
        self::runTheWorkerUntilIdle('async_notify');

        $handled = array_map(static fn (Envelope $envelope): string => $envelope->getMessage()::class, $this->asyncNotify()->getAcknowledged());
        self::assertSame([UserRenamed::class, UserRenamed::class], $handled);
        self::assertSame([], $this->asyncNotify()->getRejected());
        self::assertSame([false, true], $this->hasTeamEntries());
    }

    #[Test]
    public function publishingOutsideATransactionIsRefusedAndWritesNoOutboxRow(): void
    {
        try {
            $this->identityEvents()->publish(new UserRenamed(self::OMAR));
            self::fail('An event was published with no transaction open.');
        } catch (\LogicException $refused) {
            self::assertSame('An identity event is published in the transaction of its change; none is open.', $refused->getMessage());
        }

        self::assertSame(0, $this->outboxRowCount());
    }

    private function fillTeamEntry(string $key, string $userId): void
    {
        // The tag TeamPeopleCache gives each person in a team's entry.
        $this->teamPeoplePool()->get($key, static function (ItemInterface $item) use ($userId): string {
            $item->tag('people-user-'.$userId);

            return 'cached';
        });
    }

    /**
     * @return array{bool, bool} whether Omar's team and Mia's team are still cached
     */
    private function hasTeamEntries(): array
    {
        return [$this->teamPeoplePool()->hasItem('omar-team'), $this->teamPeoplePool()->hasItem('mia-team')];
    }

    private function teamPeoplePool(): TagAwareAdapter
    {
        return self::getContainer()->get('cache.team_people');
    }

    private function identityEvents(): MessengerIdentityEvents
    {
        return new MessengerIdentityEvents(self::getContainer()->get('event.bus'), $this->connection());
    }

    private function outboxRowCount(): int
    {
        $count = $this->connection()->fetchOne("SELECT count(*) FROM messenger_outbox WHERE queue_name = 'outbox'");
        self::assertIsInt($count);

        return $count;
    }

    private function asyncNotify(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async_notify');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function connection(): Connection
    {
        return $this->entityManager()->getConnection();
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }
}
