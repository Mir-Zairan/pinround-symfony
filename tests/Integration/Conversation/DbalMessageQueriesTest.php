<?php

declare(strict_types=1);

namespace App\Tests\Integration\Conversation;

use App\Conversation\Infrastructure\Doctrine\DbalMessageQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantNotSet;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalMessageQueries on the real cnv_message as pinround_app. Omar wrote every message on SHOP-1, one for each state a
 * message can be in; Beta Bakery holds a present comment with the removed comment's id, so only the organization filter
 * and row-level security keep it out.
 */
final class DbalMessageQueriesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000ec001';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000ec002';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000ec003';
    private const string BETA_PROJECT = '0197a3c0-0000-7000-8000-0000000ec004';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000ec005';
    private const string BETA_TICKET = '0197a3c0-0000-7000-8000-0000000ec006';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000ec011';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000ec012';

    private const string COMMENT = '0197a3c0-0000-7000-8000-0000000ec101';
    private const string RESOLVED_COMMENT = '0197a3c0-0000-7000-8000-0000000ec102';
    private const string REMOVED_COMMENT = '0197a3c0-0000-7000-8000-0000000ec103';
    private const string OPEN_QUESTION = '0197a3c0-0000-7000-8000-0000000ec104';
    private const string ANSWERED_QUESTION = '0197a3c0-0000-7000-8000-0000000ec105';
    private const string WITHDRAWN_QUESTION = '0197a3c0-0000-7000-8000-0000000ec106';
    private const string NO_MESSAGE = '0197a3c0-0000-7000-8000-0000000ec107';

    protected function setUp(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::TICKET, self::SHOP_PROJECT, self::LENA);
        $this->ticketRows()->addOf(self::BETA_BAKERY, self::BETA_TICKET, self::BETA_PROJECT, self::LENA);

        $this->addMessage(self::ACME_FOODS, self::TICKET, self::COMMENT, 'comment');
        $this->addMessage(self::ACME_FOODS, self::TICKET, self::RESOLVED_COMMENT, 'comment', resolvedAt: '2027-03-15 10:05:00+00');
        $this->addMessage(self::ACME_FOODS, self::TICKET, self::REMOVED_COMMENT, 'comment', removedAt: '2027-03-15 10:06:00+00');
        $this->addMessage(self::ACME_FOODS, self::TICKET, self::OPEN_QUESTION, 'question');
        $this->addMessage(self::ACME_FOODS, self::TICKET, self::ANSWERED_QUESTION, 'question', resolvedAt: '2027-03-15 10:05:00+00');
        $this->addMessage(self::ACME_FOODS, self::TICKET, self::WITHDRAWN_QUESTION, 'question', removedAt: '2027-03-15 10:07:00+00');
        $this->addMessage(self::BETA_BAKERY, self::BETA_TICKET, self::REMOVED_COMMENT, 'comment');
    }

    protected function tearDown(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        self::getContainer()->get('doctrine')->resetManager();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function messages(): iterable
    {
        yield 'a comment' => [self::COMMENT, true];
        yield 'a resolved comment' => [self::RESOLVED_COMMENT, true];
        yield 'a removed comment' => [self::REMOVED_COMMENT, false];
        yield 'an open question' => [self::OPEN_QUESTION, true];
        yield 'an answered question' => [self::ANSWERED_QUESTION, true];
        yield 'a withdrawn question' => [self::WITHDRAWN_QUESTION, false];
        yield 'an id that names no message' => [self::NO_MESSAGE, false];
        yield 'a malformed id' => ['not-a-uuid', false];
    }

    #[Test]
    #[DataProvider('messages')]
    public function onlyAMessageOfTheOrganizationThatIsNotRemovedIsPresent(string $messageId, bool $isPresent): void
    {
        self::assertSame($isPresent, $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): bool => $this->messageQueries()->isPresent($messageId)));
    }

    #[Test]
    public function anotherOrganizationReadsOnlyItsOwnMessageOfTheSameId(): void
    {
        self::assertTrue($this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): bool => $this->messageQueries()->isPresent(self::REMOVED_COMMENT)));
        self::assertFalse($this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): bool => $this->messageQueries()->isPresent(self::COMMENT)));
    }

    #[Test]
    public function readingWithNoOpenTransactionIsRefused(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->expectExceptionObject(new \LogicException('A message is read in a transaction under the organization as tenant; none is open.'));
            $this->messageQueries()->isPresent(self::COMMENT);
        } finally {
            $this->tenantContext()->clear();
        }
    }

    #[Test]
    public function readingWithNoTenantIsRefusedEvenForAMalformedId(): void
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $connection->beginTransaction();
        try {
            $this->expectException(TenantNotSet::class);
            $this->messageQueries()->isPresent('not-a-uuid');
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * By Omar; a question asks Lena, and $resolvedAt is when Lena answered it or resolved the comment.
     */
    private function addMessage(string $organizationId, string $ticketId, string $messageId, string $kind, ?string $resolvedAt = null, ?string $removedAt = null): void
    {
        $this->tenantContext()->set(new OrganizationId($organizationId));
        try {
            $this->migrator()->transactional(static fn (Connection $migrator): int|string => $migrator->executeStatement(
                'INSERT INTO cnv_message (organization_id, id, ticket_id, author_id, kind, to_user_id, body, removed_at, resolved_at, resolved_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$organizationId, $messageId, $ticketId, self::OMAR, $kind, 'question' === $kind ? self::LENA : null, 'Which build shows it?', $removedAt, $resolvedAt, null === $resolvedAt ? null : self::LENA, '2027-03-15 10:00:00+00'],
            ));
        } finally {
            $this->tenantContext()->clear();
        }
    }

    private function messageQueries(): DbalMessageQueries
    {
        return new DbalMessageQueries(self::getContainer()->get('doctrine.dbal.default_connection'), $this->tenantContext());
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
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), $this->tenantContext());
    }

    private function ticketRows(): TicketRows
    {
        return new TicketRows($this->migrator(), $this->tenantContext());
    }
}
