<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\UI;

use App\Ticketing\UI\TicketQueue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class TicketQueueTest extends TestCase
{
    #[Test]
    public function aQueueOfExactlyTheCapIsTaken(): void
    {
        $queue = new TicketQueue('Code review', self::keys(TicketQueue::MAX_KEYS));

        self::assertSame(100, $queue->count());
        self::assertSame(100, $queue->placeOf('SHOP-100'));
    }

    #[Test]
    public function aQueueOfOneKeyPastTheCapIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A queue holds at most 100 ticket keys.');

        new TicketQueue('Code review', self::keys(TicketQueue::MAX_KEYS + 1));
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function refusedQueues(): iterable
    {
        yield 'a blank label' => [' ', ['SHOP-1'], 'A queue needs a label.'];
        yield 'no key' => ['Code review', [], 'A queue needs at least one ticket key.'];
        yield 'an empty key' => ['Code review', ['SHOP-1', ''], 'A queue\'s ticket keys are never empty.'];
        yield 'a repeated key' => ['Code review', ['SHOP-1', 'SHOP-2', 'SHOP-1'], 'A queue holds each ticket key once.'];
    }

    /**
     * @param list<string> $keys
     */
    #[Test]
    #[DataProvider('refusedQueues')]
    public function aQueueThatCannotBeWalkedIsRefused(string $label, array $keys, string $reason): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($reason);

        new TicketQueue($label, $keys);
    }

    #[Test]
    public function eachKeyKnowsItsPlaceAndItsNeighboursInWalkOrder(): void
    {
        $queue = new TicketQueue('Code review', ['SHOP-7', 'SHOP-2', 'SHOP-9']);

        self::assertSame([1, 2, 3], [$queue->placeOf('SHOP-7'), $queue->placeOf('SHOP-2'), $queue->placeOf('SHOP-9')]);
        self::assertSame([null, 'SHOP-7', 'SHOP-2'], [$queue->previousKeyOf('SHOP-7'), $queue->previousKeyOf('SHOP-2'), $queue->previousKeyOf('SHOP-9')]);
        self::assertSame(['SHOP-2', 'SHOP-9', null], [$queue->nextKeyOf('SHOP-7'), $queue->nextKeyOf('SHOP-2'), $queue->nextKeyOf('SHOP-9')]);
    }

    #[Test]
    public function aKeyNotInTheQueueHasNoPlaceAndNoNeighbours(): void
    {
        $queue = new TicketQueue('Code review', ['SHOP-7', 'SHOP-2']);

        self::assertNull($queue->placeOf('SHOP-3'));
        self::assertNull($queue->previousKeyOf('SHOP-3'));
        self::assertNull($queue->nextKeyOf('SHOP-3'));
        self::assertNull($queue->placeOf('shop-7'), 'Keys are matched as stored, case included.');
    }

    #[Test]
    public function aOneTicketQueueIsBothFirstAndLast(): void
    {
        $queue = new TicketQueue('To do', ['SHOP-4']);

        self::assertSame(1, $queue->placeOf('SHOP-4'));
        self::assertNull($queue->previousKeyOf('SHOP-4'));
        self::assertNull($queue->nextKeyOf('SHOP-4'));
    }

    #[Test]
    public function aStoredQueueReadsBackWithItsLabelAndOrder(): void
    {
        $session = $this->session();

        new TicketQueue('Code review', ['SHOP-7', 'SHOP-2'])->storeIn($session);
        $queue = TicketQueue::of($session);

        self::assertNotNull($queue);
        self::assertSame('Code review', $queue->label);
        self::assertSame(['SHOP-7', 'SHOP-2'], $queue->keys);
    }

    #[Test]
    public function aSecondQueueReplacesTheFirst(): void
    {
        $session = $this->session();
        new TicketQueue('Code review', ['SHOP-7', 'SHOP-2'])->storeIn($session);

        new TicketQueue('To do', ['SHOP-4'])->storeIn($session);
        $queue = TicketQueue::of($session);

        self::assertNotNull($queue);
        self::assertSame('To do', $queue->label);
        self::assertSame(['SHOP-4'], $queue->keys);
        self::assertNull($queue->placeOf('SHOP-7'));
    }

    #[Test]
    public function endingTheQueueLeavesNoneAndKeepsTheRestOfTheSession(): void
    {
        $session = $this->session();
        $session->set('ticketing.list.collapsed_steps', ['a']);
        new TicketQueue('Code review', ['SHOP-7'])->storeIn($session);

        TicketQueue::endIn($session);

        self::assertNull(TicketQueue::of($session));
        self::assertSame(['a'], $session->get('ticketing.list.collapsed_steps'));
    }

    #[Test]
    public function aSessionWithNoQueueReadsAsNone(): void
    {
        self::assertNull(TicketQueue::of($this->session()));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function storedValuesNoQueueWouldStore(): iterable
    {
        yield 'a string' => ['SHOP-1'];
        yield 'no label' => [['keys' => ['SHOP-1']]];
        yield 'a label that is not text' => [['label' => 7, 'keys' => ['SHOP-1']]];
        yield 'keys that are not a list' => [['label' => 'Code review', 'keys' => ['a' => 'SHOP-1']]];
        yield 'a key that is not text' => [['label' => 'Code review', 'keys' => ['SHOP-1', 2]]];
        yield 'no key' => [['label' => 'Code review', 'keys' => []]];
        yield 'keys past the cap' => [['label' => 'Code review', 'keys' => self::keys(TicketQueue::MAX_KEYS + 1)]];
    }

    #[Test]
    #[DataProvider('storedValuesNoQueueWouldStore')]
    public function aStoredValueNoQueueWouldStoreReadsAsNone(mixed $stored): void
    {
        $session = $this->session();
        $session->set('ticketing.queue', $stored);

        self::assertNull(TicketQueue::of($session));
    }

    /**
     * @return list<string> SHOP-1 to SHOP-$count
     */
    private static function keys(int $count): array
    {
        return array_map(static fn (int $number): string => 'SHOP-'.$number, range(1, $count));
    }

    private function session(): Session
    {
        return new Session(new MockArraySessionStorage());
    }
}
