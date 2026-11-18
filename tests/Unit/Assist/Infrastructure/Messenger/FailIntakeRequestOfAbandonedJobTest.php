<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Infrastructure\Messenger;

use App\Assist\Api\DraftReport;
use App\Assist\Api\IntakeCheckId;
use App\Assist\Application\Intake\EndIntakeRequest;
use App\Assist\Application\Intake\GenerateIntakeSuggestion;
use App\Assist\Application\Summaries\GenerateSummary;
use App\Assist\Infrastructure\Messenger\FailIntakeRequestOfAbandonedJob;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * The listener on its own, with check states in memory (RememberedIntakeRequests) that start with Omar's check open
 * and queued at Acme Foods with its draft.
 */
final class FailIntakeRequestOfAbandonedJobTest extends TestCase
{
    private const string ACME_FOODS = '019a5f00-0000-7000-8000-00000000c601';
    private const string BETA_BAKERY = '019a5f00-0000-7000-8000-00000000c602';
    private const string CHECK = '019a5f00-0000-7000-8000-00000000c641';
    private const string PROJECT = '019a5f00-0000-7000-8000-00000000c651';
    private const string OMAR = '019a5f00-0000-7000-8000-00000000c621';
    private const array QUEUED_WITH_DRAFT = [
        [self::ACME_FOODS.':'.self::CHECK => ['queued', self::OMAR, null]],
        [self::ACME_FOODS.':'.self::CHECK => 'Pay does nothing'],
        [self::ACME_FOODS.':'.self::OMAR => [self::CHECK]],
    ];

    private RememberedIntakeRequests $requests;
    private TestHandler $logRecords;
    private FailIntakeRequestOfAbandonedJob $listener;

    protected function setUp(): void
    {
        $this->requests = new RememberedIntakeRequests();
        $this->requests->open(new OrganizationId(self::ACME_FOODS), new IntakeCheckId(self::CHECK), new UserId(self::OMAR), new DraftReport(self::PROJECT, 'Pay does nothing', ''));
        self::assertSame(self::QUEUED_WITH_DRAFT, $this->rememberedRequests());
        $this->logRecords = new TestHandler();
        $this->listener = new FailIntakeRequestOfAbandonedJob($this->requests, new Logger('test', [$this->logRecords]));
    }

    #[Test]
    public function aJobMessengerGivesUpOnFailsItsCheckWithTheFailedLineDropsTheDraftAndClosesIt(): void
    {
        $this->listener->__invoke(self::failed(self::job(), self::ACME_FOODS));

        self::assertSame(
            [[self::ACME_FOODS.':'.self::CHECK => ['failed', self::OMAR, 'The AI answer failed. Try again.']], [], []],
            $this->rememberedRequests(),
        );
        self::assertSame([], $this->logRecords->getRecords());
    }

    #[Test]
    public function aJobThatWillBeRetriedLeavesItsCheckQueued(): void
    {
        $event = self::failed(self::job(), self::ACME_FOODS);
        $event->setForRetry();

        $this->listener->__invoke($event);

        self::assertSame(self::QUEUED_WITH_DRAFT, $this->rememberedRequests());
    }

    /**
     * @return iterable<string, array{object}>
     */
    public static function otherMessages(): iterable
    {
        yield 'the end of a check' => [EndIntakeRequest::stored(new OrganizationId(self::ACME_FOODS), new IntakeCheckId(self::CHECK), new UserId(self::OMAR))];
        yield 'a summary job' => [new GenerateSummary(self::ACME_FOODS, self::CHECK, self::OMAR)];
    }

    #[Test]
    #[DataProvider('otherMessages')]
    public function anotherMessageMessengerGivesUpOnEndsNoCheck(object $other): void
    {
        $this->listener->__invoke(self::failed($other, self::ACME_FOODS));

        self::assertSame(self::QUEUED_WITH_DRAFT, $this->rememberedRequests());
    }

    #[Test]
    public function aJobStampedForAnotherOrganizationOrNoneEndsNoCheck(): void
    {
        $this->listener->__invoke(self::failed(self::job(), self::BETA_BAKERY));
        $this->listener->__invoke(self::failed(self::job(), null));

        self::assertSame(self::QUEUED_WITH_DRAFT, $this->rememberedRequests());
    }

    /** A throw from a worker event listener would stop worker-heavy. */
    #[Test]
    public function aStateValkeyCannotReachIsLoggedByItsClassAndNotThrown(): void
    {
        $this->requests->failure = new \RedisException('read error on connection');

        $this->listener->__invoke(self::failed(self::job(), self::ACME_FOODS));

        self::assertSame(self::QUEUED_WITH_DRAFT, $this->rememberedRequests());
        self::assertSame(
            [['An intake check could not be ended; it stays queued until it expires.', ['exception_class' => \RedisException::class]]],
            array_map(static fn (LogRecord $record): array => [$record->message, $record->context], $this->logRecords->getRecords()),
        );
    }

    #[Test]
    public function aJobNamingNoCheckIdIsLoggedAndNotThrown(): void
    {
        $this->listener->__invoke(self::failed(new GenerateIntakeSuggestion(self::ACME_FOODS, 'not-a-check', self::OMAR), self::ACME_FOODS));

        self::assertSame(self::QUEUED_WITH_DRAFT, $this->rememberedRequests());
        self::assertSame(
            [['An intake check could not be ended; it stays queued until it expires.', ['exception_class' => \InvalidArgumentException::class]]],
            array_map(static fn (LogRecord $record): array => [$record->message, $record->context], $this->logRecords->getRecords()),
        );
    }

    /**
     * @return array{mixed, mixed, mixed} the states, the drafts and the open checks
     */
    private function rememberedRequests(): array
    {
        return [$this->requests->states, $this->requests->drafts, $this->requests->openChecks];
    }

    private static function job(): GenerateIntakeSuggestion
    {
        return new GenerateIntakeSuggestion(self::ACME_FOODS, self::CHECK, self::OMAR);
    }

    private static function failed(object $message, ?string $stampedOrganizationId): WorkerMessageFailedEvent
    {
        $stamps = null === $stampedOrganizationId ? [] : [new TenantStamp(new OrganizationId($stampedOrganizationId))];

        return new WorkerMessageFailedEvent(new Envelope($message, $stamps), 'async_ai', new \RuntimeException('The job failed.'));
    }
}
