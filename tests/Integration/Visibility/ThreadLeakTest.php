<?php

declare(strict_types=1);

namespace App\Tests\Integration\Visibility;

use App\Conversation\Api\MessageView;
use App\Conversation\Api\ReplyView;
use App\Conversation\Api\ThreadQueries;
use App\Identity\Api\CurrentUser;
use App\SharedKernel\Domain\TicketId;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibilities;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * The ticket thread's read, as the Thread component and ThreadController make it: the ticket page from
 * TicketQueries::pageOf with TicketVisibilities::of the viewer, then ThreadQueries::threadOf that page's ticket. A
 * comment on the cast's team-only ticket carrying LEAK_MARKER with a reply carrying it, and an answered question whose
 * answer carries it, reach exactly the viewers who may see the ticket.
 */
final class ThreadLeakTest extends VisibilityLeakCast
{
    private const string MESSAGE_ID = '0197a3c0-0000-7000-8000-0000000058a1';
    private const string QUESTION_ID = '0197a3c0-0000-7000-8000-0000000058a2';
    private const string ANSWER_ID = '0197a3c0-0000-7000-8000-0000000058a3';
    private const string QUESTION_BODY = self::LEAK_MARKER.' question';
    private const string REPLY_ID = '0197a3c0-0000-7000-8000-0000000058a4';
    private const string REPLY_BODY = self::LEAK_MARKER.' reply';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $teamOnlyTicketId = self::teamOnlyTicketId();
        self::asTenantMigrator(self::ACME_FOODS, static function (Connection $migrator) use ($teamOnlyTicketId): void {
            $migrator->executeStatement(
                "INSERT INTO cnv_message (organization_id, id, ticket_id, author_id, kind, body, created_at)
                 VALUES (?, ?, ?, ?, 'comment', ?, TIMESTAMPTZ '2027-03-15 10:00:00+00')",
                [self::ACME_FOODS, self::MESSAGE_ID, $teamOnlyTicketId, self::VIEWERS['Lena'][0], self::LEAK_MARKER],
            );
            $migrator->executeStatement(
                "INSERT INTO cnv_message (organization_id, id, ticket_id, author_id, kind, to_user_id, body, resolved_at, resolved_by, created_at)
                 VALUES (?, ?, ?, ?, 'question', ?, ?, TIMESTAMPTZ '2027-03-15 10:10:00+00', ?, TIMESTAMPTZ '2027-03-15 10:05:00+00')",
                [self::ACME_FOODS, self::QUESTION_ID, $teamOnlyTicketId, self::VIEWERS['Mia'][0], self::VIEWERS['Lena'][0], self::QUESTION_BODY, self::VIEWERS['Lena'][0]],
            );
            $migrator->executeStatement(
                "INSERT INTO cnv_reply (organization_id, id, message_id, author_id, body, is_answer, created_at)
                 VALUES (?, ?, ?, ?, ?, true, TIMESTAMPTZ '2027-03-15 10:10:00+00')",
                [self::ACME_FOODS, self::ANSWER_ID, self::QUESTION_ID, self::VIEWERS['Lena'][0], self::LEAK_MARKER],
            );
            $migrator->executeStatement(
                "INSERT INTO cnv_reply (organization_id, id, message_id, author_id, body, is_answer, created_at)
                 VALUES (?, ?, ?, ?, ?, false, TIMESTAMPTZ '2027-03-15 10:02:00+00')",
                [self::ACME_FOODS, self::REPLY_ID, self::MESSAGE_ID, self::VIEWERS['Mia'][0], self::REPLY_BODY],
            );
        });
        self::ensureKernelShutdown();
    }

    #[Test]
    #[DataProvider('viewers')]
    public function theTeamOnlyTicketsThreadShowsOnlyToAViewerWhoMaySeeTheTicket(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        $shownMessages = self::threadOfTheTeamOnlyTicketAs($viewer);

        self::assertSame(
            $seesTeamOnly ? [self::LEAK_MARKER, self::QUESTION_BODY] : null,
            null === $shownMessages ? null : self::bodiesOf($shownMessages),
            \sprintf('%s gets the wrong thread.', $viewer),
        );
    }

    /**
     * Replies ride on their message's MessageView, read by a separate query on cnv_reply: the comment's plain reply and
     * the question's answer show to the same viewers as the ticket, never to the rest.
     */
    #[Test]
    public function theRepliesOnTheTeamOnlyTicketShowOnlyToAViewerWhoMaySeeTheTicket(): void
    {
        $viewers = iterator_to_array(self::viewers());
        self::assertNotEmpty($viewers);
        $expectedReplies = [];
        $shownReplies = [];
        foreach ($viewers as [$viewer, $seesTeamOnly]) {
            $expectedReplies[$viewer] = $seesTeamOnly ? [[[self::REPLY_BODY], null], [[self::LEAK_MARKER], self::LEAK_MARKER]] : null;
            $shownMessages = self::threadOfTheTeamOnlyTicketAs($viewer);
            $shownReplies[$viewer] = null === $shownMessages
                ? null
                : array_map(static fn (MessageView $message): array => [
                    array_map(static fn (ReplyView $reply): ?string => $reply->body, $message->replies),
                    array_values(array_filter($message->replies, static fn (ReplyView $reply): bool => $reply->isAnswer && !$reply->isRemoved))[0]->body ?? null,
                ], $shownMessages);
        }

        self::assertSame($expectedReplies, $shownReplies);
    }

    /**
     * Even read straight by the ticket's id, which no page does without the visibility check, row-level security keeps
     * Acme Foods' thread from another organization's tenant.
     */
    #[Test]
    public function anotherOrganizationsTenantReadsNoMessageOfTheTicketByItsId(): void
    {
        $teamOnlyTicketId = self::teamOnlyTicketId();

        $bodiesOfAcmeFoods = self::asViewer('Lena', static fn (): array => self::bodiesOf(self::threadQueries()->threadOf(new TicketId($teamOnlyTicketId))->messages));
        $bodiesOfBetaBakery = self::asViewer('Ben', static fn (): array => self::bodiesOf(self::threadQueries()->threadOf(new TicketId($teamOnlyTicketId))->messages));

        self::assertSame([[self::LEAK_MARKER, self::QUESTION_BODY], []], [$bodiesOfAcmeFoods, $bodiesOfBetaBakery]);
    }

    /**
     * @return ?list<MessageView> null when the viewer may not see the ticket's page
     */
    private static function threadOfTheTeamOnlyTicketAs(string $viewer): ?array
    {
        $teamOnlyKey = self::keyOfTeamOnlyTicket();

        return self::asViewer($viewer, static function (TokenInterface $token) use ($teamOnlyKey): ?array {
            $userId = self::getContainer()->get(CurrentUser::class)->idOf($token->getUser());
            self::assertNotNull($userId);
            $ticketQueries = self::getContainer()->get('test.ticketing_ticket_queries');
            \assert($ticketQueries instanceof TicketQueries);
            $ticketVisibilities = self::getContainer()->get('test.ticketing_ticket_visibilities');
            \assert($ticketVisibilities instanceof TicketVisibilities);
            $organizationId = self::tenantContext()->require();

            $page = $ticketQueries->pageOf($teamOnlyKey, $ticketVisibilities->of($organizationId, $userId));

            return null === $page ? null : self::threadQueries()->threadOf($page->id)->messages;
        });
    }

    private static function threadQueries(): ThreadQueries
    {
        return self::getContainer()->get(ThreadQueries::class);
    }

    /**
     * @param list<MessageView> $messages
     *
     * @return list<string|null>
     */
    private static function bodiesOf(array $messages): array
    {
        return array_map(static fn (MessageView $message): ?string => $message->body, $messages);
    }

    /**
     * Read under Acme Foods as tenant, as row-level security binds the migrator too.
     */
    private static function keyOfTeamOnlyTicket(): string
    {
        $teamOnlyTicketId = self::teamOnlyTicketId();
        $key = null;
        self::asTenantMigrator(self::ACME_FOODS, static function (Connection $migrator) use ($teamOnlyTicketId, &$key): void {
            $key = $migrator->fetchOne('SELECT key FROM tkt_ticket WHERE id = ?', [$teamOnlyTicketId]);
        });
        \assert(\is_string($key));

        return $key;
    }
}
