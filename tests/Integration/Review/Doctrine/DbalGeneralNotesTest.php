<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Doctrine;

use App\Review\Api\GeneralNoteView;
use App\Review\Domain\GeneralNote;
use App\Review\Domain\GeneralNoteId;
use App\Review\Domain\RoundId;
use App\Review\Infrastructure\Doctrine\DbalAreas;
use App\Review\Infrastructure\Doctrine\DbalGeneralNotes;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DbalGeneralNotes on the real rvw_round_note as pinround_app, under row-level security. Acme Foods has a shared draft
 * and another round; Beta Bakery has a round with a note of its own. Every time is a fixed one in March 2027; nothing
 * reads the clock. Each write commits, so a read afterwards proves what was stored.
 */
final class DbalGeneralNotesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f8b01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000f8b02';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000f8b03';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000f8b04';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f8b05';
    private const string DRAFT = '0197a3c0-0000-7000-8000-0000000f8b11';
    private const string OTHER_ROUND = '0197a3c0-0000-7000-8000-0000000f8b12';
    private const string BETA_ROUND = '0197a3c0-0000-7000-8000-0000000f8b13';

    protected function setUp(): void
    {
        $this->roundRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        $this->roundRows()->addAllOf(self::ACME_FOODS, [self::round(self::ACME_FOODS, self::DRAFT, 1), self::round(self::ACME_FOODS, self::OTHER_ROUND, 2)]);
        $this->roundRows()->addAllOf(self::BETA_BAKERY, [
            self::round(self::BETA_BAKERY, self::BETA_ROUND, 1),
            ['rvw_round_note', ['organization_id' => self::BETA_BAKERY, 'id' => '0197a3c0-0000-7000-8000-0000000f8bf1', 'round_id' => self::BETA_ROUND, 'author_id' => self::LENA, 'verdict' => null, 'note' => 'Beta only', 'created_at' => '2027-03-16 12:00:00+00']],
        ]);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->roundRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    #[Test]
    public function notesAreAddedAndReadNewestFirstForTheirRoundOnly(): void
    {
        // Added out of order, two at the same time; an offset other than UTC, so a time written without it reads back an hour off.
        $this->add('0197a3c0-0000-7000-8000-0000000f8b21', self::DRAFT, self::MIA, 'question', "Line one\r\nPrix affiché en €", '2027-03-16 10:15:00+01:00');
        $this->add('0197a3c0-0000-7000-8000-0000000f8b23', self::DRAFT, self::LENA, null, 'Same time, newer id', '2027-03-16 11:00:00+00:00');
        $this->add('0197a3c0-0000-7000-8000-0000000f8b22', self::DRAFT, self::MIA, 'pass', 'Same time, older id', '2027-03-16 11:00:00+00:00');
        $this->add('0197a3c0-0000-7000-8000-0000000f8b24', self::OTHER_ROUND, self::MIA, 'pass', 'Another round', '2027-03-16 12:00:00+00:00');

        $notes = $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->notes()->notesOf(strtoupper(self::DRAFT)));

        self::assertNotSame([], $notes);
        self::assertSame(
            [
                ['0197a3c0-0000-7000-8000-0000000f8b23', self::LENA, null, 'Same time, newer id', '2027-03-16 11:00:00'],
                ['0197a3c0-0000-7000-8000-0000000f8b22', self::MIA, 'pass', 'Same time, older id', '2027-03-16 11:00:00'],
                ['0197a3c0-0000-7000-8000-0000000f8b21', self::MIA, 'question', "Line one\r\nPrix affiché en €", '2027-03-16 09:15:00'],
            ],
            array_map(static fn (GeneralNoteView $note): array => [$note->id, $note->authorId->toString(), $note->verdict, $note->note, $note->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')], $notes),
        );
        self::assertSame(3, $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): int => $this->notes()->countOn(new RoundId(self::DRAFT))));
    }

    #[Test]
    public function anotherOrganizationsNotesAreNeitherReadNorCountedAndAMalformedIdHasNone(): void
    {
        self::assertSame(
            [[], 0, []],
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => [
                $this->notes()->notesOf(self::BETA_ROUND),
                $this->notes()->countOn(new RoundId(self::BETA_ROUND)),
                $this->notes()->notesOf('round-1'),
            ]),
        );
        self::assertCount(1, $this->transactions()->rolledBackAs(self::BETA_BAKERY, fn (): array => $this->notes()->notesOf(self::BETA_ROUND)));
    }

    #[Test]
    public function aNoteOnAnotherOrganizationsRoundFailsTheForeignKeyAndNothingIsStored(): void
    {
        $note = GeneralNote::write(new GeneralNoteId('0197a3c0-0000-7000-8000-0000000f8b31'), new RoundId(self::BETA_ROUND), new UserId(self::MIA), null, 'Sneaky', new \DateTimeImmutable('2027-03-16 12:00:00+00:00'));

        try {
            $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->notes()->add($note));
            self::fail('The note was stored on another organization\'s round.');
        } catch (DriverException) {
        }
        self::getContainer()->get('doctrine')->resetManager();

        self::assertSame([], $this->roundRows()->runAs(self::ACME_FOODS, 'SELECT id FROM rvw_round_note WHERE organization_id = ?', [self::ACME_FOODS]));
        self::assertSame([['Beta only']], $this->roundRows()->runAs(self::BETA_BAKERY, 'SELECT note FROM rvw_round_note WHERE organization_id = ?', [self::BETA_BAKERY]));
    }

    #[Test]
    public function withoutATransactionNothingIsRead(): void
    {
        $this->expectException(\LogicException::class);
        $this->notes()->notesOf(self::DRAFT);
    }

    private function add(string $id, string $roundId, string $authorId, ?string $verdict, string $text, string $at): void
    {
        $note = GeneralNote::write(new GeneralNoteId($id), new RoundId($roundId), new UserId($authorId), $verdict, $text, new \DateTimeImmutable($at));
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->notes()->add($note));
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function round(string $organizationId, string $roundId, int $number): array
    {
        return ['rvw_round', [
            'organization_id' => $organizationId,
            'id' => $roundId,
            'ticket_id' => self::TICKET,
            'owner_id' => self::LENA,
            'kind' => 'draft',
            'number' => $number,
            'shared_at' => '2027-03-15 10:00:00+00',
            'state' => 'open',
            'version' => 1,
            'created_at' => '2027-03-15 10:00:00+00',
        ]];
    }

    private function notes(): DbalGeneralNotes
    {
        $connection = $this->connection();

        return new DbalGeneralNotes($connection, $this->tenantContext(), new DbalAreas($connection, $this->tenantContext()));
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

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function roundRows(): RoundRows
    {
        return new RoundRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
    }
}
