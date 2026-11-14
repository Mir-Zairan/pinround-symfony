<?php

declare(strict_types=1);

namespace App\Tests\Integration\Visibility;

use App\Conversation\Api\MessageView;
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
 * comment on the cast's team-only ticket, carrying LEAK_MARKER, reaches exactly the viewers who may see the ticket.
 */
final class ThreadLeakTest extends VisibilityLeakCast
{
    private const string MESSAGE_ID = '0197a3c0-0000-7000-8000-0000000058a1';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $teamOnlyTicketId = self::teamOnlyTicketId();
        self::asTenantMigrator(self::ACME_FOODS, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO cnv_message (organization_id, id, ticket_id, author_id, kind, body, created_at)
             VALUES (?, ?, ?, ?, 'comment', ?, TIMESTAMPTZ '2027-03-15 10:00:00+00')",
            [self::ACME_FOODS, self::MESSAGE_ID, $teamOnlyTicketId, self::VIEWERS['Lena'][0], self::LEAK_MARKER],
        ));
        self::ensureKernelShutdown();
    }

    #[Test]
    #[DataProvider('viewers')]
    public function theTeamOnlyTicketsThreadShowsOnlyToAViewerWhoMaySeeTheTicket(string $viewer, bool $seesTeamOnly, bool $seesNoTeamTicket): void
    {
        $teamOnlyKey = self::keyOfTeamOnlyTicket();

        $shownBodies = self::asViewer($viewer, static function (TokenInterface $token) use ($teamOnlyKey): ?array {
            $userId = self::getContainer()->get(CurrentUser::class)->idOf($token->getUser());
            self::assertNotNull($userId);
            $ticketQueries = self::getContainer()->get('test.ticketing_ticket_queries');
            \assert($ticketQueries instanceof TicketQueries);
            $ticketVisibilities = self::getContainer()->get('test.ticketing_ticket_visibilities');
            \assert($ticketVisibilities instanceof TicketVisibilities);
            $organizationId = self::tenantContext()->require();

            $page = $ticketQueries->pageOf($teamOnlyKey, $ticketVisibilities->of($organizationId, $userId));

            return null === $page ? null : self::bodiesOf(self::threadQueries()->threadOf($page->id)->messages);
        });

        self::assertSame($seesTeamOnly ? [self::LEAK_MARKER] : null, $shownBodies, \sprintf('%s gets the wrong thread.', $viewer));
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

        self::assertSame([[self::LEAK_MARKER], []], [$bodiesOfAcmeFoods, $bodiesOfBetaBakery]);
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
