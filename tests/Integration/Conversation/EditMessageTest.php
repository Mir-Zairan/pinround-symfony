<?php

declare(strict_types=1);

namespace App\Tests\Integration\Conversation;

use App\Conversation\Api\MessageBodyRefused;
use App\Conversation\Api\MessageEditRefused;
use App\Conversation\Api\MessageNotFound;
use App\Conversation\Application\EditMessage\EditMessage;
use App\Conversation\Application\ThreadMessages;
use App\Conversation\Infrastructure\Doctrine\DoctrineMessages;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\SecondSession;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * EditMessage on the real tables, on comments posted at 10:00 on a fixed day. A refusal is caught inside a transaction
 * that then commits, so "unchanged" proves the check comes before the write, not that a rollback undid it.
 */
final class EditMessageTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000c401';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000c402';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000000c403';
    private const string TICKET = '0197a3c0-0000-7000-8000-00000000c404';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-00000000c405';
    private const string BETA_PROJECT = '0197a3c0-0000-7000-8000-00000000c406';
    private const string BETA_TICKET = '0197a3c0-0000-7000-8000-00000000c407';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000c411';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000c412';
    private const string LENAS_COMMENT = '0197a3c0-0000-7000-8000-00000000c421';
    private const string REMOVED_COMMENT = '0197a3c0-0000-7000-8000-00000000c422';
    private const string OPEN_QUESTION = '0197a3c0-0000-7000-8000-00000000c423';
    private const string BETA_COMMENT = '0197a3c0-0000-7000-8000-00000000c424';
    private const string UNKNOWN_MESSAGE = '0197a3c0-0000-7000-8000-00000000c4ff';
    private const string ORIGINAL_BODY = 'The button moves on small screens.';
    private const array UNCHANGED = [self::ORIGINAL_BODY, null, null];

    /** @var list<EntityManagerInterface> */
    private array $otherSessions = [];

    protected function setUp(): void
    {
        $this->threadRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::TICKET, self::SHOP_PROJECT, self::LENA, 1);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::OTHER_TICKET, self::SHOP_PROJECT, self::LENA, 2);
        $this->ticketRows()->addOf(self::BETA_BAKERY, self::BETA_TICKET, self::BETA_PROJECT, self::LENA);
        $this->threadRows()->addCommentOf(self::ACME_FOODS, self::TICKET, self::LENAS_COMMENT, self::LENA, self::ORIGINAL_BODY, '2027-03-15 10:00:00+00');
        $this->threadRows()->addCommentOf(self::ACME_FOODS, self::TICKET, self::REMOVED_COMMENT, self::LENA, self::ORIGINAL_BODY, '2027-03-15 10:00:00+00', '2027-03-15 10:02:00+00');
        $this->threadRows()->addOpenQuestionOf(self::ACME_FOODS, self::TICKET, self::OPEN_QUESTION, self::LENA, self::OMAR, self::ORIGINAL_BODY, '2027-03-15 10:00:00+00');
        $this->threadRows()->addCommentOf(self::BETA_BAKERY, self::BETA_TICKET, self::BETA_COMMENT, self::LENA, self::ORIGINAL_BODY, '2027-03-15 10:00:00+00');
    }

    protected function tearDown(): void
    {
        foreach ($this->otherSessions as $otherSession) {
            $otherSession->getConnection()->close();
        }
        $this->threadRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function theAuthorsEditAtTheLastSecondOfTheFifteenMinutesIsSavedTrimmed(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->editMessage()->edit(
            new OrganizationId(self::ACME_FOODS),
            new TicketId(self::TICKET),
            self::LENAS_COMMENT,
            new UserId(self::LENA),
            "  The button hides on small screens.\n",
            new \DateTimeImmutable('2027-03-15 10:15:00+00:00'),
        ));

        self::assertSame(
            ['The button hides on small screens.', '2027-03-15 10:15:00+00', null],
            $this->messageRowOf(self::ACME_FOODS, self::LENAS_COMMENT),
        );
    }

    #[Test]
    public function anEditOneSecondAfterTheFifteenMinutesIsRefusedWithItsReasonAndTheBodyStays(): void
    {
        $refusal = $this->refusalOfEdit(self::LENAS_COMMENT, self::LENA, '2027-03-15 10:15:01+00:00');

        self::assertSame([MessageEditRefused::class, 'You can edit a message for 15 minutes after you post it.'], [$refusal::class, $refusal->getMessage()]);
        self::assertSame(self::UNCHANGED, $this->messageRowOf(self::ACME_FOODS, self::LENAS_COMMENT));
    }

    #[Test]
    public function someoneElsesEditIsRefusedWithItsReasonAndTheBodyStays(): void
    {
        $refusal = $this->refusalOfEdit(self::LENAS_COMMENT, self::OMAR, '2027-03-15 10:05:00+00:00');

        self::assertSame([MessageEditRefused::class, 'Only the person who wrote a message can edit it.'], [$refusal::class, $refusal->getMessage()]);
        self::assertSame(self::UNCHANGED, $this->messageRowOf(self::ACME_FOODS, self::LENAS_COMMENT));
    }

    #[Test]
    public function aRemovedCommentIsNotEdited(): void
    {
        $refusal = $this->refusalOfEdit(self::REMOVED_COMMENT, self::LENA, '2027-03-15 10:05:00+00:00');

        self::assertSame([MessageEditRefused::class, 'This message was removed, so it cannot be edited.'], [$refusal::class, $refusal->getMessage()]);
        self::assertSame([self::ORIGINAL_BODY, null, '2027-03-15 10:02:00+00'], $this->messageRowOf(self::ACME_FOODS, self::REMOVED_COMMENT));
    }

    #[Test]
    public function aQuestionIsNotEdited(): void
    {
        $refusal = $this->refusalOfEdit(self::OPEN_QUESTION, self::LENA, '2027-03-15 10:05:00+00:00');

        self::assertSame([MessageEditRefused::class, 'A question cannot be edited.'], [$refusal::class, $refusal->getMessage()]);
        self::assertSame(self::UNCHANGED, $this->messageRowOf(self::ACME_FOODS, self::OPEN_QUESTION));
    }

    #[Test]
    public function aBlankBodyIsRefusedWithItsReasonAndTheBodyStays(): void
    {
        $refusal = $this->refusalOfEdit(self::LENAS_COMMENT, self::LENA, '2027-03-15 10:05:00+00:00', " \t\n");

        self::assertSame([MessageBodyRefused::class, 'A message needs a body.'], [$refusal::class, $refusal->getMessage()]);
        self::assertSame(self::UNCHANGED, $this->messageRowOf(self::ACME_FOODS, self::LENAS_COMMENT));
    }

    #[Test]
    public function anUnknownMalformedOrOtherTicketsMessageIdIsNotFound(): void
    {
        $refusals = [];
        foreach ([[self::TICKET, self::UNKNOWN_MESSAGE], [self::TICKET, 'not-a-message-id'], [self::OTHER_TICKET, self::LENAS_COMMENT]] as [$ticketId, $messageId]) {
            $refusal = $this->refusalOfEdit($messageId, self::LENA, '2027-03-15 10:05:00+00:00', 'Changed.', $ticketId);
            $refusals[] = [$refusal::class, $refusal->getMessage()];
        }

        self::assertSame(array_fill(0, 3, [MessageNotFound::class, 'That message does not exist.']), $refusals);
        self::assertSame(self::UNCHANGED, $this->messageRowOf(self::ACME_FOODS, self::LENAS_COMMENT));
    }

    #[Test]
    public function anotherOrganizationsMessageIsNotFoundEvenByItsOwnAuthorAndStaysUnchanged(): void
    {
        $refusals = [];
        foreach ([[self::ACME_FOODS, self::TICKET], [self::BETA_BAKERY, self::BETA_TICKET]] as [$organizationId, $ticketId]) {
            $refusal = $this->transactions()->committedAs(self::ACME_FOODS, fn (): \Throwable => $this->refusalOf(fn () => $this->editMessage()->edit(
                new OrganizationId($organizationId),
                new TicketId($ticketId),
                self::BETA_COMMENT,
                new UserId(self::LENA),
                'Changed.',
                new \DateTimeImmutable('2027-03-15 10:05:00+00:00'),
            )));
            $refusals[] = [$refusal::class, $refusal->getMessage()];
        }

        self::assertSame(array_fill(0, 2, [MessageNotFound::class, 'That message does not exist.']), $refusals);
        self::assertSame(self::UNCHANGED, $this->messageRowOf(self::BETA_BAKERY, self::BETA_COMMENT));
    }

    #[Test]
    public function aSecondEditWaitsUntilTheFirstEndsAndThenStartsFromItsSavedBody(): void
    {
        $secondSession = SecondSession::besideTheApp($this->entityManager(), $this->tenantContext());
        $this->otherSessions[] = $secondSession->entityManager;
        $secondSessionMessages = new DoctrineMessages($secondSession->entityManager);
        $editInSecondSession = new EditMessage(new ThreadMessages($secondSessionMessages), $secondSessionMessages);
        $connection = $this->connection();

        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $connection->beginTransaction();
            $this->editMessage()->edit(new OrganizationId(self::ACME_FOODS), new TicketId(self::TICKET), self::LENAS_COMMENT, new UserId(self::LENA), 'First edit.', new \DateTimeImmutable('2027-03-15 10:05:00+00:00'));
            // Omar's edit is refused at once by the author check unless reading the message waits for the first edit.
            $sqlStateWhileTheFirstRuns = $secondSession->sqlStateOfFailedAttempt(static fn () => $editInSecondSession->edit(
                new OrganizationId(self::ACME_FOODS),
                new TicketId(self::TICKET),
                self::LENAS_COMMENT,
                new UserId(self::OMAR),
                'Second edit.',
                new \DateTimeImmutable('2027-03-15 10:06:00+00:00'),
            ));
            $connection->commit();

            $secondSession->entityManager->wrapInTransaction(static fn () => $editInSecondSession->edit(
                new OrganizationId(self::ACME_FOODS),
                new TicketId(self::TICKET),
                self::LENAS_COMMENT,
                new UserId(self::LENA),
                'Second edit.',
                new \DateTimeImmutable('2027-03-15 10:07:00+00:00'),
            ));
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $this->entityManager()->clear();
            $secondSession->entityManager->clear();
            $this->tenantContext()->clear();
        }

        self::assertSame(SecondSession::LOCK_NOT_AVAILABLE, $sqlStateWhileTheFirstRuns, 'The second edit read the message while the first held it.');
        self::assertSame(['Second edit.', '2027-03-15 10:07:00+00', null], $this->messageRowOf(self::ACME_FOODS, self::LENAS_COMMENT));
    }

    private function refusalOfEdit(string $messageId, string $editorId, string $editedAt, string $body = 'Changed.', string $ticketId = self::TICKET): \Throwable
    {
        return $this->transactions()->committedAs(self::ACME_FOODS, fn (): \Throwable => $this->refusalOf(fn () => $this->editMessage()->edit(
            new OrganizationId(self::ACME_FOODS),
            new TicketId($ticketId),
            $messageId,
            new UserId($editorId),
            $body,
            new \DateTimeImmutable($editedAt),
        )));
    }

    /**
     * @param \Closure(): mixed $edit
     */
    private function refusalOf(\Closure $edit): \Throwable
    {
        try {
            $edit();
        } catch (\Throwable $refusal) {
            return $refusal;
        }

        self::fail('The edit was saved.');
    }

    /**
     * @return list<mixed> body, edited_at and removed_at
     */
    private function messageRowOf(string $organizationId, string $messageId): array
    {
        $row = $this->transactions()->rolledBackAs($organizationId, fn (): array|false => $this->connection()->fetchNumeric(
            "SELECT body, to_char(edited_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS+00'), to_char(removed_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS+00')
             FROM cnv_message WHERE id = ?",
            [$messageId],
        ));
        self::assertIsArray($row, 'The message row is gone.');

        return $row;
    }

    private function editMessage(): EditMessage
    {
        $editMessage = self::getContainer()->get('test.conversation_edit_message');
        self::assertInstanceOf(EditMessage::class, $editMessage);

        return $editMessage;
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
    }

    private function threadRows(): ThreadRows
    {
        return new ThreadRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
    }
}
