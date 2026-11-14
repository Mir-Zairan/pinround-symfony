<?php

declare(strict_types=1);

namespace App\Tests\Integration\Visibility;

use App\Conversation\Api\Event\QuestionAsked;
use App\Notification\Application\Notify\NotifyRecipients;
use App\SharedKernel\Infrastructure\Messenger\MessageBeingHandled;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * Notification delivery (NotifyRecipients writing ntf_notification), as worker-notify handles an event: each viewer is
 * asked a question about each cast ticket, and gets a notification exactly when they may see that ticket. Raj asks,
 * and Noor asks Raj. Each case runs in a rolled-back transaction under Acme Foods, the event's organization.
 */
final class NotificationDeliveryLeakTest extends VisibilityLeakCast
{
    private const string MESSAGE = '0197a3c0-0000-7000-8000-0000000057f1';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $teamOnlyTicketId = self::teamOnlyTicketId();
        // The cast's no-team ticket is a raw row whose step Acme Foods lacks; the handler reads its step as a real ticket's.
        self::asTenantMigrator(self::ACME_FOODS, static function (Connection $migrator) use ($teamOnlyTicketId): void {
            $updated = $migrator->executeStatement(
                'UPDATE tkt_ticket SET step_id = (SELECT step_id FROM tkt_ticket WHERE id = ?) WHERE id = ?',
                [$teamOnlyTicketId, self::NO_TEAM_TICKET],
            );
            if (1 !== $updated) {
                throw new \LogicException('The cast has no no-team ticket to move into an Acme Foods step.');
            }
        });
        self::ensureKernelShutdown();
    }

    #[Test]
    #[DataProvider('viewers')]
    public function aViewerIsNotifiedAboutATicketExactlyWhenTheyMaySeeIt(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        self::assertSame(
            ['team-only ticket' => $seesTeamOnly ? 1 : 0, 'no-team ticket' => $seesNoTeamTicket ? 1 : 0],
            [
                'team-only ticket' => $this->notificationsAfterAQuestionTo($viewer, self::teamOnlyTicketId()),
                'no-team ticket' => $this->notificationsAfterAQuestionTo($viewer, self::NO_TEAM_TICKET),
            ],
        );
    }

    private function notificationsAfterAQuestionTo(string $viewer, string $ticketId): int
    {
        $actor = 'Raj' === $viewer ? 'Noor' : 'Raj';
        $question = new QuestionAsked(self::ACME_FOODS, $ticketId, self::MESSAGE, self::VIEWERS[$viewer][0], self::VIEWERS[$actor][0], new \DateTimeImmutable('2027-03-15 10:00:00 UTC'));

        return self::transactions()->rolledBackAs(self::ACME_FOODS, static function () use ($question, $viewer, $ticketId): int {
            $messageBeingHandled = self::getContainer()->get(MessageBeingHandled::class);
            $messageBeingHandled->set(Uuid::v7()->toRfc4122());
            try {
                self::getContainer()->get(NotifyRecipients::class)($question);
            } finally {
                $messageBeingHandled->reset();
            }

            $count = self::connection()->fetchOne(
                'SELECT count(*) FROM ntf_notification WHERE organization_id = ? AND user_id = ? AND ticket_id = ?',
                [self::ACME_FOODS, self::VIEWERS[$viewer][0], $ticketId],
            );
            self::assertIsInt($count);

            return $count;
        });
    }
}
