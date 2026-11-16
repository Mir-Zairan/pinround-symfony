<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Doctrine;

use App\SharedKernel\Application\TenantContext;
use App\Tests\Integration\Conversation\RolledBackInserts;
use App\Tests\Integration\Organization\TenantTransactions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What rvw_area keeps on its own (Version20261116130000), whatever writes it: the CHECKs and keys refuse a row Area
 * could not make, written as the app past the domain, and the rows next to it are still accepted; its indexes start
 * with the organization, its foreign keys stay inside Review, and row-level security hides one organization's areas
 * from another. Grants and the schema filter are pinned in DatabaseRolesTest.
 */
final class AreaTablesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f7a01';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-0000000f7a02';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000f7a03';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000f7a04';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000f7a05';
    private const string REPORT = '0197a3c0-0000-7000-8000-0000000f7a11';
    private const string REVIEW = '0197a3c0-0000-7000-8000-0000000f7a12';
    private const string MISSING_ROUND = '0197a3c0-0000-7000-8000-0000000f7a13';
    private const string BEFORE = '0197a3c0-0000-7000-8000-0000000f7a21';
    private const string AFTER = '0197a3c0-0000-7000-8000-0000000f7a22';
    private const string SECOND_AFTER = '0197a3c0-0000-7000-8000-0000000f7a23';
    private const string MISSING_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f7a24';
    private const string AREA = '0197a3c0-0000-7000-8000-0000000f7a31';
    private const string SECOND_AREA = '0197a3c0-0000-7000-8000-0000000f7a32';
    private const string MEDIA = '0197a3c0-0000-7000-8000-0000000f7a41';
    private const string DECIDED_AT = '2027-03-16 09:00:00+00';
    private const string CHECK_VIOLATION = '23514';
    private const string UNIQUE_VIOLATION = '23505';
    private const string FOREIGN_KEY_VIOLATION = '23503';

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::getContainer()->get(TenantContext::class)->reset();
        $this->roundRows()->removeAllOf(self::ACME_FOODS, self::BETA_BAKERY);
        parent::tearDown();
    }

    /**
     * Every CHECK, foreign key and unique index of rvw_area but the primary key has a case below that breaks it, and
     * every case breaks one the table has.
     */
    #[Test]
    public function theCasesCoverEveryCheckForeignKeyAndUniqueIndexOfTheAreaTable(): void
    {
        $constraintsTheCasesBreak = array_values(array_unique(array_map(
            static fn (array $case): string => $case[1],
            iterator_to_array(self::rowsTheDatabaseRefuses(), false),
        )));

        self::assertEqualsCanonicalizing(
            $this->connection()->fetchFirstColumn(
                "SELECT conname FROM pg_constraint WHERE contype IN ('c', 'f') AND conrelid = 'public.rvw_area'::regclass"
                ." UNION ALL SELECT indexrelid::regclass::text FROM pg_index WHERE indisunique AND NOT indisprimary AND indrelid = 'public.rvw_area'::regclass",
            ),
            $constraintsTheCasesBreak,
        );
    }

    /**
     * @return iterable<string, array{non-empty-list<array{string, array<string, mixed>}>, string, string}>
     */
    public static function rowsTheDatabaseRefuses(): iterable
    {
        yield 'an area at position -1' => [[...self::rounds(), self::area(['position' => -1])], 'rvw_area_position_check', self::CHECK_VIOLATION];
        yield 'an empty name' => [[...self::rounds(), self::area(['name' => ''])], 'rvw_area_name_check', self::CHECK_VIOLATION];
        yield 'a name of 81 characters' => [[...self::rounds(), self::area(['name' => str_repeat('é', 81)])], 'rvw_area_name_check', self::CHECK_VIOLATION];
        yield 'a name with a tab before it' => [[...self::rounds(), self::area(['name' => "\tPer product"])], 'rvw_area_name_check', self::CHECK_VIOLATION];
        yield 'a note of 1001 characters' => [[...self::rounds(), self::decidedArea(['note' => str_repeat('é', 1001)])], 'rvw_area_note_check', self::CHECK_VIOLATION];
        yield 'a note of only a line break' => [[...self::rounds(), self::decidedArea(['note' => "\r\n"])], 'rvw_area_note_check', self::CHECK_VIOLATION];
        yield 'an unknown verdict' => [[...self::rounds(), self::decidedArea(['verdict' => 'fail'])], 'rvw_area_verdict_check', self::CHECK_VIOLATION];
        yield 'a verdict with nobody who decided' => [[...self::rounds(), self::decidedArea(['decided_by' => null])], 'rvw_area_decided_check', self::CHECK_VIOLATION];
        yield 'a verdict with no time' => [[...self::rounds(), self::decidedArea(['decided_at' => null])], 'rvw_area_decided_check', self::CHECK_VIOLATION];
        yield 'who decided and when with no verdict' => [[...self::rounds(), self::area(['decided_by' => self::MIA, 'decided_at' => self::DECIDED_AT])], 'rvw_area_decided_check', self::CHECK_VIOLATION];
        yield 'a note with no verdict' => [[...self::rounds(), self::area(['note' => 'The total is off'])], 'rvw_area_decided_check', self::CHECK_VIOLATION];
        yield 'one picture as before and after' => [[...self::rounds(), self::area(['before_screenshot_id' => self::AFTER])], 'rvw_area_screenshots_check', self::CHECK_VIOLATION];
        yield 'an area of a round that does not exist' => [[...self::rounds(), self::area(['round_id' => self::MISSING_ROUND])], 'rvw_area_round_fkey', self::FOREIGN_KEY_VIOLATION];
        yield 'an after picture that does not exist' => [[...self::rounds(), self::area(['after_screenshot_id' => self::MISSING_SCREENSHOT])], 'rvw_area_after_screenshot_fkey', self::FOREIGN_KEY_VIOLATION];
        yield 'a before picture that does not exist' => [[...self::rounds(), self::area(['before_screenshot_id' => self::MISSING_SCREENSHOT])], 'rvw_area_before_screenshot_fkey', self::FOREIGN_KEY_VIOLATION];
        yield 'a second area at one position' => [[...self::rounds(), self::area([]), self::area(['id' => self::SECOND_AREA, 'after_screenshot_id' => self::SECOND_AFTER])], 'rvw_area_round_id_position_key', self::UNIQUE_VIOLATION];
        yield 'a second area of one after picture' => [[...self::rounds(), self::area([]), self::area(['id' => self::SECOND_AREA, 'position' => 1])], 'rvw_area_after_screenshot_id_key', self::UNIQUE_VIOLATION];
    }

    /**
     * @param non-empty-list<array{string, array<string, mixed>}> $rows the last one is refused
     */
    #[Test]
    #[DataProvider('rowsTheDatabaseRefuses')]
    public function theDatabaseRefusesTheRow(array $rows, string $violatedConstraint, string $sqlState): void
    {
        $refusedTable = $rows[array_key_last($rows)][0];
        $rowsBefore = \count(array_filter(\array_slice($rows, 0, -1), static fn (array $row): bool => $row[0] === $refusedTable));

        self::assertSame([$sqlState, true, $rowsBefore], $this->inserts()->refusalOfLast($rows, $violatedConstraint));
    }

    /**
     * @return iterable<string, array{non-empty-list<array{string, array<string, mixed>}>}>
     */
    public static function rowsTheDatabaseAccepts(): iterable
    {
        yield 'a decided area with a name of 80 and a note of 1000 characters' => [[...self::rounds(), self::decidedArea(['name' => str_repeat('é', 80), 'note' => str_repeat('é', 1000)])]];
        yield 'each verdict, the last one with no note' => [[
            ...self::rounds(),
            self::decidedArea(['verdict' => 'needs_fix']),
            self::decidedArea(['id' => self::SECOND_AREA, 'position' => 1, 'after_screenshot_id' => self::SECOND_AFTER, 'verdict' => 'question', 'note' => null]),
        ]];
        yield 'an area with no earlier picture' => [[...self::rounds(), self::area(['before_screenshot_id' => null])]];
        yield 'two areas compared with one earlier picture' => [[
            ...self::rounds(),
            self::area([]),
            self::area(['id' => self::SECOND_AREA, 'position' => 1, 'after_screenshot_id' => self::SECOND_AFTER]),
        ]];
    }

    /**
     * @param non-empty-list<array{string, array<string, mixed>}> $rows
     */
    #[Test]
    #[DataProvider('rowsTheDatabaseAccepts')]
    public function theDatabaseAcceptsTheRows(array $rows): void
    {
        self::assertSame(array_fill(0, \count($rows), 1), $this->inserts()->insertAll($rows));
    }

    #[Test]
    public function everyIndexOfTheAreaTableStartsWithTheOrganization(): void
    {
        self::assertEqualsCanonicalizing(
            [
                'CREATE UNIQUE INDEX rvw_area_pkey ON public.rvw_area USING btree (organization_id, id)',
                'CREATE UNIQUE INDEX rvw_area_round_id_position_key ON public.rvw_area USING btree (organization_id, round_id, "position")',
                'CREATE UNIQUE INDEX rvw_area_after_screenshot_id_key ON public.rvw_area USING btree (organization_id, after_screenshot_id)',
                'CREATE INDEX rvw_area_before_screenshot_id_idx ON public.rvw_area USING btree (organization_id, before_screenshot_id)',
            ],
            $this->connection()->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = 'rvw_area'"),
        );
    }

    #[Test]
    public function theForeignKeysOfTheAreaTableStayInsideReview(): void
    {
        self::assertEqualsCanonicalizing(
            [
                'rvw_area (organization_id, round_id) -> rvw_round',
                'rvw_area (organization_id, after_screenshot_id) -> rvw_screenshot',
                'rvw_area (organization_id, before_screenshot_id) -> rvw_screenshot',
            ],
            $this->connection()->fetchFirstColumn(
                "SELECT c.conrelid::regclass::text || ' (' || (SELECT string_agg(a.attname, ', ' ORDER BY k.ord) FROM unnest(c.conkey) WITH ORDINALITY k(attnum, ord)"
                ." JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum) || ') -> ' || c.confrelid::regclass::text"
                ." FROM pg_constraint c WHERE c.contype = 'f' AND c.conrelid = 'public.rvw_area'::regclass",
            ),
        );
    }

    /**
     * Committed rows of Acme Foods: the app under Beta Bakery reads none of them and its update changes none, even
     * when it names the ids; under Acme Foods it reads its area.
     */
    #[Test]
    public function anotherOrganizationNeitherSeesNorChangesTheAreas(): void
    {
        $this->roundRows()->addAllOf(self::ACME_FOODS, [...self::rounds(), self::area([])]);

        $seenAndUpdatedByBeta = $this->transactions()->committedAs(self::BETA_BAKERY, fn (): array => [
            $this->connection()->fetchFirstColumn('SELECT id FROM rvw_area'),
            $this->connection()->executeStatement(
                "UPDATE rvw_area SET verdict = 'pass', decided_by = ?, decided_at = ? WHERE organization_id = ? AND id = ?",
                [self::MIA, self::DECIDED_AT, self::ACME_FOODS, self::AREA],
            ),
        ]);

        self::assertSame([[], 0], $seenAndUpdatedByBeta);
        self::assertSame(
            [['id' => self::AREA, 'verdict' => null]],
            $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchAllAssociative('SELECT id, verdict FROM rvw_area')),
        );
    }

    /**
     * A draft that drops its "after" picture drops the area with it; the earlier round's "before" picture is never
     * removed while an area compares with it.
     */
    #[Test]
    public function removingTheAfterPictureRemovesTheAreaAndRemovingTheBeforePictureIsRefused(): void
    {
        $this->roundRows()->addAllOf(self::ACME_FOODS, [...self::rounds(), self::area([])]);

        $refusal = $this->transactions()->committedAs(self::ACME_FOODS, function (): ?ForeignKeyConstraintViolationException {
            $this->connection()->createSavepoint('before_picture');
            try {
                $this->connection()->executeStatement('DELETE FROM rvw_screenshot WHERE organization_id = ? AND id = ?', [self::ACME_FOODS, self::BEFORE]);
            } catch (ForeignKeyConstraintViolationException $refused) {
                $this->connection()->rollbackSavepoint('before_picture');

                return $refused;
            }

            return null;
        });
        self::assertNotNull($refusal);
        self::assertStringContainsString('rvw_area_before_screenshot_fkey', $refusal->getMessage());
        self::assertSame([self::AREA], $this->areaIdsOfAcmeFoods());

        $this->transactions()->committedAs(self::ACME_FOODS, fn (): int|string => $this->connection()->executeStatement(
            'DELETE FROM rvw_screenshot WHERE organization_id = ? AND id = ?',
            [self::ACME_FOODS, self::AFTER],
        ));
        self::assertSame([], $this->areaIdsOfAcmeFoods());
    }

    /**
     * Round 0 with the "before" picture, and a review round with two "after" pictures.
     *
     * @return list<array{string, array<string, mixed>}>
     */
    private static function rounds(): array
    {
        return [
            self::round(self::REPORT, ['kind' => 'report', 'number' => 0]),
            self::round(self::REVIEW, ['kind' => 'review', 'number' => 2, 'shared_at' => '2027-03-16 08:00:00+00']),
            self::screenshot(self::BEFORE, self::REPORT, 0),
            self::screenshot(self::AFTER, self::REVIEW, 0),
            self::screenshot(self::SECOND_AFTER, self::REVIEW, 1),
        ];
    }

    /**
     * @param array<string, mixed> $change
     *
     * @return array{string, array<string, mixed>}
     */
    private static function round(string $roundId, array $change): array
    {
        return ['rvw_round', [
            'organization_id' => self::ACME_FOODS,
            'id' => $roundId,
            'ticket_id' => self::TICKET,
            'owner_id' => self::LENA,
            'shared_at' => '2027-03-15 10:00:00+00',
            'state' => 'open',
            'version' => 1,
            'created_at' => '2027-03-15 10:00:00+00',
            ...$change,
        ]];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private static function screenshot(string $screenshotId, string $roundId, int $position): array
    {
        return ['rvw_screenshot', [
            'organization_id' => self::ACME_FOODS,
            'id' => $screenshotId,
            'round_id' => $roundId,
            'media_id' => self::MEDIA,
            'position' => $position,
            'created_at' => '2027-03-15 10:00:00+00',
        ]];
    }

    /**
     * @param array<string, mixed> $change the columns that differ from a valid area with no verdict
     *
     * @return array{string, array<string, mixed>}
     */
    private static function area(array $change): array
    {
        return ['rvw_area', [
            'organization_id' => self::ACME_FOODS,
            'id' => self::AREA,
            'round_id' => self::REVIEW,
            'name' => 'Per product',
            'position' => 0,
            'before_screenshot_id' => self::BEFORE,
            'after_screenshot_id' => self::AFTER,
            'created_at' => '2027-03-16 08:00:00+00',
            ...$change,
        ]];
    }

    /**
     * @param array<string, mixed> $change the columns that differ from a valid area with a Pass and a note
     *
     * @return array{string, array<string, mixed>}
     */
    private static function decidedArea(array $change): array
    {
        return self::area(['verdict' => 'pass', 'note' => 'Looks right now', 'decided_by' => self::MIA, 'decided_at' => self::DECIDED_AT, ...$change]);
    }

    /**
     * @return list<mixed>
     */
    private function areaIdsOfAcmeFoods(): array
    {
        return $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connection()->fetchFirstColumn('SELECT id FROM rvw_area ORDER BY id'));
    }

    private function inserts(): RolledBackInserts
    {
        return new RolledBackInserts($this->connection(), self::getContainer()->get(TenantContext::class), self::ACME_FOODS);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), self::getContainer()->get(TenantContext::class));
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function roundRows(): RoundRows
    {
        return new RoundRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), self::getContainer()->get(TenantContext::class));
    }
}
