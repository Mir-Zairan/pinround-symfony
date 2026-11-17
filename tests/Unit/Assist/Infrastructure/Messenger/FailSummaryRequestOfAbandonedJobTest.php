<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Infrastructure\Messenger;

use App\Assist\Application\Summaries\EndSummaryRequest;
use App\Assist\Application\Summaries\GenerateSummary;
use App\Assist\Infrastructure\Messenger\FailSummaryRequestOfAbandonedJob;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * The listener on its own, with request states in memory (RememberedSummaryRequests) that start with Omar's checkout
 * request queued at Acme Foods.
 */
final class FailSummaryRequestOfAbandonedJobTest extends TestCase
{
    private const string ACME_FOODS = '019a5f00-0000-7000-8000-00000000c501';
    private const string BETA_BAKERY = '019a5f00-0000-7000-8000-00000000c502';
    private const string CHECKOUT_TICKET = '019a5f00-0000-7000-8000-00000000c531';
    private const string OMAR = '019a5f00-0000-7000-8000-00000000c521';

    private RememberedSummaryRequests $requests;
    private TestHandler $logRecords;
    private FailSummaryRequestOfAbandonedJob $listener;

    protected function setUp(): void
    {
        $this->requests = new RememberedSummaryRequests();
        $this->requests->states = [self::ACME_FOODS.':'.self::CHECKOUT_TICKET => ['queued', self::OMAR, null]];
        $this->logRecords = new TestHandler();
        $this->listener = new FailSummaryRequestOfAbandonedJob($this->requests, new Logger('test', [$this->logRecords]));
    }

    #[Test]
    public function aJobMessengerGivesUpOnFailsItsRequestWithTheFailedLine(): void
    {
        $this->listener->__invoke(self::failed(self::job(), self::ACME_FOODS));

        self::assertSame(
            [self::ACME_FOODS.':'.self::CHECKOUT_TICKET => ['failed', self::OMAR, 'The AI answer failed. Try again.']],
            $this->requests->states,
        );
        self::assertSame([], $this->logRecords->getRecords());
    }

    #[Test]
    public function aJobThatWillBeRetriedLeavesItsRequestQueued(): void
    {
        $event = self::failed(self::job(), self::ACME_FOODS);
        $event->setForRetry();

        $this->listener->__invoke($event);

        self::assertSame([self::ACME_FOODS.':'.self::CHECKOUT_TICKET => ['queued', self::OMAR, null]], $this->requests->states);
    }

    #[Test]
    public function anotherMessageMessengerGivesUpOnEndsNoRequest(): void
    {
        $other = EndSummaryRequest::stored(new OrganizationId(self::ACME_FOODS), new TicketId(self::CHECKOUT_TICKET), new UserId(self::OMAR));

        $this->listener->__invoke(self::failed($other, self::ACME_FOODS));

        self::assertSame([self::ACME_FOODS.':'.self::CHECKOUT_TICKET => ['queued', self::OMAR, null]], $this->requests->states);
    }

    #[Test]
    public function aJobStampedForAnotherOrganizationOrNoneEndsNoRequest(): void
    {
        $this->listener->__invoke(self::failed(self::job(), self::BETA_BAKERY));
        $this->listener->__invoke(self::failed(self::job(), null));

        self::assertSame([self::ACME_FOODS.':'.self::CHECKOUT_TICKET => ['queued', self::OMAR, null]], $this->requests->states);
    }

    /** A throw from a worker event listener would stop worker-heavy. */
    #[Test]
    public function aStateValkeyCannotReachIsLoggedByItsClassAndNotThrown(): void
    {
        $this->requests->failure = new \RedisException('read error on connection');

        $this->listener->__invoke(self::failed(self::job(), self::ACME_FOODS));

        self::assertSame([self::ACME_FOODS.':'.self::CHECKOUT_TICKET => ['queued', self::OMAR, null]], $this->requests->states);
        self::assertSame(
            [['A summary request could not be ended; it stays queued until it expires.', ['exception_class' => \RedisException::class]]],
            array_map(static fn (LogRecord $record): array => [$record->message, $record->context], $this->logRecords->getRecords()),
        );
    }

    #[Test]
    public function aJobNamingNoTicketIdIsLoggedAndNotThrown(): void
    {
        $this->listener->__invoke(self::failed(new GenerateSummary(self::ACME_FOODS, 'not-a-ticket', self::OMAR), self::ACME_FOODS));

        self::assertSame([self::ACME_FOODS.':'.self::CHECKOUT_TICKET => ['queued', self::OMAR, null]], $this->requests->states);
        self::assertSame(
            [['A summary request could not be ended; it stays queued until it expires.', ['exception_class' => \InvalidArgumentException::class]]],
            array_map(static fn (LogRecord $record): array => [$record->message, $record->context], $this->logRecords->getRecords()),
        );
    }

    private static function job(): GenerateSummary
    {
        return new GenerateSummary(self::ACME_FOODS, self::CHECKOUT_TICKET, self::OMAR);
    }

    private static function failed(object $message, ?string $stampedOrganizationId): WorkerMessageFailedEvent
    {
        $stamps = null === $stampedOrganizationId ? [] : [new TenantStamp(new OrganizationId($stampedOrganizationId))];

        return new WorkerMessageFailedEvent(new Envelope($message, $stamps), 'async_ai', new \RuntimeException('The job failed.'));
    }
}
