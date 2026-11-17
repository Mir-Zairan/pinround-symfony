<?php

declare(strict_types=1);

namespace App\Tests\Integration\Assist\Summaries;

use App\Assist\Api\SummaryRequestView;
use App\Assist\Application\Summaries\SummaryRequests;
use App\Assist\Infrastructure\Valkey\ValkeySummaryRequests;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The request states on the test's state Valkey, with the clock frozen in March 2027. Beta Bakery has a ticket with
 * the same id as Acme Foods' checkout ticket, so a key without the organization would mix their states up.
 */
final class ValkeySummaryRequestsTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000c7b01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000c7b02';
    private const string CHECKOUT_TICKET = '0197a3c0-0000-7000-8000-0000000c7b31';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-0000000c7b32';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000c7b11';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000c7b12';
    private const string NOW = '2027-03-15 10:00:00 UTC';
    private const string FAILED_SENTENCE = 'The AI answer failed. Try again.';

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        $this->forgetStates();
    }

    protected function tearDown(): void
    {
        $this->forgetStates();
        parent::tearDown();
    }

    #[Test]
    public function theContainersPortIsThisClassOnTheStateValkey(): void
    {
        $port = self::getContainer()->get('test.assist_summary_requests');
        self::assertInstanceOf(ValkeySummaryRequests::class, $port);

        self::assertTrue($port->claim(self::acmeFoods(), self::checkoutTicket(), new UserId(self::OMAR)));

        self::assertSame(1, $this->connection()->exists(self::keyOf(self::ACME_FOODS, self::CHECKOUT_TICKET)));
    }

    #[Test]
    public function aClaimMarksTheTicketQueuedForTheRequesterAsOfNowForAnHour(): void
    {
        self::assertTrue($this->requests()->claim(self::acmeFoods(), self::checkoutTicket(), new UserId(self::OMAR)));

        $queued = $this->requests()->latestOf(self::acmeFoods(), self::checkoutTicket());
        self::assertNotNull($queued);
        self::assertSame([SummaryRequestView::QUEUED, self::OMAR, '2027-03-15 10:00:00', null], self::fieldsOf($queued));
        self::assertTimeToLiveIsAbout(3600, self::keyOf(self::ACME_FOODS, self::CHECKOUT_TICKET));
    }

    #[Test]
    public function aSecondClaimWhileQueuedIsRefusedAndKeepsTheFirstRequester(): void
    {
        self::assertTrue($this->requests()->claim(self::acmeFoods(), self::checkoutTicket(), new UserId(self::OMAR)));
        $this->clock->modify('2027-03-15 10:05:00 UTC');

        self::assertFalse($this->requests()->claim(self::acmeFoods(), self::checkoutTicket(), new UserId(self::LENA)));

        $queued = $this->requests()->latestOf(self::acmeFoods(), self::checkoutTicket());
        self::assertNotNull($queued);
        self::assertSame([SummaryRequestView::QUEUED, self::OMAR, '2027-03-15 10:00:00', null], self::fieldsOf($queued));
    }

    #[Test]
    public function aClaimReplacesAFailedState(): void
    {
        $this->requests()->recordFailure(self::acmeFoods(), self::checkoutTicket(), new UserId(self::OMAR), self::FAILED_SENTENCE);
        $this->clock->modify('2027-03-15 10:05:00 UTC');

        self::assertTrue($this->requests()->claim(self::acmeFoods(), self::checkoutTicket(), new UserId(self::LENA)));

        $queued = $this->requests()->latestOf(self::acmeFoods(), self::checkoutTicket());
        self::assertNotNull($queued);
        self::assertSame([SummaryRequestView::QUEUED, self::LENA, '2027-03-15 10:05:00', null], self::fieldsOf($queued));
        self::assertTimeToLiveIsAbout(3600, self::keyOf(self::ACME_FOODS, self::CHECKOUT_TICKET));
    }

    #[Test]
    public function aClaimTouchesOnlyItsOwnOrganizationAndTicket(): void
    {
        self::assertTrue($this->requests()->claim(self::acmeFoods(), self::checkoutTicket(), new UserId(self::OMAR)));

        self::assertTrue($this->requests()->claim(new OrganizationId(self::BETA_BAKERY), self::checkoutTicket(), new UserId(self::LENA)));
        self::assertTrue($this->requests()->claim(self::acmeFoods(), new TicketId(self::OTHER_TICKET), new UserId(self::LENA)));
        $this->requests()->release(new OrganizationId(self::BETA_BAKERY), self::checkoutTicket());

        $acmeFoodsState = $this->requests()->latestOf(self::acmeFoods(), self::checkoutTicket());
        self::assertNotNull($acmeFoodsState);
        self::assertSame([SummaryRequestView::QUEUED, self::OMAR, '2027-03-15 10:00:00', null], self::fieldsOf($acmeFoodsState));
        self::assertNull($this->requests()->latestOf(new OrganizationId(self::BETA_BAKERY), self::checkoutTicket()));
    }

    #[Test]
    public function aFailureReplacesTheQueuedStateWithItsSentenceForADay(): void
    {
        self::assertTrue($this->requests()->claim(self::acmeFoods(), self::checkoutTicket(), new UserId(self::OMAR)));
        $this->clock->modify('2027-03-15 10:02:00 UTC');

        $this->requests()->recordFailure(self::acmeFoods(), self::checkoutTicket(), new UserId(self::OMAR), self::FAILED_SENTENCE);

        $failed = $this->requests()->latestOf(self::acmeFoods(), self::checkoutTicket());
        self::assertNotNull($failed);
        self::assertSame([SummaryRequestView::FAILED, self::OMAR, '2027-03-15 10:02:00', self::FAILED_SENTENCE], self::fieldsOf($failed));
        self::assertTimeToLiveIsAbout(86400, self::keyOf(self::ACME_FOODS, self::CHECKOUT_TICKET));
    }

    #[Test]
    public function aReleasedTicketHasNoStateAndCanBeClaimedAgain(): void
    {
        self::assertTrue($this->requests()->claim(self::acmeFoods(), self::checkoutTicket(), new UserId(self::OMAR)));

        $this->requests()->release(self::acmeFoods(), self::checkoutTicket());

        self::assertNull($this->requests()->latestOf(self::acmeFoods(), self::checkoutTicket()));
        self::assertSame(0, $this->connection()->exists(self::keyOf(self::ACME_FOODS, self::CHECKOUT_TICKET)));
        self::assertTrue($this->requests()->claim(self::acmeFoods(), self::checkoutTicket(), new UserId(self::LENA)));
    }

    #[Test]
    public function aTicketNeverRequestedHasNoState(): void
    {
        self::assertNull($this->requests()->latestOf(self::acmeFoods(), self::checkoutTicket()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableValues(): iterable
    {
        yield 'not JSON' => ['not json'];
        yield 'a JSON string' => ['"queued"'];
        yield 'nested deeper than a state' => ['{"status": "queued", "requesterId": "'.self::OMAR.'", "at": 1805104800, "sentence": {"text": "nested"}}'];
        yield 'no requester' => ['{"status": "queued", "at": 1805104800, "sentence": null}'];
        yield 'a requester that is no user id' => ['{"status": "queued", "requesterId": "omar", "at": 1805104800, "sentence": null}'];
        yield 'a time that is no number' => ['{"status": "queued", "requesterId": "'.self::OMAR.'", "at": "2027-03-15", "sentence": null}'];
        yield 'an unknown status' => ['{"status": "running", "requesterId": "'.self::OMAR.'", "at": 1805104800, "sentence": null}'];
        yield 'a failure without its sentence' => ['{"status": "failed", "requesterId": "'.self::OMAR.'", "at": 1805104800, "sentence": null}'];
        yield 'a failure with a blank sentence' => ['{"status": "failed", "requesterId": "'.self::OMAR.'", "at": 1805104800, "sentence": " "}'];
    }

    #[Test]
    #[DataProvider('unreadableValues')]
    public function aValueThisClassDidNotWriteIsRefusedOnRead(string $unreadableValue): void
    {
        $this->connection()->set(self::keyOf(self::ACME_FOODS, self::CHECKOUT_TICKET), $unreadableValue);

        try {
            $this->requests()->latestOf(self::acmeFoods(), self::checkoutTicket());
            self::fail('An unreadable state was read.');
        } catch (\RuntimeException $refusal) {
            self::assertStringStartsWith('The summary request state holds ', $refusal->getMessage());
        }
    }

    #[Test]
    public function aWellFormedValueWrittenByHandIsReadAsTheStateItHolds(): void
    {
        $this->connection()->set(
            self::keyOf(self::ACME_FOODS, self::CHECKOUT_TICKET),
            '{"status": "failed", "requesterId": "'.self::OMAR.'", "at": 1805104800, "sentence": "'.self::FAILED_SENTENCE.'"}',
        );

        $failed = $this->requests()->latestOf(self::acmeFoods(), self::checkoutTicket());

        self::assertNotNull($failed);
        self::assertSame([SummaryRequestView::FAILED, self::OMAR, '2027-03-15 10:00:00', self::FAILED_SENTENCE], self::fieldsOf($failed));
    }

    #[Test]
    public function aClaimOverAValueTheScriptCannotDecodeFailsAndLeavesTheValue(): void
    {
        $this->connection()->set(self::keyOf(self::ACME_FOODS, self::CHECKOUT_TICKET), 'not json');

        try {
            $this->requests()->claim(self::acmeFoods(), self::checkoutTicket(), new UserId(self::OMAR));
            self::fail('A claim went through over a value the script cannot decode.');
        } catch (\RuntimeException $failure) {
            self::assertSame('The summary request state could not be claimed.', $failure->getMessage());
        }

        self::assertSame('not json', $this->connection()->get(self::keyOf(self::ACME_FOODS, self::CHECKOUT_TICKET)));
        self::assertNull($this->connection()->getLastError(), 'The script error stayed on the connection for the next caller.');
    }

    private function requests(): SummaryRequests
    {
        return new ValkeySummaryRequests($this->connection(), $this->clock);
    }

    /**
     * @return array{string, string, string, ?string} status, requester, time in UTC, sentence
     */
    private static function fieldsOf(SummaryRequestView $view): array
    {
        return [$view->status, $view->requesterId->toString(), $view->at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $view->sentence];
    }

    private function assertTimeToLiveIsAbout(int $expectedSeconds, string $key): void
    {
        $milliseconds = $this->connection()->pttl($key);
        self::assertIsInt($milliseconds);
        self::assertGreaterThan(($expectedSeconds - 5) * 1000, $milliseconds);
        self::assertLessThanOrEqual($expectedSeconds * 1000, $milliseconds);
    }

    private function forgetStates(): void
    {
        foreach ([self::ACME_FOODS, self::BETA_BAKERY] as $organizationId) {
            foreach ([self::CHECKOUT_TICKET, self::OTHER_TICKET] as $ticketId) {
                $this->connection()->del(self::keyOf($organizationId, $ticketId));
            }
        }
    }

    private static function keyOf(string $organizationId, string $ticketId): string
    {
        return 'summary_request:'.$organizationId.':'.$ticketId;
    }

    private static function acmeFoods(): OrganizationId
    {
        return new OrganizationId(self::ACME_FOODS);
    }

    private static function checkoutTicket(): TicketId
    {
        return new TicketId(self::CHECKOUT_TICKET);
    }

    private function connection(): \Redis
    {
        return self::getContainer()->get('state_valkey.connection');
    }
}
