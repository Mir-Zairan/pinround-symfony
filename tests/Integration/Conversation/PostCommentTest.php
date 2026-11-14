<?php

declare(strict_types=1);

namespace App\Tests\Integration\Conversation;

use App\Conversation\Api\Event\CommentPosted;
use App\Conversation\Api\MessageBodyRefused;
use App\Conversation\Application\PostComment\PostComment;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Api\TicketNotFound;
use App\Ticketing\Api\TicketVisibilityFacts;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * PostComment on the real tables: who may post on which ticket, the row it writes in the ticket's organization, and its
 * CommentPosted outbox row. The refusals are caught inside a transaction that then commits, so "nothing written" proves
 * the check comes before the write, not that a rollback undid it.
 */
final class PostCommentTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000c301';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000c302';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000000c303';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000000c304';
    private const string BETA_PROJECT = '0197a3c0-0000-7000-8000-00000000c305';
    private const string TICKET_FOR_EVERYONE = '0197a3c0-0000-7000-8000-00000000c306';
    private const string TEAM_ONLY_TICKET = '0197a3c0-0000-7000-8000-00000000c307';
    private const string BETA_TICKET = '0197a3c0-0000-7000-8000-00000000c308';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000c311';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000c312';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000c313';
    private const string SARA = '0197a3c0-0000-7000-8000-00000000c314';
    private const string POSTED_AT = '2027-03-15 10:00:00+00:00';

    protected function setUp(): void
    {
        $this->threadRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->deleteOutboxRowsOfTheseTickets();
        $this->threadRows()->addMembershipOf(self::ACME_FOODS, self::LENA, '{}');
        $this->threadRows()->addMembershipOf(self::ACME_FOODS, self::OMAR, '{}');
        $this->threadRows()->addMembershipOf(self::ACME_FOODS, self::MIA, '{}');
        $this->threadRows()->addMembershipOf(self::ACME_FOODS, self::SARA, '{admin}');
        $this->threadRows()->addMembershipOf(self::BETA_BAKERY, self::LENA, '{}');
        $this->threadRows()->addTeamOf(self::ACME_FOODS, self::SHOP_TEAM, self::LENA, [self::OMAR]);
        $this->ticketRows()->addWithAudienceOf(self::ACME_FOODS, self::TICKET_FOR_EVERYONE, self::SHOP_PROJECT, 1, 'all', null, self::LENA);
        $this->ticketRows()->addWithAudienceOf(self::ACME_FOODS, self::TEAM_ONLY_TICKET, self::SHOP_PROJECT, 2, 'team', self::SHOP_TEAM, self::LENA);
        $this->ticketRows()->addWithAudienceOf(self::BETA_BAKERY, self::BETA_TICKET, self::BETA_PROJECT, 1, 'all', null, self::LENA);
    }

    protected function tearDown(): void
    {
        $this->threadRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->deleteOutboxRowsOfTheseTickets();
        parent::tearDown();
    }

    #[Test]
    public function aCommentIsSavedTrimmedInTheTicketsOrganizationWithOneCommentPostedInTheOutbox(): void
    {
        $messageId = $this->transactions()->committedAs(self::ACME_FOODS, fn (): string => $this->postComment()->post(
            new TicketId(self::TICKET_FOR_EVERYONE),
            $this->factsOfTicketForEveryone(),
            new UserId(self::OMAR),
            "  The button moves on small screens.\n",
            new \DateTimeImmutable(self::POSTED_AT),
        ));

        self::assertSame(
            [[self::ACME_FOODS, $messageId, self::TICKET_FOR_EVERYONE, self::OMAR, 'comment', 'The button moves on small screens.', null, null, '2027-03-15 10:00:00+00']],
            $this->messageRowsOf(self::ACME_FOODS, self::TICKET_FOR_EVERYONE),
        );
        $outboxBodies = $this->outboxBodiesNaming($messageId);
        self::assertCount(1, $outboxBodies);
        self::assertStringContainsString(str_replace('\\', '', CommentPosted::class), str_replace('\\', '', $outboxBodies[0]));
        self::assertStringContainsString(self::TICKET_FOR_EVERYONE, $outboxBodies[0]);
        self::assertStringContainsString(self::OMAR, $outboxBodies[0]);
    }

    #[Test]
    public function aMemberOutsideTheTeamGetsTicketNotFoundOnATeamOnlyTicketAndNothingIsWritten(): void
    {
        $refusal = $this->transactions()->committedAs(self::ACME_FOODS, fn (): \Throwable => $this->refusalOf(fn (): string => $this->postComment()->post(
            new TicketId(self::TEAM_ONLY_TICKET),
            $this->factsOfTeamOnlyTicket(),
            new UserId(self::MIA),
            'Which build shows it?',
            new \DateTimeImmutable(self::POSTED_AT),
        )));

        self::assertInstanceOf(TicketNotFound::class, $refusal);
        self::assertSame('That ticket does not exist.', $refusal->getMessage());
        self::assertSame([], $this->messageRowsOf(self::ACME_FOODS, self::TEAM_ONLY_TICKET));
        self::assertSame([], $this->outboxBodiesNaming(self::TEAM_ONLY_TICKET));
    }

    #[Test]
    public function aTeamMemberAndAnAdminWithNoTeamRoleCanPostOnATeamOnlyTicket(): void
    {
        foreach ([self::OMAR => '10:00', self::SARA => '10:05'] as $authorId => $time) {
            $this->transactions()->committedAs(self::ACME_FOODS, fn (): string => $this->postComment()->post(
                new TicketId(self::TEAM_ONLY_TICKET),
                $this->factsOfTeamOnlyTicket(),
                new UserId($authorId),
                'Seen on build 42.',
                new \DateTimeImmutable('2027-03-15 '.$time.':00+00:00'),
            ));
        }

        self::assertSame(
            [self::OMAR, self::SARA],
            array_column($this->messageRowsOf(self::ACME_FOODS, self::TEAM_ONLY_TICKET), 3),
        );
        self::assertCount(2, $this->outboxBodiesNaming(self::TEAM_ONLY_TICKET));
    }

    #[Test]
    public function anotherOrganizationsTicketGetsTicketNotFoundAndNothingIsWrittenInEither(): void
    {
        $refusal = $this->transactions()->committedAs(self::ACME_FOODS, fn (): \Throwable => $this->refusalOf(fn (): string => $this->postComment()->post(
            new TicketId(self::BETA_TICKET),
            new TicketVisibilityFacts(new OrganizationId(self::BETA_BAKERY), TicketVisibilityFacts::FOR_EVERYONE, null, new UserId(self::LENA)),
            new UserId(self::LENA),
            'Seen on build 42.',
            new \DateTimeImmutable(self::POSTED_AT),
        )));

        self::assertInstanceOf(TicketNotFound::class, $refusal);
        self::assertSame('That ticket does not exist.', $refusal->getMessage());
        self::assertSame([], $this->messageRowsOf(self::BETA_BAKERY, self::BETA_TICKET));
        self::assertSame([], $this->messageRowsOf(self::ACME_FOODS, self::BETA_TICKET));
        self::assertSame([], $this->outboxBodiesNaming(self::BETA_TICKET));
    }

    #[Test]
    public function aBlankBodyIsRefusedWithItsReasonAndNothingIsWritten(): void
    {
        $refusal = $this->transactions()->committedAs(self::ACME_FOODS, fn (): \Throwable => $this->refusalOf(fn (): string => $this->postComment()->post(
            new TicketId(self::TICKET_FOR_EVERYONE),
            $this->factsOfTicketForEveryone(),
            new UserId(self::OMAR),
            " \n\t ",
            new \DateTimeImmutable(self::POSTED_AT),
        )));

        self::assertInstanceOf(MessageBodyRefused::class, $refusal);
        self::assertSame('A message needs a body.', $refusal->getMessage());
        self::assertSame([], $this->messageRowsOf(self::ACME_FOODS, self::TICKET_FOR_EVERYONE));
        self::assertSame([], $this->outboxBodiesNaming(self::TICKET_FOR_EVERYONE));
    }

    #[Test]
    public function postingOutsideATransactionIsRefusedBeforeTheCommentIsSaved(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $refusal = $this->refusalOf(fn (): string => $this->postComment()->post(
                new TicketId(self::TICKET_FOR_EVERYONE),
                $this->factsOfTicketForEveryone(),
                new UserId(self::OMAR),
                'Seen on build 42.',
                new \DateTimeImmutable(self::POSTED_AT),
            ));
        } finally {
            $this->entityManager()->clear();
            $this->tenantContext()->clear();
        }

        self::assertInstanceOf(\LogicException::class, $refusal);
        self::assertSame([], $this->messageRowsOf(self::ACME_FOODS, self::TICKET_FOR_EVERYONE));
        self::assertSame([], $this->outboxBodiesNaming(self::TICKET_FOR_EVERYONE));
    }

    private function factsOfTicketForEveryone(): TicketVisibilityFacts
    {
        return new TicketVisibilityFacts(new OrganizationId(self::ACME_FOODS), TicketVisibilityFacts::FOR_EVERYONE, null, new UserId(self::LENA));
    }

    private function factsOfTeamOnlyTicket(): TicketVisibilityFacts
    {
        return new TicketVisibilityFacts(new OrganizationId(self::ACME_FOODS), TicketVisibilityFacts::TEAM_ONLY, new TeamId(self::SHOP_TEAM), new UserId(self::LENA));
    }

    /**
     * @param \Closure(): mixed $post
     */
    private function refusalOf(\Closure $post): \Throwable
    {
        try {
            $post();
        } catch (\Throwable $refusal) {
            return $refusal;
        }

        self::fail('The comment was posted.');
    }

    /**
     * @return list<list<mixed>> organization, id, ticket, author, kind, body, edited_at, removed_at and created_at of
     *                           each message of the ticket, oldest first
     */
    private function messageRowsOf(string $organizationId, string $ticketId): array
    {
        return $this->transactions()->rolledBackAs($organizationId, fn (): array => $this->connection()->fetchAllNumeric(
            "SELECT organization_id, id, ticket_id, author_id, kind, body, edited_at, removed_at, to_char(created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS+00')
             FROM cnv_message WHERE ticket_id = ? ORDER BY created_at, id",
            [$ticketId],
        ));
    }

    /**
     * Only the outbox rows naming this test's ids: other tests delete outbox rows at the same time.
     *
     * @return list<string>
     */
    private function outboxBodiesNaming(string $id): array
    {
        $bodies = [];
        foreach ($this->connection()->fetchFirstColumn("SELECT body FROM messenger_outbox WHERE queue_name = 'outbox' AND body LIKE ? ORDER BY id", ['%'.$id.'%']) as $body) {
            self::assertIsString($body);
            $bodies[] = $body;
        }

        return $bodies;
    }

    private function deleteOutboxRowsOfTheseTickets(): void
    {
        foreach ([self::TICKET_FOR_EVERYONE, self::TEAM_ONLY_TICKET, self::BETA_TICKET] as $ticketId) {
            $this->connection()->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox' AND body LIKE ?", ['%'.$ticketId.'%']);
        }
    }

    private function postComment(): PostComment
    {
        $postComment = self::getContainer()->get('test.conversation_post_comment');
        self::assertInstanceOf(PostComment::class, $postComment);

        return $postComment;
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
