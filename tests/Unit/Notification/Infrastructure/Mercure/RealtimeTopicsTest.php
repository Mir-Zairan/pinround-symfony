<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Mercure;

use App\Notification\Infrastructure\Mercure\RealtimeTopics;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RealtimeTopicsTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000000c1';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000000c2';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000000d1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000000a2';

    #[Test]
    public function aTicketTopicIsTheOrganizationAndTheTicketIdOnly(): void
    {
        self::assertSame(
            'https://pinround.app/o/'.self::ACME_FOODS.'/t/'.self::TICKET,
            RealtimeTopics::ofTicket(new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET)),
        );
    }

    #[Test]
    public function aUserTopicIsTheOrganizationAndTheUserIdOnly(): void
    {
        self::assertSame(
            'https://pinround.app/o/'.self::ACME_FOODS.'/u/'.self::OMAR,
            RealtimeTopics::ofUser(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR)),
        );
    }

    #[Test]
    public function theSameIdsInAnotherOrganizationAreAnotherTopic(): void
    {
        self::assertNotSame(
            RealtimeTopics::ofTicket(new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET)),
            RealtimeTopics::ofTicket(new OrganizationId(self::BETA_BAKERY), new TicketId(self::TICKET)),
        );
        self::assertNotSame(
            RealtimeTopics::ofUser(new OrganizationId(self::ACME_FOODS), new UserId(self::OMAR)),
            RealtimeTopics::ofUser(new OrganizationId(self::BETA_BAKERY), new UserId(self::OMAR)),
        );
    }
}
