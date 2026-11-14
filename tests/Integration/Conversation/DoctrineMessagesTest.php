<?php

declare(strict_types=1);

namespace App\Tests\Integration\Conversation;

use App\Conversation\Domain\Message;
use App\Conversation\Domain\MessageId;
use App\Conversation\Infrastructure\Doctrine\DoctrineMessages;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\TransactionRequiredException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DoctrineMessages on the real tables: save() flushes only a message read in the same transaction, as a new one would
 * flush nothing and look saved; lockedOf() locks the row whether or not the transaction loaded the message before.
 */
final class DoctrineMessagesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000d101';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000000d102';
    private const string TICKET = '0197a3c0-0000-7000-8000-00000000d103';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000d111';
    private const string LENAS_COMMENT = '0197a3c0-0000-7000-8000-00000000d121';
    private const string NEVER_ADDED_COMMENT = '0197a3c0-0000-7000-8000-00000000d122';
    private const string ORIGINAL_BODY = 'The button moves on small screens.';

    protected function setUp(): void
    {
        $this->threadRows()->removeAllOf(self::ACME_FOODS);
        $this->ticketRows()->addOf(self::ACME_FOODS, self::TICKET, self::SHOP_PROJECT, self::LENA, 1);
        $this->threadRows()->addCommentOf(self::ACME_FOODS, self::TICKET, self::LENAS_COMMENT, self::LENA, self::ORIGINAL_BODY, '2027-03-15 10:00:00+00');
    }

    protected function tearDown(): void
    {
        $this->threadRows()->removeAllOf(self::ACME_FOODS);
        parent::tearDown();
    }

    #[Test]
    public function savingAMessageNeverReadIsRefusedAndWritesNoRow(): void
    {
        $refusal = $this->transactions()->committedAs(self::ACME_FOODS, function (): ?\Throwable {
            $comment = Message::comment(
                new MessageId(self::NEVER_ADDED_COMMENT),
                new OrganizationId(self::ACME_FOODS),
                new TicketId(self::TICKET),
                new UserId(self::LENA),
                'Saved without being added.',
                new \DateTimeImmutable('2027-03-15 10:05:00+00:00'),
            );
            try {
                new DoctrineMessages($this->entityManager())->save($comment);
            } catch (\LogicException $refusal) {
                return $refusal;
            }

            return null;
        });

        self::assertNotNull($refusal, 'The message was saved.');
        self::assertSame(
            [\LogicException::class, 'Only a message read in this transaction can be saved; a new one is added.'],
            [$refusal::class, $refusal->getMessage()],
        );
        self::assertSame([[self::LENAS_COMMENT, self::ORIGINAL_BODY]], $this->messageRows());
    }

    #[Test]
    public function savingAMessageReadInTheTransactionWritesItsChange(): void
    {
        $this->transactions()->committedAs(self::ACME_FOODS, function (): void {
            $messages = new DoctrineMessages($this->entityManager());
            $comment = $messages->lockedOf(new OrganizationId(self::ACME_FOODS), new MessageId(self::LENAS_COMMENT));
            self::assertNotNull($comment);
            $comment->edit(new UserId(self::LENA), 'The button hides on small screens.', new \DateTimeImmutable('2027-03-15 10:05:00+00:00'));
            $messages->save($comment);
        });

        self::assertSame([[self::LENAS_COMMENT, 'The button hides on small screens.']], $this->messageRows());
    }

    #[Test]
    public function aMessageTheTransactionLoadedBeforeIsLockedAsTheSameObjectAndKeepsItsUnsavedChange(): void
    {
        [$isSameObject, $lockedBody, $refusal] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $loaded = $this->entityManager()->find(Message::class, ['organizationId' => self::ACME_FOODS, 'id' => self::LENAS_COMMENT]);
            self::assertNotNull($loaded);
            $loaded->edit(new UserId(self::LENA), 'The button hides on small screens.', new \DateTimeImmutable('2027-03-15 10:05:00+00:00'));
            $locked = new DoctrineMessages($this->entityManager())->lockedOf(new OrganizationId(self::ACME_FOODS), new MessageId(self::LENAS_COMMENT));

            return [$loaded === $locked, $locked?->body(), $this->refusalOfLockingTheRowElsewhere()];
        });

        self::assertTrue($isSameObject);
        self::assertSame('The button hides on small screens.', $lockedBody);
        self::assertInstanceOf(DriverException::class, $refusal);
        self::assertSame('55P03', $refusal->getSQLState());
    }

    #[Test]
    public function aMessageReadForTheFirstTimeIsLocked(): void
    {
        [$lockedId, $refusal] = $this->transactions()->rolledBackAs(self::ACME_FOODS, function (): array {
            $locked = new DoctrineMessages($this->entityManager())->lockedOf(new OrganizationId(self::ACME_FOODS), new MessageId(self::LENAS_COMMENT));

            return [$locked?->id()->toString(), $this->refusalOfLockingTheRowElsewhere()];
        });

        self::assertSame(self::LENAS_COMMENT, $lockedId);
        self::assertInstanceOf(DriverException::class, $refusal);
        self::assertSame('55P03', $refusal->getSQLState());
    }

    #[Test]
    public function lockingOutsideATransactionIsRefusedBeforeAnyQuery(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));
        try {
            new DoctrineMessages($this->entityManager())->lockedOf(new OrganizationId(self::ACME_FOODS), new MessageId(self::LENAS_COMMENT));
            self::fail('The message was locked outside a transaction.');
        } catch (TransactionRequiredException $refusal) {
            self::assertSame('An open transaction is required for this operation.', $refusal->getMessage());
        } finally {
            $this->tenantContext()->clear();
        }
    }

    /**
     * A second connection in its own transaction, under the same tenant, stands in for a concurrent request; NOWAIT
     * raises 55P03 (lock_not_available) on a row another transaction holds.
     */
    private function refusalOfLockingTheRowElsewhere(): ?\Throwable
    {
        try {
            $this->migrator()->transactional(static fn (Connection $concurrent): array => $concurrent->fetchFirstColumn(
                'SELECT id FROM cnv_message WHERE organization_id = ? AND id = ? FOR UPDATE NOWAIT',
                [self::ACME_FOODS, self::LENAS_COMMENT],
            ));
        } catch (\Throwable $refusal) {
            return $refusal;
        }

        return null;
    }

    private function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    /**
     * @return list<list<mixed>> id and body of Acme Foods' messages, by id
     */
    private function messageRows(): array
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchAllNumeric(
            'SELECT id::text, body FROM cnv_message WHERE organization_id = ? ORDER BY id',
            [self::ACME_FOODS],
        ));
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
