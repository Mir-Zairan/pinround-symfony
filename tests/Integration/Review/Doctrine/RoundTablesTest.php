<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Doctrine;

use App\SharedKernel\Application\TenantContext;
use App\Tests\Integration\Conversation\RolledBackInserts;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What rvw_round, rvw_screenshot and rvw_pin keep on their own (Version20261115180000), whatever writes them: the
 * CHECKs and keys refuse a row the domain could not make, written as the app past the domain, and the rows next to it
 * are still accepted; the indexes and foreign keys are as the migration made them. Grants, ownership and the schema
 * filter are pinned with every other module's in DatabaseRolesTest, row-level security in CatalogTest.
 */
final class RoundTablesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000f5201';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000f5202';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000f5203';
    private const string ROUND = '0197a3c0-0000-7000-8000-0000000f5211';
    private const string SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f5221';
    private const string SECOND_SCREENSHOT = '0197a3c0-0000-7000-8000-0000000f5222';
    private const string PIN = '0197a3c0-0000-7000-8000-0000000f5231';
    private const string SECOND_PIN = '0197a3c0-0000-7000-8000-0000000f5232';
    private const string MISSING_ROUND = '0197a3c0-0000-7000-8000-0000000f5241';
    private const string MEDIA = '0197a3c0-0000-7000-8000-0000000f5251';
    private const string CHECK_VIOLATION = '23514';
    private const string UNIQUE_VIOLATION = '23505';
    private const string FOREIGN_KEY_VIOLATION = '23503';

    /**
     * Every CHECK, foreign key and unique index but the primary keys (DbalRoundsTest clashes those) has a case below
     * that breaks it, and every case breaks one the tables have.
     */
    #[Test]
    public function theCasesCoverEveryCheckForeignKeyAndUniqueIndexOfTheRoundTables(): void
    {
        $constraintsTheCasesBreak = array_values(array_unique(array_map(
            static fn (array $case): string => $case[1],
            iterator_to_array(self::rowsTheDatabaseRefuses(), false),
        )));

        self::assertEqualsCanonicalizing(
            $this->connection()->fetchFirstColumn(
                "SELECT conname FROM pg_constraint WHERE contype IN ('c', 'f') AND conrelid IN ('public.rvw_round'::regclass, 'public.rvw_screenshot'::regclass, 'public.rvw_pin'::regclass)"
                .' UNION ALL SELECT indexrelid::regclass::text FROM pg_index WHERE indisunique AND NOT indisprimary'
                ." AND indrelid IN ('public.rvw_round'::regclass, 'public.rvw_screenshot'::regclass, 'public.rvw_pin'::regclass)",
            ),
            $constraintsTheCasesBreak,
        );
    }

    /**
     * @return iterable<string, array{non-empty-list<array{string, array<string, mixed>}>, string, string}>
     */
    public static function rowsTheDatabaseRefuses(): iterable
    {
        yield 'a round of an unknown kind' => [[self::round(['kind' => 'sketch'])], 'rvw_round_kind_check', self::CHECK_VIOLATION];
        yield 'a round in an unknown state' => [[self::round(['state' => 'paused'])], 'rvw_round_state_check', self::CHECK_VIOLATION];
        yield 'a shared round with no number' => [[self::draftRound(['shared_at' => '2027-03-16 09:00:00+00'])], 'rvw_round_shared_check', self::CHECK_VIOLATION];
        yield 'a numbered round never shared' => [[self::draftRound(['number' => 1])], 'rvw_round_shared_check', self::CHECK_VIOLATION];
        yield 'a report that is not Round 0' => [[self::round(['number' => 1])], 'rvw_round_number_check', self::CHECK_VIOLATION];
        yield 'a report with no number' => [[self::round(['number' => null, 'shared_at' => null])], 'rvw_round_number_check', self::CHECK_VIOLATION];
        yield 'a shared draft as Round 0' => [[self::draftRound(['number' => 0, 'shared_at' => '2027-03-16 09:00:00+00'])], 'rvw_round_number_check', self::CHECK_VIOLATION];
        yield 'a round number below 0' => [[self::draftRound(['number' => -1, 'shared_at' => '2027-03-16 09:00:00+00'])], 'rvw_round_number_check', self::CHECK_VIOLATION];
        yield 'a round at version 0' => [[self::round(['version' => 0])], 'rvw_round_version_check', self::CHECK_VIOLATION];
        yield 'a draft decision of an unknown kind' => [[self::draftRound(['decision' => 'later', 'decided_at' => '2027-03-16 09:00:00+00'])], 'rvw_round_decision_check', self::CHECK_VIOLATION];
        yield 'a decision on a report' => [[self::round(['decision' => 'kept_private', 'decided_at' => '2027-03-16 09:00:00+00'])], 'rvw_round_decision_check', self::CHECK_VIOLATION];
        yield 'a decision with no time' => [[self::draftRound(['decision' => 'kept_private'])], 'rvw_round_decided_check', self::CHECK_VIOLATION];
        yield 'a decision time with no decision' => [[self::draftRound(['decided_at' => '2027-03-16 09:00:00+00'])], 'rvw_round_decided_check', self::CHECK_VIOLATION];
        yield 'a deleted draft still open' => [[self::draftRound(['deleted_at' => '2027-03-16 09:00:00+00'])], 'rvw_round_deleted_check', self::CHECK_VIOLATION];
        yield 'a deleted shared draft' => [[self::draftRound(['number' => 1, 'shared_at' => '2027-03-16 09:00:00+00', 'state' => 'closed', 'deleted_at' => '2027-03-16 09:00:00+00'])], 'rvw_round_deleted_check', self::CHECK_VIOLATION];
        yield 'a deleted report' => [[self::round(['state' => 'closed', 'deleted_at' => '2027-03-16 09:00:00+00'])], 'rvw_round_deleted_check', self::CHECK_VIOLATION];
        yield 'a second Round 0 of the ticket' => [[self::round([]), self::round(['id' => self::MISSING_ROUND])], 'rvw_round_ticket_id_number_key', self::UNIQUE_VIOLATION];
        yield 'a second open draft of the owner on the ticket' => [[self::draftRound([]), self::draftRound(['id' => self::MISSING_ROUND])], 'rvw_round_ticket_id_owner_id_open_draft_key', self::UNIQUE_VIOLATION];
        yield 'a screenshot of a round that does not exist' => [[self::screenshot(['round_id' => self::MISSING_ROUND])], 'rvw_screenshot_round_fkey', self::FOREIGN_KEY_VIOLATION];
        yield 'a screenshot at position -1' => [[self::round([]), self::screenshot(['position' => -1])], 'rvw_screenshot_position_check', self::CHECK_VIOLATION];
        yield 'a screenshot labelled other than before or after' => [[self::round([]), self::screenshot(['variant_label' => 'during'])], 'rvw_screenshot_variant_label_check', self::CHECK_VIOLATION];
        yield 'a screenshot with an unknown verdict' => [[self::round([]), self::screenshot(['verdict' => 'fine'])], 'rvw_screenshot_verdict_check', self::CHECK_VIOLATION];
        yield 'a title of 121 characters' => [[self::round([]), self::screenshot(['title' => str_repeat('é', 121)])], 'rvw_screenshot_title_check', self::CHECK_VIOLATION];
        yield 'an empty title' => [[self::round([]), self::screenshot(['title' => ''])], 'rvw_screenshot_title_check', self::CHECK_VIOLATION];
        yield 'a title with a space before it' => [[self::round([]), self::screenshot(['title' => ' Checkout'])], 'rvw_screenshot_title_check', self::CHECK_VIOLATION];
        yield 'a summary of 601 characters' => [[self::round([]), self::screenshot(['summary' => str_repeat('é', 601)])], 'rvw_screenshot_summary_check', self::CHECK_VIOLATION];
        yield 'a summary ending in a line break' => [[self::round([]), self::screenshot(['summary' => "The total is wrong\r\n"])], 'rvw_screenshot_summary_check', self::CHECK_VIOLATION];
        yield 'a second screenshot at one position' => [[self::round([]), self::screenshot([]), self::screenshot(['id' => self::SECOND_SCREENSHOT])], 'rvw_screenshot_round_id_position_key', self::UNIQUE_VIOLATION];
        yield 'a pin on a screenshot that does not exist' => [[self::pin([])], 'rvw_pin_screenshot_fkey', self::FOREIGN_KEY_VIOLATION];
        yield 'a pin numbered 0' => [[self::round([]), self::screenshot([]), self::pin(['number' => 0])], 'rvw_pin_number_check', self::CHECK_VIOLATION];
        yield 'a pin right of the picture' => [[self::round([]), self::screenshot([]), self::pin(['x_pct' => '100.01'])], 'rvw_pin_position_check', self::CHECK_VIOLATION];
        yield 'a pin above the picture' => [[self::round([]), self::screenshot([]), self::pin(['y_pct' => '-0.01'])], 'rvw_pin_position_check', self::CHECK_VIOLATION];
        yield 'a pin note of 1001 characters' => [[self::round([]), self::screenshot([]), self::pin(['note' => str_repeat('é', 1001)])], 'rvw_pin_note_check', self::CHECK_VIOLATION];
        yield 'a pin note of only a line break' => [[self::round([]), self::screenshot([]), self::pin(['note' => "\n"])], 'rvw_pin_note_check', self::CHECK_VIOLATION];
        yield 'a pin with an unknown verdict' => [[self::round([]), self::screenshot([]), self::pin(['verdict' => 'maybe'])], 'rvw_pin_verdict_check', self::CHECK_VIOLATION];
        yield 'a pin from an unknown source' => [[self::round([]), self::screenshot([]), self::pin(['source' => 'import'])], 'rvw_pin_source_check', self::CHECK_VIOLATION];
        yield 'an AI pin in an unknown state' => [[self::round([]), self::screenshot([]), self::pin(['source' => 'ai', 'ai_state' => 'ignored'])], 'rvw_pin_ai_state_check', self::CHECK_VIOLATION];
        yield 'an AI pin with no state' => [[self::round([]), self::screenshot([]), self::pin(['source' => 'ai'])], 'rvw_pin_ai_check', self::CHECK_VIOLATION];
        yield 'a person\'s pin with an AI state' => [[self::round([]), self::screenshot([]), self::pin(['ai_state' => 'kept'])], 'rvw_pin_ai_check', self::CHECK_VIOLATION];
        yield 'a second pin with one number' => [[self::round([]), self::screenshot([]), self::pin([]), self::pin(['id' => self::SECOND_PIN])], 'rvw_pin_screenshot_id_number_key', self::UNIQUE_VIOLATION];
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
        yield 'a report with a picture of 120 and 600 characters and a pin note of 1000 at both corners' => [[
            self::round([]),
            self::screenshot(['title' => str_repeat('é', 120), 'summary' => str_repeat('é', 600), 'variant_label' => 'after', 'verdict' => 'looks_right']),
            self::pin(['x_pct' => '0', 'y_pct' => '100', 'note' => str_repeat('é', 1000), 'verdict' => 'still_wrong']),
            self::pin(['id' => self::SECOND_PIN, 'number' => 2, 'x_pct' => '100', 'y_pct' => '0', 'note' => '']),
        ]];
        yield 'an open draft and a shared one of one owner on the ticket' => [[
            self::draftRound([]),
            self::draftRound(['id' => self::MISSING_ROUND, 'number' => 1, 'shared_at' => '2027-03-16 09:00:00+00']),
        ]];
        yield 'a deleted draft and a new open one of one owner on the ticket' => [[
            self::draftRound(['state' => 'closed', 'deleted_at' => '2027-03-16 09:00:00+00']),
            self::draftRound(['id' => self::MISSING_ROUND]),
        ]];
        yield 'a draft kept private and a shared one decided as shared' => [[
            self::draftRound(['decision' => 'kept_private', 'decided_at' => '2027-03-16 09:00:00+00']),
            self::draftRound(['id' => self::MISSING_ROUND, 'number' => 1, 'shared_at' => '2027-03-16 09:00:00+00', 'decision' => 'shared', 'decided_at' => '2027-03-16 09:00:00+00']),
        ]];
        yield 'an AI pin kept' => [[self::round([]), self::screenshot([]), self::pin(['source' => 'ai', 'ai_state' => 'kept'])]];
        yield 'two screenshots at two positions with a pin 1 each' => [[
            self::round([]),
            self::screenshot([]),
            self::screenshot(['id' => self::SECOND_SCREENSHOT, 'position' => 1]),
            self::pin([]),
            self::pin(['id' => self::SECOND_PIN, 'screenshot_id' => self::SECOND_SCREENSHOT]),
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
    public function everyIndexOfTheRoundTablesStartsWithTheOrganization(): void
    {
        self::assertEqualsCanonicalizing(
            [
                'CREATE UNIQUE INDEX rvw_round_pkey ON public.rvw_round USING btree (organization_id, id)',
                'CREATE UNIQUE INDEX rvw_round_ticket_id_number_key ON public.rvw_round USING btree (organization_id, ticket_id, number)',
                "CREATE UNIQUE INDEX rvw_round_ticket_id_owner_id_open_draft_key ON public.rvw_round USING btree (organization_id, ticket_id, owner_id) WHERE ((kind = 'draft'::text) AND (shared_at IS NULL) AND (state = 'open'::text))",
                'CREATE UNIQUE INDEX rvw_screenshot_pkey ON public.rvw_screenshot USING btree (organization_id, id)',
                'CREATE UNIQUE INDEX rvw_screenshot_round_id_position_key ON public.rvw_screenshot USING btree (organization_id, round_id, "position")',
                'CREATE UNIQUE INDEX rvw_pin_pkey ON public.rvw_pin USING btree (organization_id, id)',
                'CREATE UNIQUE INDEX rvw_pin_screenshot_id_number_key ON public.rvw_pin USING btree (organization_id, screenshot_id, number)',
            ],
            $this->connection()->fetchFirstColumn("SELECT indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename IN ('rvw_round', 'rvw_screenshot', 'rvw_pin')"),
        );
    }

    /** Ticket, owner, picture and author are ids of other modules (ADR-0002): the foreign keys stay inside Review. */
    #[Test]
    public function theForeignKeysOfTheRoundTablesStayInsideReview(): void
    {
        self::assertEqualsCanonicalizing(
            ['rvw_screenshot (organization_id, round_id) -> rvw_round', 'rvw_pin (organization_id, screenshot_id) -> rvw_screenshot'],
            $this->connection()->fetchFirstColumn(
                "SELECT c.conrelid::regclass::text || ' (' || (SELECT string_agg(a.attname, ', ' ORDER BY k.ord) FROM unnest(c.conkey) WITH ORDINALITY k(attnum, ord)"
                ." JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.attnum) || ') -> ' || c.confrelid::regclass::text"
                ." FROM pg_constraint c WHERE c.contype = 'f' AND c.conrelid IN ('public.rvw_round'::regclass, 'public.rvw_screenshot'::regclass, 'public.rvw_pin'::regclass)",
            ),
        );
    }

    /**
     * @param array<string, mixed> $change the columns that differ from a valid Round 0
     *
     * @return array{string, array<string, mixed>}
     */
    private static function round(array $change): array
    {
        return ['rvw_round', [
            'organization_id' => self::ACME_FOODS,
            'id' => self::ROUND,
            'ticket_id' => self::TICKET,
            'number' => 0,
            'kind' => 'report',
            'owner_id' => self::LENA,
            'shared_at' => '2027-03-15 10:00:00+00',
            'state' => 'open',
            'version' => 1,
            'created_at' => '2027-03-15 10:00:00+00',
            ...$change,
        ]];
    }

    /**
     * @param array<string, mixed> $change the columns that differ from a valid open draft
     *
     * @return array{string, array<string, mixed>}
     */
    private static function draftRound(array $change): array
    {
        return self::round(['kind' => 'draft', 'number' => null, 'shared_at' => null, ...$change]);
    }

    /**
     * @param array<string, mixed> $change the columns that differ from a valid screenshot of the round
     *
     * @return array{string, array<string, mixed>}
     */
    private static function screenshot(array $change): array
    {
        return ['rvw_screenshot', [
            'organization_id' => self::ACME_FOODS,
            'id' => self::SCREENSHOT,
            'round_id' => self::ROUND,
            'media_id' => self::MEDIA,
            'position' => 0,
            'title' => 'Checkout',
            'summary' => 'The total is wrong',
            'created_at' => '2027-03-15 10:00:00+00',
            ...$change,
        ]];
    }

    /**
     * @param array<string, mixed> $change the columns that differ from a valid pin of the screenshot
     *
     * @return array{string, array<string, mixed>}
     */
    private static function pin(array $change): array
    {
        return ['rvw_pin', [
            'organization_id' => self::ACME_FOODS,
            'id' => self::PIN,
            'screenshot_id' => self::SCREENSHOT,
            'number' => 1,
            'x_pct' => '40.00',
            'y_pct' => '60.00',
            'note' => 'Looks off',
            'source' => 'human',
            'author_id' => self::LENA,
            'created_at' => '2027-03-15 10:00:00+00',
            ...$change,
        ]];
    }

    private function inserts(): RolledBackInserts
    {
        return new RolledBackInserts($this->connection(), self::getContainer()->get(TenantContext::class), self::ACME_FOODS);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }
}
