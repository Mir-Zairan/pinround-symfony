<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification\Infrastructure\Messenger;

use App\Notification\Application\Digest\Digest;
use App\Notification\Application\Digest\DigestItem;
use App\Notification\Application\Digest\SendDigest;
use App\Notification\Application\Notify\DeliveryTimes;
use App\Notification\Application\Notify\NotificationKind;
use App\Notification\Infrastructure\Mail\DigestMail;
use App\Notification\Infrastructure\Messenger\SendDigestHandler;
use App\SharedKernel\Application\MessageForAnotherTenantRefused;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Unit\Notification\Application\Notify\InMemoryMemberTimeZones;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\BodyRendererInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouterInterface;

/**
 * The order of SendDigestHandler's checks and what each stops, with in-memory ports; SendDigestHandlerTest in
 * tests/Integration runs it on the database and the real buses. Lena's clock is Europe/Berlin (UTC+1 in March 2027).
 */
final class SendDigestHandlerTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000e8a01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000e8a02';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000e8a11';
    private const string LENA_ADDRESS = 'lena@acme-foods.example';
    private const string NOW = '2027-03-03 10:05:00 UTC';

    private InMemoryDeliveries $deliveries;
    private RecordingDigestItems $digestItems;
    private CountingPingSettings $pingSettings;
    private InMemoryMemberEmails $memberEmails;
    private RecordingBus $commandBus;
    private TenantContext $tenantContext;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->deliveries = new InMemoryDeliveries();
        $this->digestItems = new RecordingDigestItems(self::digestOfOneItem());
        $this->pingSettings = new CountingPingSettings(false);
        $this->memberEmails = new InMemoryMemberEmails([self::LENA => self::LENA_ADDRESS]);
        $this->commandBus = new RecordingBus();
        $this->tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        $this->tenantContext->set(new OrganizationId(self::ACME_FOODS));
        $this->clock = new MockClock(self::NOW);
    }

    #[Test]
    public function aDueDigestIsRecordedForTheSlotAndItsEmailWaitsForTheBusToCommit(): void
    {
        $this->handle();

        self::assertSame(['digest:2027-03-03T09:00:00+00:00' => '2027-03-03T10:05:00+00:00'], $this->deliveries->recorded());
        self::assertCount(1, $this->commandBus->envelopes);
        $envelope = $this->commandBus->envelopes[0];
        self::assertNotNull($envelope->last(DispatchAfterCurrentBusStamp::class));
        $message = $envelope->getMessage();
        self::assertInstanceOf(SendEmailMessage::class, $message);
        $email = $message->getMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame([self::LENA_ADDRESS], array_map(static fn (Address $address): string => $address->getAddress(), $email->getTo()));
        self::assertSame('1 thing waits on you in pinround', $email->getSubject());
    }

    #[Test]
    public function aDigestUnderThreeHoursOldStopsTheRunBeforeAnyOtherRead(): void
    {
        $this->deliveries = new InMemoryDeliveries(['digest:2027-03-03T06:00:00+00:00' => '2027-03-03 07:05:01 UTC']);
        $this->pingSettings = new CountingPingSettings(true);

        $this->handle();

        self::assertSame(0, $this->pingSettings->readCount);
        self::assertSame([], $this->digestItems->calls);
        self::assertSame(0, $this->memberEmails->readCount);
        self::assertSame(['digest:2027-03-03T06:00:00+00:00' => '2027-03-03T07:05:01+00:00'], $this->deliveries->recorded());
        self::assertSame([], $this->commandBus->envelopes);
    }

    #[Test]
    public function aDigestExactlyThreeHoursOldLetsTheNextOneGoAndItStartsWhereThatOneEnded(): void
    {
        $this->deliveries = new InMemoryDeliveries(['digest:2027-03-03T06:00:00+00:00' => '2027-03-03 07:05:00 UTC']);

        $this->handle();

        // The digest of 07:05 listed items up to 06:05, its coveredUntil, so this one starts there, not at 07:05.
        self::assertSame([['2027-03-03T06:05:00+00:00', '2027-03-03T10:05:00+00:00']], $this->digestItems->calls);
        self::assertArrayHasKey('digest:2027-03-03T09:00:00+00:00', $this->deliveries->recorded());
        self::assertCount(1, $this->commandBus->envelopes);
    }

    #[Test]
    public function aFirstDigestListsEveryItemSinceTheStartOfTime(): void
    {
        $this->handle();

        self::assertSame([['1970-01-01T00:00:00+00:00', '2027-03-03T10:05:00+00:00']], $this->digestItems->calls);
    }

    #[Test]
    public function inTheRecipientsQuietHoursNoItemIsReadAndNothingIsRecorded(): void
    {
        $this->pingSettings = new CountingPingSettings(true);
        // 21:00 UTC is 22:00 in Berlin.
        $this->clock = new MockClock('2027-03-03 21:00:00 UTC');

        $this->handle();

        self::assertSame(1, $this->pingSettings->readCount);
        self::assertSame([], $this->digestItems->calls);
        self::assertSame([], $this->deliveries->recorded());
        self::assertSame([], $this->commandBus->envelopes);
    }

    #[Test]
    public function withQuietHoursOnADigestOutsideThemIsSent(): void
    {
        $this->pingSettings = new CountingPingSettings(true);

        $this->handle();

        self::assertCount(1, $this->commandBus->envelopes);
    }

    #[Test]
    public function anEmptyDigestRecordsNothingAndSendsNothing(): void
    {
        $this->digestItems = new RecordingDigestItems(new Digest([], 0, Digest::coveredUntilAt(new \DateTimeImmutable(self::NOW))));

        $this->handle();

        self::assertCount(1, $this->digestItems->calls);
        self::assertSame(0, $this->memberEmails->readCount);
        self::assertSame([], $this->deliveries->recorded());
        self::assertSame([], $this->commandBus->envelopes);
    }

    #[Test]
    public function aPersonWithNoAddressGetsNoDigestRecorded(): void
    {
        $this->memberEmails = new InMemoryMemberEmails([]);

        $this->handle();

        self::assertSame(1, $this->memberEmails->readCount);
        self::assertSame([], $this->deliveries->recorded());
        self::assertSame([], $this->commandBus->envelopes);
    }

    #[Test]
    public function aRunThatLosesTheSlotToAnotherRunAtOnceSendsNothing(): void
    {
        // The other run's row is not committed yet, so lastDigestSentAt does not see it; the insert then conflicts.
        $this->deliveries = new InMemoryDeliveries([], ['digest:2027-03-03T09:00:00+00:00']);

        $this->handle();

        self::assertCount(1, $this->digestItems->calls);
        self::assertSame([], $this->deliveries->recorded());
        self::assertSame([], $this->commandBus->envelopes);
    }

    #[Test]
    public function aMessageForAnotherOrganizationIsRefusedBeforeAnyRead(): void
    {
        try {
            $this->handle(self::BETA_BAKERY);
            self::fail('A digest of Beta Bakery ran under Acme Foods.');
        } catch (MessageForAnotherTenantRefused $refused) {
            self::assertSame('A message for another organization cannot be dispatched or handled while this organization is set.', $refused->getMessage());
        }

        self::assertSame(0, $this->deliveries->lastReadCount);
        self::assertSame([], $this->digestItems->calls);
        self::assertSame([], $this->deliveries->recorded());
        self::assertSame([], $this->commandBus->envelopes);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function instantsAndTheirWindowKeys(): iterable
    {
        yield 'the first minute of a slot' => ['2027-03-03T09:00:00Z', 'digest:2027-03-03T09:00:00+00:00'];
        yield 'the last second of a slot' => ['2027-03-03T11:59:59Z', 'digest:2027-03-03T09:00:00+00:00'];
        yield 'the next slot' => ['2027-03-03T12:00:00Z', 'digest:2027-03-03T12:00:00+00:00'];
        yield 'midnight' => ['2027-03-03T02:59:59Z', 'digest:2027-03-03T00:00:00+00:00'];
        yield 'an instant given in another zone is slotted on UTC' => ['2027-03-03T01:30:00+05:30', 'digest:2027-03-02T18:00:00+00:00'];
    }

    #[Test]
    #[DataProvider('instantsAndTheirWindowKeys')]
    public function theWindowKeyNamesTheUtcStartOfTheThreeHourSlot(string $instant, string $expectedWindowKey): void
    {
        self::assertSame($expectedWindowKey, SendDigestHandler::windowKeyAt(new \DateTimeImmutable($instant)));
    }

    private function handle(string $organizationId = self::ACME_FOODS): void
    {
        $router = self::createStub(RouterInterface::class);
        $router->method('getContext')->willReturn(new RequestContext());
        $router->method('generate')->willReturn('http://localhost/t/SHOP-7');

        $handler = new SendDigestHandler(
            $this->tenantContext,
            $this->deliveries,
            new DeliveryTimes($this->pingSettings, new InMemoryMemberTimeZones(self::ACME_FOODS, [self::LENA => 'Europe/Berlin'])),
            $this->digestItems,
            $this->memberEmails,
            new DigestMail(self::createStub(BodyRendererInterface::class), $router, 'http://localhost'),
            $this->commandBus,
            $this->clock,
        );

        $handler(new SendDigest($organizationId, self::LENA));
    }

    private static function digestOfOneItem(): Digest
    {
        return new Digest(
            [new DigestItem('Mia', NotificationKind::Ping, 'SHOP-7', 'Checkout button is hidden', null, new \DateTimeImmutable('2027-03-03 08:30:00 UTC'))],
            1,
            Digest::coveredUntilAt(new \DateTimeImmutable(self::NOW)),
        );
    }
}
