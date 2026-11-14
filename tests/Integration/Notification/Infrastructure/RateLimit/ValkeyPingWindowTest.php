<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Infrastructure\RateLimit;

use App\Notification\Api\PingsPaused;
use App\Notification\Api\PingTooSoon;
use App\Notification\Application\Ping\PingWindow;
use App\Notification\Domain\PingId;
use App\Notification\Infrastructure\RateLimit\ValkeyPingWindow;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\RedisAdapter;

/**
 * On the state Valkey of the tests (TEST_STATE_VALKEY_DSN), through the connection the app uses.
 */
final class ValkeyPingWindowTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000003ca1';
    private const string LENA = '0197a3c0-0000-7000-8000-000000003c01';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000003c02';
    private const string MIA = '0197a3c0-0000-7000-8000-000000003c03';
    private const string SHOP_12 = '0197a3c0-0000-7000-8000-000000003d12';
    private const string SHOP_13 = '0197a3c0-0000-7000-8000-000000003d13';
    private const string FIRST_PING = '0197a3c0-0000-7000-8000-000000003e01';
    private const string SECOND_PING = '0197a3c0-0000-7000-8000-000000003e02';
    private const string SENT_AT = '2027-03-15 10:00:00 UTC';

    protected function setUp(): void
    {
        $this->forgetSlots();
    }

    protected function tearDown(): void
    {
        $this->forgetSlots();
        parent::tearDown();
    }

    #[Test]
    public function theFirstPingTakesTheSlotForTheOrganizationsLimit(): void
    {
        $this->window()->take(...$this->slot(self::LENA, self::OMAR, self::SHOP_12), pingId: new PingId(self::FIRST_PING), hours: 8, at: new \DateTimeImmutable(self::SENT_AT));

        $key = $this->keyOf(self::LENA, self::OMAR, self::SHOP_12);
        self::assertSame(new \DateTimeImmutable('2027-03-15 18:00:00 UTC')->getTimestamp().':'.self::FIRST_PING, $this->stateValkey()->get($key));
        // Valkey drops the key after 8 hours, so no sleep is needed to see the slot end.
        self::assertGreaterThan(8 * 3600 - 5, $this->stateValkey()->ttl($key));
        self::assertLessThanOrEqual(8 * 3600, $this->stateValkey()->ttl($key));
    }

    #[Test]
    public function aSecondPingForTheSameSlotIsRefusedWithTheTimeTheFirstOnesSlotEnds(): void
    {
        $this->window()->take(...$this->slot(self::LENA, self::OMAR, self::SHOP_12), pingId: new PingId(self::FIRST_PING), hours: 4, at: new \DateTimeImmutable(self::SENT_AT));
        $key = $this->keyOf(self::LENA, self::OMAR, self::SHOP_12);
        $heldBefore = $this->stateValkey()->get($key);

        try {
            $this->window()->take(...$this->slot(self::LENA, self::OMAR, self::SHOP_12), pingId: new PingId(self::SECOND_PING), hours: 24, at: new \DateTimeImmutable('2027-03-15 11:30:00 UTC'));
            self::fail('The second ping took a held slot.');
        } catch (PingTooSoon $refusal) {
            self::assertEquals(new \DateTimeImmutable('2027-03-15 14:00:00 UTC'), $refusal->againAt);
            self::assertSame('You pinged this person about this ticket recently.', $refusal->getMessage());
        }

        self::assertSame($heldBefore, $this->stateValkey()->get($key));
        self::assertLessThanOrEqual(4 * 3600, $this->stateValkey()->ttl($key));
    }

    #[Test]
    public function anotherTicketAnotherRecipientOrAnotherSenderHasASlotOfItsOwn(): void
    {
        $at = new \DateTimeImmutable(self::SENT_AT);
        $this->window()->take(...$this->slot(self::LENA, self::OMAR, self::SHOP_12), pingId: new PingId(self::FIRST_PING), hours: 4, at: $at);

        $this->window()->take(...$this->slot(self::LENA, self::OMAR, self::SHOP_13), pingId: new PingId(self::SECOND_PING), hours: 4, at: $at);
        $this->window()->take(...$this->slot(self::LENA, self::MIA, self::SHOP_12), pingId: new PingId(self::SECOND_PING), hours: 4, at: $at);
        $this->window()->take(...$this->slot(self::MIA, self::OMAR, self::SHOP_12), pingId: new PingId(self::SECOND_PING), hours: 4, at: $at);

        foreach ([[self::LENA, self::OMAR, self::SHOP_13], [self::LENA, self::MIA, self::SHOP_12], [self::MIA, self::OMAR, self::SHOP_12]] as [$from, $to, $ticket]) {
            self::assertSame($at->modify('+4 hours')->getTimestamp().':'.self::SECOND_PING, $this->stateValkey()->get($this->keyOf($from, $to, $ticket)));
        }
    }

    #[Test]
    public function aSlotIsFreeAgainOnceItsKeyExpires(): void
    {
        $at = new \DateTimeImmutable(self::SENT_AT);
        $this->window()->take(...$this->slot(self::LENA, self::OMAR, self::SHOP_12), pingId: new PingId(self::FIRST_PING), hours: 1, at: $at);

        // What Valkey does when the TTL runs out, without waiting an hour.
        $this->stateValkey()->del($this->keyOf(self::LENA, self::OMAR, self::SHOP_12));
        $this->window()->take(...$this->slot(self::LENA, self::OMAR, self::SHOP_12), pingId: new PingId(self::SECOND_PING), hours: 1, at: $at->modify('+1 hour'));

        self::assertSame($at->modify('+2 hours')->getTimestamp().':'.self::SECOND_PING, $this->stateValkey()->get($this->keyOf(self::LENA, self::OMAR, self::SHOP_12)));
    }

    #[Test]
    public function releasingAnotherPingLeavesTheSlotHeld(): void
    {
        $this->window()->take(...$this->slot(self::LENA, self::OMAR, self::SHOP_12), pingId: new PingId(self::FIRST_PING), hours: 4, at: new \DateTimeImmutable(self::SENT_AT));
        $key = $this->keyOf(self::LENA, self::OMAR, self::SHOP_12);
        $heldBefore = $this->stateValkey()->get($key);

        $this->window()->release(...$this->slot(self::LENA, self::OMAR, self::SHOP_12), pingId: new PingId(self::SECOND_PING));

        self::assertSame($heldBefore, $this->stateValkey()->get($key));
    }

    #[Test]
    public function releasingTheHoldingPingFreesTheSlot(): void
    {
        $this->window()->take(...$this->slot(self::LENA, self::OMAR, self::SHOP_12), pingId: new PingId(self::FIRST_PING), hours: 4, at: new \DateTimeImmutable(self::SENT_AT));

        $this->window()->release(...$this->slot(self::LENA, self::OMAR, self::SHOP_12), pingId: new PingId(self::FIRST_PING));

        self::assertSame(0, $this->stateValkey()->exists($this->keyOf(self::LENA, self::OMAR, self::SHOP_12)));
    }

    #[Test]
    public function releasingAFreeSlotChangesNothing(): void
    {
        $this->window()->release(...$this->slot(self::LENA, self::OMAR, self::SHOP_12), pingId: new PingId(self::FIRST_PING));

        self::assertSame(0, $this->stateValkey()->exists($this->keyOf(self::LENA, self::OMAR, self::SHOP_12)));
    }

    #[Test]
    public function aLimitUnderOneHourIsRefusedBeforeValkeyIsAsked(): void
    {
        try {
            $this->window()->take(...$this->slot(self::LENA, self::OMAR, self::SHOP_12), pingId: new PingId(self::FIRST_PING), hours: 0, at: new \DateTimeImmutable(self::SENT_AT));
            self::fail('A limit of 0 hours was taken.');
        } catch (\InvalidArgumentException $refusal) {
            self::assertSame('A ping limit is at least one hour.', $refusal->getMessage());
        }

        self::assertSame(0, $this->stateValkey()->exists($this->keyOf(self::LENA, self::OMAR, self::SHOP_12)));
    }

    #[Test]
    public function anUnreachableValkeyPausesPingsAndTakesNothing(): void
    {
        $unreachable = new ValkeyPingWindow($this->unreachableValkey());

        try {
            $unreachable->take(...$this->slot(self::LENA, self::OMAR, self::SHOP_12), pingId: new PingId(self::FIRST_PING), hours: 4, at: new \DateTimeImmutable(self::SENT_AT));
            self::fail('A ping took a slot no store holds.');
        } catch (PingsPaused $refusal) {
            self::assertSame('Pings are paused for a moment. Try again soon.', $refusal->getMessage());
        }

        self::assertSame(0, $this->stateValkey()->exists($this->keyOf(self::LENA, self::OMAR, self::SHOP_12)));
    }

    /**
     * The lazy connection reports a failed connect as a cache InvalidArgumentException; a connection that is gone
     * throws \RedisException, as a plain \Redis that never connected does.
     */
    #[Test]
    public function aLostConnectionPausesPingsToo(): void
    {
        $this->expectException(PingsPaused::class);

        new ValkeyPingWindow(new \Redis())->take(...$this->slot(self::LENA, self::OMAR, self::SHOP_12), pingId: new PingId(self::FIRST_PING), hours: 4, at: new \DateTimeImmutable(self::SENT_AT));
    }

    #[Test]
    public function anUnreachableValkeyPausesTheRelease(): void
    {
        $this->expectException(PingsPaused::class);

        new ValkeyPingWindow($this->unreachableValkey())->release(...$this->slot(self::LENA, self::OMAR, self::SHOP_12), pingId: new PingId(self::FIRST_PING));
    }

    /**
     * Valkey answers the script with an error here (WRONGTYPE), which phpredis reports as false, not as an exception.
     */
    #[Test]
    public function aScriptErrorPausesPingsInsteadOfLettingThePingThrough(): void
    {
        $key = $this->keyOf(self::LENA, self::OMAR, self::SHOP_12);
        $this->stateValkey()->hSet($key, 'held', 'yes');

        $this->expectException(PingsPaused::class);

        $this->window()->take(...$this->slot(self::LENA, self::OMAR, self::SHOP_12), pingId: new PingId(self::FIRST_PING), hours: 4, at: new \DateTimeImmutable(self::SENT_AT));
    }

    #[Test]
    public function anUnreadableHeldSlotIsRefusedInsteadOfLettingThePingThrough(): void
    {
        $this->stateValkey()->set($this->keyOf(self::LENA, self::OMAR, self::SHOP_12), 'soon', ['EX' => 60]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('The ping window holds an unreadable slot.');

        $this->window()->take(...$this->slot(self::LENA, self::OMAR, self::SHOP_12), pingId: new PingId(self::FIRST_PING), hours: 4, at: new \DateTimeImmutable(self::SENT_AT));
    }

    #[Test]
    public function theHeldSlotsOfTheAskedRecipientsAreReadWithWhenEachEnds(): void
    {
        $at = new \DateTimeImmutable(self::SENT_AT);
        $this->window()->take(...$this->slot(self::LENA, self::OMAR, self::SHOP_12), pingId: new PingId(self::FIRST_PING), hours: 4, at: $at);
        $this->window()->take(...$this->slot(self::LENA, self::MIA, self::SHOP_13), pingId: new PingId(self::SECOND_PING), hours: 8, at: $at);
        $this->window()->take(...$this->slot(self::MIA, self::OMAR, self::SHOP_12), pingId: new PingId(self::SECOND_PING), hours: 24, at: $at);

        $heldUntilById = $this->window()->heldUntilOf(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), [new UserId(self::MIA), new UserId(self::OMAR)], new TicketId(self::SHOP_12));

        self::assertEquals([self::OMAR => new \DateTimeImmutable('2027-03-15 14:00:00 UTC')], $heldUntilById);
    }

    #[Test]
    public function aReleasedSlotIsNoLongerRead(): void
    {
        $this->window()->take(...$this->slot(self::LENA, self::OMAR, self::SHOP_12), pingId: new PingId(self::FIRST_PING), hours: 4, at: new \DateTimeImmutable(self::SENT_AT));
        $this->window()->release(...$this->slot(self::LENA, self::OMAR, self::SHOP_12), pingId: new PingId(self::FIRST_PING));

        self::assertSame([], $this->window()->heldUntilOf(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), [new UserId(self::OMAR)], new TicketId(self::SHOP_12)));
    }

    #[Test]
    public function askingForNoRecipientsDoesNotReachValkey(): void
    {
        self::assertSame([], new ValkeyPingWindow($this->unreachableValkey())->heldUntilOf(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), [], new TicketId(self::SHOP_12)));
    }

    #[Test]
    public function anUnreachableValkeyPausesTheRead(): void
    {
        $this->expectException(PingsPaused::class);

        new ValkeyPingWindow($this->unreachableValkey())->heldUntilOf(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), [new UserId(self::OMAR)], new TicketId(self::SHOP_12));
    }

    #[Test]
    public function aLostConnectionPausesTheReadToo(): void
    {
        $this->expectException(PingsPaused::class);

        new ValkeyPingWindow(new \Redis())->heldUntilOf(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), [new UserId(self::OMAR)], new TicketId(self::SHOP_12));
    }

    #[Test]
    public function anUnreadableHeldSlotFailsTheRead(): void
    {
        $this->stateValkey()->set($this->keyOf(self::LENA, self::OMAR, self::SHOP_12), 'soon', ['EX' => 60]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageIsOrContains('The ping window holds an unreadable slot.');

        $this->window()->heldUntilOf(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), [new UserId(self::OMAR)], new TicketId(self::SHOP_12));
    }

    /**
     * @return array{organizationId: OrganizationId, fromUserId: UserId, toUserId: UserId, ticketId: TicketId}
     */
    private function slot(string $from, string $to, string $ticket): array
    {
        return [
            'organizationId' => new OrganizationId(self::ACME_FOODS),
            'fromUserId' => new UserId($from),
            'toUserId' => new UserId($to),
            'ticketId' => new TicketId($ticket),
        ];
    }

    private function keyOf(string $from, string $to, string $ticket): string
    {
        return 'ping:'.self::ACME_FOODS.':'.$from.':'.$to.':'.$ticket;
    }

    private function forgetSlots(): void
    {
        foreach ([self::LENA, self::MIA] as $from) {
            foreach ([self::OMAR, self::MIA] as $to) {
                foreach ([self::SHOP_12, self::SHOP_13] as $ticket) {
                    $this->stateValkey()->del($this->keyOf($from, $to, $ticket));
                }
            }
        }
    }

    private function window(): PingWindow
    {
        return new ValkeyPingWindow($this->stateValkey());
    }

    private function unreachableValkey(): \Redis
    {
        $unreachable = RedisAdapter::createConnection('redis://127.0.0.1:1', ['lazy' => true, 'timeout' => 1]);
        self::assertInstanceOf(\Redis::class, $unreachable);

        return $unreachable;
    }

    private function stateValkey(): \Redis
    {
        return self::getContainer()->get('state_valkey.connection');
    }
}
