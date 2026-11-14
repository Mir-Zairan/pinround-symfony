<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Messenger;

use App\Notification\Application\Digest\Deliveries;
use App\Notification\Application\Digest\Digest;
use App\Notification\Application\Digest\DigestItems;
use App\Notification\Application\Digest\SendDigest;
use App\Notification\Application\Notify\DeliveryTimes;
use App\Notification\Infrastructure\Mail\DigestMail;
use App\Organization\Api\MemberEmailQueries;
use App\SharedKernel\Application\MessageForAnotherTenantRefused;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Mime\Address;

/**
 * Emails one person their digest (FR-PNG-09), in worker-notify inside the bus transaction under the message's
 * TenantStamp. Nothing is sent when their last digest is under DIGEST_INTERVAL_HOURS old, in their quiet hours, when
 * nothing new waits, or when they have no address (not a member, or blocked). A run holds the person's digest lock from
 * before it reads their last digest until it commits, so a second run, even one in the next slot, waits and then sees
 * the first's digest; the digest is recorded in ntf_delivery under the window key of the current slot, so of two runs
 * in one slot only one sends; and the email is dispatched after the bus commits, so a rollback sends nothing. Here, not in Application: the clock and the mailer
 * message are vendor classes, which only Infrastructure may use (deptrac.yaml).
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class SendDigestHandler
{
    /** A person gets at most one digest this many hours; the window key slots are this long too. */
    public const int DIGEST_INTERVAL_HOURS = 3;

    public function __construct(
        private TenantContext $tenantContext,
        private Deliveries $deliveries,
        private DeliveryTimes $deliveryTimes,
        private DigestItems $digestItems,
        private MemberEmailQueries $memberEmailQueries,
        private DigestMail $digestMail,
        #[Autowire(service: 'command.bus')]
        private MessageBusInterface $commandBus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws \App\SharedKernel\Application\TenantNotSet when no organization is set, before any read
     * @throws MessageForAnotherTenantRefused             when the message names another organization than the one set
     */
    public function __invoke(SendDigest $sendDigest): void
    {
        $organizationId = new OrganizationId($sendDigest->organizationId);
        if (!$this->tenantContext->require()->equals($organizationId)) {
            throw new MessageForAnotherTenantRefused();
        }
        $recipientId = new UserId($sendDigest->userId);
        $now = $this->clock->now();

        $this->deliveries->lockFor($recipientId);
        // digest_recipients keeps offering a person whose unread items an earlier digest already listed, so past
        // DIGEST_INTERVAL_HOURS such a run gets beyond this read every 15 minutes and stops only at the empty digest.
        $lastSentAt = $this->deliveries->lastDigestSentAt($recipientId);
        if (null !== $lastSentAt && $lastSentAt > $now->modify(\sprintf('-%d hours', self::DIGEST_INTERVAL_HOURS))) {
            return;
        }
        if (null !== $this->deliveryTimes->deliverAfterFor($organizationId, $recipientId, $now)) {
            return;
        }

        // The previous digest covered items up to its own coveredUntil, so this one starts there: none is listed twice
        // and none falls between two digests.
        $since = null === $lastSentAt ? new \DateTimeImmutable('@0') : Digest::coveredUntilAt($lastSentAt);
        $digest = $this->digestItems->digestOf($recipientId, $since, $now);
        if ($digest->isEmpty()) {
            return;
        }
        $address = $this->memberEmailQueries->emailsOf($organizationId, [$recipientId])[$recipientId->toString()] ?? null;
        if (null === $address) {
            return;
        }
        if (!$this->deliveries->recordDigest($recipientId, self::windowKeyAt($now), $now)) {
            return;
        }

        $this->commandBus->dispatch(
            new SendEmailMessage($this->digestMail->emailFor($digest, new Address($address))),
            [new DispatchAfterCurrentBusStamp()],
        );
    }

    /**
     * The ntf_delivery window key of the DIGEST_INTERVAL_HOURS slot that holds $now, named by its UTC start, such as
     * "digest:2027-03-03T09:00:00+00:00" for any instant from 09:00 up to but not including 12:00 UTC.
     */
    public static function windowKeyAt(\DateTimeImmutable $now): string
    {
        $utc = $now->setTimezone(new \DateTimeZone('UTC'));
        $hour = (int) $utc->format('G');

        return Deliveries::DIGEST_WINDOW_PREFIX.$utc->setTime($hour - $hour % self::DIGEST_INTERVAL_HOURS, 0)->format(\DATE_ATOM);
    }
}
