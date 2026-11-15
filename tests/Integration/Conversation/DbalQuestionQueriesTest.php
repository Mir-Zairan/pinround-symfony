<?php

declare(strict_types=1);

namespace App\Tests\Integration\Conversation;

use App\Conversation\Infrastructure\Doctrine\DbalQuestionQueries;
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
 * DbalQuestionQueries on the real cnv_message as pinround_app. Omar asked Lena on SHOP-1, which holds one open question
 * and every way a message stops being one; Beta Bakery holds an open question with the withdrawn one's id, so only the
 * organization filter and row-level security keep it out.
 */
final class DbalQuestionQueriesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000e9a01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000e9a02';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000e9a03';
    private const string BETA_PROJECT = '0197a3c0-0000-7000-8000-0000000e9a04';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000e9a05';
    private const string BETA_TICKET = '0197a3c0-0000-7000-8000-0000000e9a06';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000e9a11';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000e9a12';

    private const string OPEN_QUESTION = '0197a3c0-0000-7000-8000-0000000e9b01';
    private const string ANSWERED_QUESTION = '0197a3c0-0000-7000-8000-0000000e9b02';
    private const string WITHDRAWN_QUESTION = '0197a3c0-0000-7000-8000-0000000e9b03';
    private const string ANSWERED_THEN_REMOVED_QUESTION = '0197a3c0-0000-7000-8000-0000000e9b04';
    private const string OPEN_COMMENT = '0197a3c0-0000-7000-8000-0000000e9b05';
    private const string REMOVED_COMMENT = '0197a3c0-0000-7000-8000-0000000e9b06';
    private const string NO_MESSAGE = '0197a3c0-0000-7000-8000-0000000e9b08';

    protected function setUp(): void
    {
        $this->ticketRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::TICKET, self::SHOP_PROJECT, self::LENA);
        $this->ticketRows()->addOf(self::BETA_BAKERY, self::BETA_TICKET, self::BETA_PROJECT, self::LENA);

        $this->addMessage(self::ACME_FOODS, self::TICKET, self::OPEN_QUESTION, 'question');
        $this->addMessage(self::ACME_FOODS, self::TICKET, self::ANSWERED_QUESTION, 'question', resolvedAt: '2027-03-15 10:05:00+00');
        $this->addMessage(self::ACME_FOODS, self::TICKET, self::WITHDRAWN_QUESTION, 'question', removedAt: '2027-03-15 10:06:00+00');
        $this->addMessage(self::ACME_FOODS, self::TICKET, self::ANSWERED_THEN_REMOVED_QUESTION, 'question', resolvedAt: '2027-03-15 10:05:00+00', removedAt: '2027-03-15 10:07:00+00');
        $this->addMessage(self::ACME_FOODS, self::TICKET, self::OPEN_COMMENT, 'comment');
        $this->addMessage(self::ACME_FOODS, self::TICKET, self::REMOVED_COMMENT, 'comment', removedAt: '2027-03-15 10:08:00+00');
        $this->addMessage(self::BETA_BAKERY, self::BETA_TICKET, self::WITHDRAWN_QUESTION, 'question');
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
        yield 'an open question' => [self::OPEN_QUESTION, true];
        yield 'an answered question' => [self::ANSWERED_QUESTION, false];
        yield 'a withdrawn question' => [self::WITHDRAWN_QUESTION, false];
        yield 'an answered question removed later' => [self::ANSWERED_THEN_REMOVED_QUESTION, false];
        yield 'an open comment' => [self::OPEN_COMMENT, false];
        yield 'a removed comment' => [self::REMOVED_COMMENT, false];
        yield 'an id that names no message' => [self::NO_MESSAGE, false];
        yield 'a malformed id' => ['not-a-uuid', false];
    }

    #[Test]
    #[DataProvider('messages')]
    public function onlyAQuestionOfTheOrganizationThatIsNeitherAnsweredNorWithdrawnIsOpen(string $messageId, bool $isOpen): void
    {
        self::assertSame($isOpen, $this->isOpenInAcmeFoods($messageId));
    }

    #[Test]
    public function anotherOrganizationReadsOnlyItsOwnQuestionOfTheSameId(): void
    {
        self::assertTrue($this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): bool => $this->questionQueries()->isOpen(self::WITHDRAWN_QUESTION)));
        self::assertFalse($this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): bool => $this->questionQueries()->isOpen(self::OPEN_QUESTION)));
    }

    #[Test]
    public function readingWithNoOpenTransactionIsRefused(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            $this->expectExceptionObject(new \LogicException('A question is read in a transaction under the organization as tenant; none is open.'));
            $this->questionQueries()->isOpen(self::OPEN_QUESTION);
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
            $this->questionQueries()->isOpen('not-a-uuid');
        } finally {
            $connection->rollBack();
        }
    }

    private function isOpenInAcmeFoods(string $messageId): bool
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): bool => $this->questionQueries()->isOpen($messageId));
    }

    /**
     * By Omar; a question asks Lena, and $resolvedAt is when Lena answered it.
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

    private function questionQueries(): DbalQuestionQueries
    {
        return new DbalQuestionQueries(self::getContainer()->get('doctrine.dbal.default_connection'), $this->tenantContext());
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
