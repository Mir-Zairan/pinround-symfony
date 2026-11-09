<?php

declare(strict_types=1);

namespace App\Tests\Integration\Organization;

use App\Organization\Api\Event\MemberInvited;
use App\Organization\Application\EmailInvitationLink;
use App\Organization\Application\SendInvitationLink;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use App\Tests\Fixtures\Messaging\CapturedLogRecords;
use App\Tests\Fixtures\Messaging\FailOnceOnMemberInvitedHandler;
use App\Tests\Functional\Identity\LoginLogLeakTest;
use App\Tests\Integration\Messaging\RunsTheWorkerUntilIdle;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\RawMessage;

/**
 * The invitation email on the real outbox, relay, worker-notify transport and org_invitation table: MemberInvited
 * queues an EmailInvitationLink, whose handler makes the link token in its own transaction and stores only its
 * SHA-256; a redelivered message or a repeated MemberInvited sends no second link.
 */
final class EmailInvitationLinkHandlerTest extends KernelTestCase
{
    use RunsTheWorkerUntilIdle;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000007d1';
    private const string SLUG = 'acme-foods-invitation-link';
    // Inside the months the migrations created aud_event partitions for, as the audit consumer handles the same event.
    private const string INVITED_AT = '2027-03-15 10:00:00 UTC';
    // A minute later, so the worker's clock and not the event's time decides whether the invitation is still open.
    private const string NOW = '2027-03-15 10:01:00 UTC';

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock(self::NOW);
        self::getContainer()->set('clock', $this->clock);
        $this->rows()->removeAllOf(self::ACME_FOODS);
        $this->migrator()->executeStatement('TRUNCATE aud_event');
        $this->migrator()->executeStatement('DELETE FROM processed_message WHERE handler = ?', [SendInvitationLink::HANDLER_NAME]);
        $this->migrator()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox'");
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        $this->rows()->removeAllOf(self::ACME_FOODS);
        $this->migrator()->executeStatement('TRUNCATE aud_event');
        $this->migrator()->executeStatement('DELETE FROM processed_message WHERE handler = ?', [SendInvitationLink::HANDLER_NAME]);
        parent::tearDown();
    }

    #[Test]
    public function theWorkerEmailsOneLinkWhoseTokenOnlyTheEmailHoldsAndResolvesToTheInvitation(): void
    {
        $capturedLogRecords = self::getContainer()->get(CapturedLogRecords::class);
        self::assertInstanceOf(CapturedLogRecords::class, $capturedLogRecords);
        $invitationId = $this->inviteMia();
        $capturedLogRecords->clear();

        self::runTheWorkerUntilIdle('outbox');
        $relayed = $this->asyncNotify()->getSent();
        self::assertSame([MemberInvited::class], array_map(static fn (Envelope $envelope): string => $envelope->getMessage()::class, \array_slice($relayed, -1)));
        self::assertCount(0, self::getMailerMessages(), 'The relay only moves the event; worker-notify sends the email.');

        self::runTheWorkerUntilIdle('async_notify');
        // Taken before resolve() below, whose own query logs the token as its parameter.
        $workerLogRecords = $capturedLogRecords->all();

        $emails = self::getMailerMessages();
        self::assertCount(1, $emails);
        $email = $emails[0];
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame(['mia@acme-foods.example'], array_map(static fn ($address): string => $address->getAddress(), $email->getTo()));
        self::assertSame('You are invited to Acme Foods on pinround', $email->getSubject());
        self::assertSame(1, preg_match('~^http://localhost/invite/([A-Za-z0-9_-]{43})$~m', (string) $email->getTextBody(), $match));
        $token = $match[1];
        self::assertStringContainsString('href="http://localhost/invite/'.$token.'"', (string) $email->getHtmlBody());
        self::assertStringContainsString('72 hours', (string) $email->getTextBody());

        self::assertSame(hash('sha256', $token), $this->storedTokenHashHex($invitationId));
        self::assertSame([[$invitationId, self::ACME_FOODS]], $this->resolve($token));
        self::assertSame([], $this->resolve(hash('sha256', $token, true)), 'The stored value is the hash of the token, not the token.');

        self::assertNotSame([], $workerLogRecords, 'the worker logged nothing, so nothing was checked');
        self::assertStringNotContainsString($token, implode("\n", array_map(LoginLogLeakTest::textOf(...), $workerLogRecords)));
        $queued = $this->asyncNotify()->getSent();
        self::assertEquals(new EmailInvitationLink(self::ACME_FOODS, $invitationId), $this->sentEnvelopeOf(EmailInvitationLink::class)->getMessage());
        foreach ($queued as $envelope) {
            self::assertStringNotContainsString($token, serialize($envelope->getMessage()));
        }
    }

    #[Test]
    public function aRedeliveredEmailInvitationLinkSendsNoSecondEmail(): void
    {
        $invitationId = $this->inviteMia();
        self::runTheWorkerUntilIdle('outbox');
        self::runTheWorkerUntilIdle('async_notify');
        $firstHash = $this->storedTokenHashHex($invitationId);
        $emailInvitationLink = $this->sentEnvelopeOf(EmailInvitationLink::class);

        $this->asyncNotify()->send($emailInvitationLink);
        self::runTheWorkerUntilIdle('async_notify');

        self::assertCount(1, self::getMailerMessages());
        self::assertSame($firstHash, $this->storedTokenHashHex($invitationId));
        self::assertSame(1, $this->processedMessageCount(), 'The ledger skipped the redelivered message.');
    }

    /**
     * The handlers of a received event share one transaction, and its retry runs them all again: an email sent inside
     * it would stay sent while its link token rolled back, and the retry would send a second one.
     */
    #[Test]
    public function aMemberInvitedHandlerThatFailsOnceLeavesOneEmailWithAWorkingLinkAndTheAuditEntry(): void
    {
        $failingHandler = self::getContainer()->get(FailOnceOnMemberInvitedHandler::class);
        self::assertInstanceOf(FailOnceOnMemberInvitedHandler::class, $failingHandler);
        $invitationId = $this->inviteMia();
        self::runTheWorkerUntilIdle('outbox');
        $failingHandler->failNextDelivery();

        self::runTheWorkerUntilIdle('async_notify');
        // The in-memory transport holds a retry until the clock passes the time it was sent at.
        $this->clock->sleep(1);
        self::runTheWorkerUntilIdle('async_notify');

        self::assertCount(2, $this->sentEnvelopesOf(MemberInvited::class), 'The event was not retried, so nothing failed.');
        self::assertCount(2, $this->sentEnvelopesOf(EmailInvitationLink::class), 'Each try of the event queued the email.');
        $emails = self::getMailerMessages();
        self::assertCount(1, $emails);
        self::assertSame([[$invitationId, self::ACME_FOODS]], $this->resolve(self::linkTokenOf($emails[0])));
        self::assertSame([$invitationId], $this->invitedAuditTargets(), 'The retry recorded the audit entry.');
    }

    #[Test]
    public function aSendThatFailsOnceIsRetriedWithOneLinkAndOneEmail(): void
    {
        $invitationId = $this->inviteMia();
        self::runTheWorkerUntilIdle('outbox');
        self::getContainer()->get('event.bus')->dispatch($this->sentEnvelopeOf(MemberInvited::class)->with(new ReceivedStamp('async_notify')));
        $envelope = $this->sentEnvelopeOf(EmailInvitationLink::class)->with(new ReceivedStamp('async_notify'));
        $isMailServerDown = true;
        // Fails the first real send. services_resetter->reset() below clears the mailer's message log, so the count
        // covers only the sends after it.
        self::getContainer()->get('event_dispatcher')->addListener(MessageEvent::class, static function (MessageEvent $event) use (&$isMailServerDown): void {
            if (!$event->isQueued() && $isMailServerDown) {
                $isMailServerDown = false;

                throw new TransportException('The mail server cannot be reached.');
            }
        }, 1024);

        try {
            self::getContainer()->get('command.bus')->dispatch($envelope);
            self::fail('The first send did not fail.');
        } catch (HandlerFailedException $failure) {
            self::assertInstanceOf(TransportException::class, $failure->getPrevious());
        }
        // What the worker does after a failed message; the rollback left the entity manager closed.
        self::getContainer()->get('services_resetter')->reset();
        self::assertNull($this->storedTokenHashHex($invitationId));
        self::assertSame(0, $this->processedMessageCount());
        self::getContainer()->get('command.bus')->dispatch($envelope);
        self::getContainer()->get('command.bus')->dispatch($envelope);

        $emails = self::getMailerMessages();
        self::assertCount(1, $emails);
        self::assertSame([[$invitationId, self::ACME_FOODS]], $this->resolve(self::linkTokenOf($emails[0])));
        self::assertSame(1, $this->processedMessageCount());
    }

    /**
     * A copy without the original's message id passes OnceOnlyHandling, so only the link token already on the
     * invitation keeps a second, different link from going out.
     */
    #[Test]
    public function aSecondMemberInvitedOfTheSameInvitationSendsNoSecondEmail(): void
    {
        $invitationId = $this->inviteMia();
        self::runTheWorkerUntilIdle('outbox');
        self::runTheWorkerUntilIdle('async_notify');
        $firstHash = $this->storedTokenHashHex($invitationId);
        $memberInvited = $this->sentEnvelopeOf(MemberInvited::class);
        $tenantStamp = $memberInvited->last(TenantStamp::class);
        self::assertNotNull($tenantStamp);
        // Without it the worker dispatches the copy on command.bus, which has no handler for it.
        $busNameStamp = $memberInvited->last(BusNameStamp::class);
        self::assertNotNull($busNameStamp);

        $this->asyncNotify()->send(new Envelope($memberInvited->getMessage(), [$tenantStamp, $busNameStamp]));
        self::runTheWorkerUntilIdle('async_notify');

        self::assertCount(1, self::getMailerMessages());
        self::assertSame($firstHash, $this->storedTokenHashHex($invitationId));
        self::assertCount(2, $this->sentEnvelopesOf(EmailInvitationLink::class));
        self::assertSame(2, $this->processedMessageCount(), 'The second EmailInvitationLink ran under its own message id.');
    }

    private function inviteMia(): string
    {
        $invitedAt = new \DateTimeImmutable(self::INVITED_AT);

        return $this->transactions()->committedAs(self::ACME_FOODS, function () use ($invitedAt): string {
            $this->commands()->setUpShop(self::ACME_FOODS, self::SLUG, $invitedAt);

            return $this->commands()->invite(self::ACME_FOODS, 'mia@acme-foods.example', $invitedAt);
        });
    }

    private static function linkTokenOf(RawMessage $email): string
    {
        self::assertInstanceOf(TemplatedEmail::class, $email);
        self::assertSame(1, preg_match('~^http://localhost/invite/([A-Za-z0-9_-]{43})$~m', (string) $email->getTextBody(), $match));

        return $match[1];
    }

    /**
     * @param class-string $messageClass
     */
    private function sentEnvelopeOf(string $messageClass): Envelope
    {
        $sent = $this->sentEnvelopesOf($messageClass);
        self::assertCount(1, $sent);

        return $sent[0];
    }

    /**
     * @param class-string $messageClass
     *
     * @return list<Envelope> sent to async_notify, retries included
     */
    private function sentEnvelopesOf(string $messageClass): array
    {
        return array_values(array_filter($this->asyncNotify()->getSent(), static fn (Envelope $envelope): bool => $envelope->getMessage() instanceof $messageClass));
    }

    /**
     * setUpShop() records entries of its own; only the member.invited ones are returned.
     *
     * @return list<mixed>
     */
    private function invitedAuditTargets(): array
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->entityManager()->getConnection()->fetchFirstColumn(
            "SELECT target_id FROM aud_event WHERE organization_id = ? AND action = 'member.invited' ORDER BY id",
            [self::ACME_FOODS],
        ));
    }

    private function storedTokenHashHex(string $invitationId): mixed
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): mixed => $this->entityManager()->getConnection()->fetchOne(
            "SELECT encode(token_hash, 'hex') FROM org_invitation WHERE id = ?",
            [$invitationId],
        ));
    }

    /**
     * resolve_invitation compares the expiry with the database's now(), not the test clock, so the expiry is moved
     * far out first: the test then does not depend on today's date.
     *
     * @return list<list<mixed>>
     */
    private function resolve(string $token): array
    {
        $this->migrator()->executeStatement("UPDATE org_invitation SET expires_at = '2099-01-01 00:00:00+00' WHERE organization_id = ?", [self::ACME_FOODS]);

        return array_map(
            array_values(...),
            $this->entityManager()->getConnection()->fetchAllAssociative('SELECT invitation_id, organization_id FROM resolve_invitation(sha256(?::bytea))', [$token], [ParameterType::BINARY]),
        );
    }

    private function processedMessageCount(): int
    {
        $count = $this->migrator()->fetchOne('SELECT count(*) FROM processed_message WHERE handler = ?', [SendInvitationLink::HANDLER_NAME]);
        self::assertIsInt($count);

        return $count;
    }

    private function asyncNotify(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async_notify');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function commands(): OrganizationCommandsOnTheEventBus
    {
        return new OrganizationCommandsOnTheEventBus($this->entityManager(), self::getContainer()->get('event.bus'));
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }

    private function rows(): OrganizationRows
    {
        return new OrganizationRows($this->migrator(), self::getContainer()->get(TenantContext::class));
    }
}
