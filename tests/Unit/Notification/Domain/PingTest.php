<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Domain;

use App\Notification\Domain\Ping;
use App\Notification\Domain\PingId;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PingTest extends TestCase
{
    private const string ID = '0197a3c0-0000-7000-8000-000000004121';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-000000004122';
    private const string TICKET = '0197a3c0-0000-7000-8000-000000004123';
    private const string LENA = '0197a3c0-0000-7000-8000-000000004124';
    private const string OMAR = '0197a3c0-0000-7000-8000-000000004125';

    #[Test]
    public function aPingKeepsItsPeopleTicketAndWhenItShows(): void
    {
        $createdAt = new \DateTimeImmutable('2027-03-15 22:00:00+00:00');
        $deliverAfter = new \DateTimeImmutable('2027-03-16 08:00:00+00:00');

        $ping = $this->send('  Can you look today?  ', $deliverAfter, $createdAt);

        self::assertSame(self::ID, $ping->id()->toString());
        self::assertSame(self::ACME_FOODS, $ping->organizationId()->toString());
        self::assertSame(self::TICKET, $ping->ticketId()->toString());
        self::assertSame(self::LENA, $ping->fromId()->toString());
        self::assertSame(self::OMAR, $ping->toId()->toString());
        self::assertSame('Can you look today?', $ping->body());
        self::assertSame($deliverAfter, $ping->deliverAfter());
        self::assertSame($createdAt, $ping->createdAt());
        self::assertNull($ping->clearedAt());
        self::assertNull($ping->clearedReason());
    }

    #[Test]
    public function aBodyOfFiveHundredCharactersCountsCharactersNotBytes(): void
    {
        $body = str_repeat('ü', Ping::MAX_BODY_LENGTH);

        self::assertSame($body, $this->send($body, null, new \DateTimeImmutable('2027-03-15 10:00:00+00:00'))->body());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bodiesOutOfBounds(): iterable
    {
        yield 'empty' => [''];
        yield 'only spaces' => ["  \n "];
        yield 'one character too long' => [str_repeat('a', Ping::MAX_BODY_LENGTH + 1)];
    }

    #[Test]
    #[DataProvider('bodiesOutOfBounds')]
    public function aBodyOutOfBoundsIsRefused(string $body): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('A ping needs a message of 1 to 500 characters.');

        $this->send($body, null, new \DateTimeImmutable('2027-03-15 10:00:00+00:00'));
    }

    private function send(string $body, ?\DateTimeImmutable $deliverAfter, \DateTimeImmutable $createdAt): Ping
    {
        return Ping::send(new PingId(self::ID), new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), new UserId(self::LENA), new UserId(self::OMAR), $body, $deliverAfter, $createdAt);
    }
}
