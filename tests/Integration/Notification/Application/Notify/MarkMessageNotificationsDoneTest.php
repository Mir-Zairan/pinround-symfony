<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification\Application\Notify;

use App\Conversation\Api\Event\MessageRemoved;
use App\Conversation\Api\Event\QuestionAnswered;
use App\Conversation\Api\Event\QuestionAsked;
use App\Notification\Api\BellGroup;
use App\Notification\Api\NotificationQueries;
use App\Notification\Application\Digest\DigestItem;
use App\Notification\Application\Digest\DigestItems;
use App\Notification\Application\Notify\MarkMessageNotificationsDone;
use App\Notification\Application\Notify\NotifyRecipients;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Messenger\MessageBeingHandled;
use App\Tests\Integration\Visibility\VisibilityLeakCast;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * From a closed question to Mia's bell and digest: NotifyRecipients and MarkMessageNotificationsDone from the container
 * on the visibility cast's team-only SHOP ticket, NotificationQueries and DigestItems reading what they left. Raj asks
 * Mia QUESTION; Lena's earlier question to Mia (OTHER_QUESTION) stays open throughout, as a bell row only, since the
 * ticket allows one open question in cnv_message. Mia lives in Europe/Berlin (UTC+1 in March), whose quiet hours run
 * 18:00 to 09:00, so 21:00 UTC is 22:00 for her and a held row waits until 08:00 UTC. Each case runs in one
 * rolled-back transaction under Acme Foods.
 */
final class MarkMessageNotificationsDoneTest extends VisibilityLeakCast
{
    private const string QUESTION = '0197a3c0-0000-7000-8000-0000000e9e01';
    private const string OTHER_QUESTION = '0197a3c0-0000-7000-8000-0000000e9e02';
    /** In a month ntf_notification has a partition for (Version20261114130000), before Berlin's March clock change. */
    private const string ASKED_AT = '2027-03-03 21:00:00 UTC';
    private const string OTHER_QUESTION_AT = '2027-03-03 16:00:00 UTC';
    private const string QUIET_HOURS_END = '2027-03-04 08:00:00 UTC';

    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock(new \DateTimeImmutable(self::ASKED_AT));
        self::getContainer()->set('clock', $this->clock);
        self::getContainer()->get('cache.bell_count')->clear();
    }

    #[Test]
    public function aHeldQuestionWithdrawnDuringTheHoldNeverReachesTheBellOrTheDigest(): void
    {
        [$heldUntil, $bellAfterTheHold, $digestItems] = self::transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $this->setUpMia(isQuietHoursOn: true);
            $this->addLenasOpenQuestionToMia();
            $this->askMiaAsRaj();
            $heldUntil = $this->miasQuestionRowsHeldUntil();

            $this->closeQuestion("removed_at = '2027-03-03 21:30:00+00'");
            $this->markDone(new MessageRemoved(self::ACME_FOODS, self::teamOnlyTicketId(), self::QUESTION, self::VIEWERS['Raj'][0], new \DateTimeImmutable('2027-03-03 21:30:00 UTC')));
            $this->clock->modify(self::QUIET_HOURS_END);

            return [$heldUntil, $this->miasBell(), $this->miasDigestItems()];
        });

        self::assertSame([self::OTHER_QUESTION => null, self::QUESTION => '2027-03-04 08:00:00+00'], $heldUntil);
        self::assertSame([1, [['Lena', 'question']]], $bellAfterTheHold);
        self::assertSame([['Lena', 'question', '2027-03-03 16:00:00+00']], $digestItems);
    }

    #[Test]
    public function aQuestionAnsweredBeforeTheBellIsOpenedLeavesOnlyTheOtherOpenQuestion(): void
    {
        [$bellBeforeTheAnswer, $bellAfterTheAnswer, $digestItems] = self::transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $this->setUpMia(isQuietHoursOn: false);
            $this->addLenasOpenQuestionToMia();
            $this->askMiaAsRaj();
            $bellBeforeTheAnswer = $this->miasBell();

            $this->clock->modify('2027-03-03 21:10:00 UTC');
            $this->closeQuestion("resolved_at = '2027-03-03 21:10:00+00', resolved_by = '".self::VIEWERS['Mia'][0]."'");
            $this->markDone(new QuestionAnswered(self::ACME_FOODS, self::teamOnlyTicketId(), self::QUESTION, self::VIEWERS['Raj'][0], self::VIEWERS['Mia'][0], $this->clock->now()));

            return [$bellBeforeTheAnswer, $this->miasBell(), $this->miasDigestItems()];
        });

        self::assertSame([2, [['Raj', 'question'], ['Lena', 'question']]], $bellBeforeTheAnswer);
        self::assertSame([1, [['Lena', 'question']]], $bellAfterTheAnswer);
        self::assertSame([['Lena', 'question', '2027-03-03 16:00:00+00']], $digestItems);
    }

    /**
     * Mia in Europe/Berlin and the organization's quiet hours switch, before anything reads either: Organization's
     * settings reads are cached for the rest of the request.
     */
    private function setUpMia(bool $isQuietHoursOn): void
    {
        self::assertSame(1, self::connection()->executeStatement("UPDATE idn_user SET time_zone = 'Europe/Berlin' WHERE id = ?", [self::VIEWERS['Mia'][0]]));
        self::assertSame(1, self::connection()->executeStatement(
            "UPDATE org_settings SET data = data || jsonb_build_object('quiet_hours', ?::boolean) WHERE organization_id = ?",
            [$isQuietHoursOn ? 'true' : 'false', self::ACME_FOODS],
        ));
    }

    /**
     * The bell row NotifyRecipients wrote for Lena's question at 16:00 UTC, outside Mia's quiet hours.
     */
    private function addLenasOpenQuestionToMia(): void
    {
        $ticketKey = self::connection()->fetchOne('SELECT key FROM tkt_ticket WHERE organization_id = ? AND id = ?', [self::ACME_FOODS, self::teamOnlyTicketId()]);
        self::assertIsString($ticketKey);
        self::assertSame(1, self::connection()->executeStatement(
            "INSERT INTO ntf_notification (organization_id, id, user_id, kind, actor_id, ticket_id, data, created_at) VALUES (?, ?, ?, 'question', ?, ?, ?, ?)",
            [
                self::ACME_FOODS,
                Uuid::v7()->toRfc4122(),
                self::VIEWERS['Mia'][0],
                self::VIEWERS['Lena'][0],
                self::teamOnlyTicketId(),
                json_encode(['actorName' => 'Lena', 'ticketKey' => $ticketKey, 'messageId' => self::OTHER_QUESTION], \JSON_THROW_ON_ERROR),
                self::OTHER_QUESTION_AT,
            ],
        ));
    }

    private function askMiaAsRaj(): void
    {
        self::addOpenQuestion(self::teamOnlyTicketId(), self::QUESTION, 'Mia', 'Raj', self::ASKED_AT);
        $messageBeingHandled = self::getContainer()->get(MessageBeingHandled::class);
        $messageBeingHandled->set(Uuid::v7()->toRfc4122());
        try {
            self::getContainer()->get(NotifyRecipients::class)(new QuestionAsked(
                self::ACME_FOODS,
                self::teamOnlyTicketId(),
                self::QUESTION,
                self::VIEWERS['Mia'][0],
                self::VIEWERS['Raj'][0],
                new \DateTimeImmutable(self::ASKED_AT),
            ));
        } finally {
            $messageBeingHandled->reset();
        }
    }

    private function closeQuestion(string $closingColumns): void
    {
        self::assertSame(1, self::connection()->executeStatement(
            'UPDATE cnv_message SET '.$closingColumns.' WHERE organization_id = ? AND id = ?',
            [self::ACME_FOODS, self::QUESTION],
        ));
    }

    private function markDone(QuestionAnswered|MessageRemoved $event): void
    {
        self::getContainer()->get(MarkMessageNotificationsDone::class)($event);
    }

    /**
     * @return array<string, ?string> the UTC deliver_after of each of Mia's question rows, by the question it is about
     */
    private function miasQuestionRowsHeldUntil(): array
    {
        $rows = self::connection()->fetchAllAssociative(
            "SELECT data->>'messageId' AS message_id, to_char(deliver_after AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS\"+00\"') AS deliver_after
             FROM ntf_notification WHERE organization_id = ? AND user_id = ? AND kind = 'question' ORDER BY created_at, id",
            [self::ACME_FOODS, self::VIEWERS['Mia'][0]],
        );

        $heldUntil = [];
        foreach ($rows as $row) {
            self::assertIsString($row['message_id']);
            self::assertTrue(null === $row['deliver_after'] || \is_string($row['deliver_after']));
            $heldUntil[$row['message_id']] = $row['deliver_after'];
        }

        return $heldUntil;
    }

    /**
     * @return array{int, list<array{string, string}>} the open count, and each group's sender and newest kind
     */
    private function miasBell(): array
    {
        $bell = self::getContainer()->get(NotificationQueries::class)->bell(new UserId(self::VIEWERS['Mia'][0]));

        return [$bell->openCount, array_map(static fn (BellGroup $group): array => [$group->senderName, $group->newestKind], $bell->groups)];
    }

    /**
     * @return list<array{string, string, string}> each item's sender, kind and UTC creation time, of a digest from the day
     *                                             before the questions to after the hold
     */
    private function miasDigestItems(): array
    {
        $digestItems = self::getContainer()->get('test.notification_digest_items');
        \assert($digestItems instanceof DigestItems);
        $digest = $digestItems->digestOf(new UserId(self::VIEWERS['Mia'][0]), new \DateTimeImmutable('2027-03-02 00:00:00 UTC'), new \DateTimeImmutable('2027-03-04 09:30:00 UTC'));

        return array_map(static fn (DigestItem $item): array => [$item->actorName, $item->kind->value, $item->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s').'+00'], $digest->items);
    }
}
