<?php

declare(strict_types=1);

namespace App\Tests\Integration\Conversation;

use App\Conversation\Infrastructure\Doctrine\DbalOpenQuestionQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use App\Ticketing\Api\OpenQuestion;
use App\Ticketing\Api\OpenQuestionQueries;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalOpenQuestionQueries on the real cnv_message under row-level security: only a ticket's open question to the asked
 * person, of the tickets asked for, in the tenant's organization.
 */
final class DbalOpenQuestionQueriesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000e501';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000e502';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000000e503';
    private const string BETA_PROJECT = '0197a3c0-0000-7000-8000-00000000e504';
    private const string ASKED_TICKET = '0197a3c0-0000-7000-8000-00000000e511';
    private const string TO_OMAR_TICKET = '0197a3c0-0000-7000-8000-00000000e512';
    private const string REASKED_TICKET = '0197a3c0-0000-7000-8000-00000000e513';
    private const string REMOVED_TICKET = '0197a3c0-0000-7000-8000-00000000e514';
    private const string ANSWERED_TICKET = '0197a3c0-0000-7000-8000-00000000e515';
    private const string COMMENTED_TICKET = '0197a3c0-0000-7000-8000-00000000e516';
    private const string NOT_ASKED_FOR_TICKET = '0197a3c0-0000-7000-8000-00000000e517';
    private const string BETA_ONLY_TICKET = '0197a3c0-0000-7000-8000-00000000e518';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000e521';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000e522';
    private const string MIA = '0197a3c0-0000-7000-8000-00000000e523';
    private const string AT = '2027-03-15 10:00:00+00';

    protected function setUp(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $number = 1;
        foreach ([self::ASKED_TICKET, self::TO_OMAR_TICKET, self::REASKED_TICKET, self::REMOVED_TICKET, self::ANSWERED_TICKET, self::COMMENTED_TICKET, self::NOT_ASKED_FOR_TICKET] as $ticketId) {
            $this->ticketRows()->addOf(self::ACME_FOODS, $ticketId, self::SHOP_PROJECT, self::MIA, $number++);
        }
        $this->ticketRows()->addOf(self::BETA_BAKERY, self::ASKED_TICKET, self::BETA_PROJECT, self::MIA, 1);
        $this->ticketRows()->addOf(self::BETA_BAKERY, self::BETA_ONLY_TICKET, self::BETA_PROJECT, self::MIA, 2);

        $this->addMessage(self::ACME_FOODS, self::ASKED_TICKET, '0197a3c0-0000-7000-8000-00000000e531', self::LENA, 'question', self::MIA, "Which order?\r\nThe one from May?");
        $this->addMessage(self::ACME_FOODS, self::TO_OMAR_TICKET, '0197a3c0-0000-7000-8000-00000000e532', self::LENA, 'question', self::OMAR, 'Which build?');
        // Asked again at the same second after the first was answered: the open one is the answer, whatever the order.
        $this->addMessage(self::ACME_FOODS, self::REASKED_TICKET, '0197a3c0-0000-7000-8000-00000000e534', self::OMAR, 'question', self::MIA, 'Still on tablets?');
        $this->addMessage(self::ACME_FOODS, self::REASKED_TICKET, '0197a3c0-0000-7000-8000-00000000e533', self::LENA, 'question', self::MIA, 'Which tablet?', resolvedAt: self::AT);
        $this->addMessage(self::ACME_FOODS, self::REMOVED_TICKET, '0197a3c0-0000-7000-8000-00000000e535', self::LENA, 'question', self::MIA, 'Asked by mistake.', removedAt: self::AT);
        $this->addMessage(self::ACME_FOODS, self::ANSWERED_TICKET, '0197a3c0-0000-7000-8000-00000000e536', self::LENA, 'question', self::MIA, 'Which page?', resolvedAt: self::AT);
        $this->addMessage(self::ACME_FOODS, self::COMMENTED_TICKET, '0197a3c0-0000-7000-8000-00000000e537', self::LENA, 'comment', null, 'Seen on staging too.');
        $this->addMessage(self::ACME_FOODS, self::NOT_ASKED_FOR_TICKET, '0197a3c0-0000-7000-8000-00000000e538', self::LENA, 'question', self::MIA, 'Not on this page.');
        // The same ticket id under another organization: only row-level security and the organization filter keep it out.
        $this->addMessage(self::BETA_BAKERY, self::ASKED_TICKET, '0197a3c0-0000-7000-8000-00000000e539', self::OMAR, 'question', self::MIA, 'Which bread?');
        $this->addMessage(self::BETA_BAKERY, self::BETA_ONLY_TICKET, '0197a3c0-0000-7000-8000-00000000e540', self::OMAR, 'question', self::MIA, 'Which oven?');
    }

    protected function tearDown(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function onlyTheOpenQuestionsToTheAskedPersonOfTheTicketsAskedForAreRead(): void
    {
        $questions = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->queries()->openQuestionsTo(
            new UserId(self::MIA),
            self::ticketIds(self::ASKED_TICKET, self::TO_OMAR_TICKET, self::REASKED_TICKET, self::REMOVED_TICKET, self::ANSWERED_TICKET, self::COMMENTED_TICKET, self::BETA_ONLY_TICKET),
        ));

        ksort($questions);
        self::assertEquals(
            [
                self::ASKED_TICKET => new OpenQuestion(new UserId(self::LENA), "Which order?\r\nThe one from May?"),
                self::REASKED_TICKET => new OpenQuestion(new UserId(self::OMAR), 'Still on tablets?'),
            ],
            $questions,
        );
    }

    #[Test]
    public function anotherOrganizationReadsItsOwnQuestionsOnly(): void
    {
        $questions = $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): array => $this->queries()->openQuestionsTo(
            new UserId(self::MIA),
            self::ticketIds(self::ASKED_TICKET, self::REASKED_TICKET, self::BETA_ONLY_TICKET),
        ));

        ksort($questions);
        self::assertEquals(
            [
                self::ASKED_TICKET => new OpenQuestion(new UserId(self::OMAR), 'Which bread?'),
                self::BETA_ONLY_TICKET => new OpenQuestion(new UserId(self::OMAR), 'Which oven?'),
            ],
            $questions,
        );
    }

    #[Test]
    public function aQuestionToSomeoneElseIsNotTheAskedPersons(): void
    {
        $questions = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->queries()->openQuestionsTo(
            new UserId(self::OMAR),
            self::ticketIds(self::ASKED_TICKET, self::TO_OMAR_TICKET),
        ));

        self::assertEquals([self::TO_OMAR_TICKET => new OpenQuestion(new UserId(self::LENA), 'Which build?')], $questions);
    }

    #[Test]
    public function noTicketsReadNothing(): void
    {
        self::assertSame([], $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->queries()->openQuestionsTo(new UserId(self::MIA), [])));
    }

    #[Test]
    public function moreTicketsThanTheCapAreRefused(): void
    {
        $tooMany = array_map(static fn (int $number): TicketId => new TicketId(\sprintf('0197a3c0-0000-7000-8000-%012d', $number)), range(1, OpenQuestionQueries::MAX_TICKET_IDS + 1));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Open questions are read for at most 100 tickets at a time.');

        $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->queries()->openQuestionsTo(new UserId(self::MIA), $tooMany));
    }

    #[Test]
    public function theCapItselfIsRead(): void
    {
        $atTheCap = [
            ...self::ticketIds(self::ASKED_TICKET),
            ...array_map(static fn (int $number): TicketId => new TicketId(\sprintf('0197a3c0-0000-7000-8000-%012d', $number)), range(1, OpenQuestionQueries::MAX_TICKET_IDS - 1)),
        ];

        $questions = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->queries()->openQuestionsTo(new UserId(self::MIA), $atTheCap));

        self::assertSame([self::ASKED_TICKET], array_keys($questions));
    }

    #[Test]
    public function questionsAreReadOnlyInATransaction(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('none is open');

        $this->queries()->openQuestionsTo(new UserId(self::MIA), self::ticketIds(self::ASKED_TICKET));
    }

    /**
     * @return list<TicketId>
     */
    private static function ticketIds(string ...$ids): array
    {
        return array_values(array_map(static fn (string $id): TicketId => new TicketId($id), $ids));
    }

    private function addMessage(string $organizationId, string $ticketId, string $messageId, string $authorId, string $kind, ?string $toUserId, string $body, ?string $resolvedAt = null, ?string $removedAt = null): void
    {
        $this->tenantContext()->set(new OrganizationId($organizationId));
        try {
            $this->migrator()->transactional(static fn (Connection $migrator): int|string => $migrator->executeStatement(
                'INSERT INTO cnv_message (organization_id, id, ticket_id, author_id, kind, to_user_id, body, resolved_at, resolved_by, removed_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$organizationId, $messageId, $ticketId, $authorId, $kind, $toUserId, $body, $resolvedAt, null === $resolvedAt ? null : $toUserId, $removedAt, self::AT],
            ));
        } finally {
            $this->tenantContext()->clear();
        }
    }

    private function queries(): DbalOpenQuestionQueries
    {
        return new DbalOpenQuestionQueries($this->entityManager()->getConnection(), $this->tenantContext());
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
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
        return new TicketRows($this->migrator(), $this->tenantContext());
    }
}
