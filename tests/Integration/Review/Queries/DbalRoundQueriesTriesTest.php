<?php

declare(strict_types=1);

namespace App\Tests\Integration\Review\Queries;

use App\Review\Api\AreaView;
use App\Review\Api\PinNote;
use App\Review\Api\ReviewRoundQueries;
use App\Review\Api\ReviewRoundView;
use App\Review\Api\RoundAccessFacts;
use App\Review\Api\RoundPinView;
use App\Review\Api\RoundQueries;
use App\Review\Api\RoundScreenshotView;
use App\Review\Api\RoundView;
use App\Review\Api\ScreenshotNotes;
use App\Review\Api\SharedRoundNotes;
use App\Review\Infrastructure\Doctrine\DbalReviewRoundQueries;
use App\Review\Infrastructure\Doctrine\DbalRoundQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Review\Doctrine\RoundRows;
use App\Tests\Integration\Ticketing\ShopTeamRows;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tries of a screenshot (ADR-0048) through DbalRoundQueries and DbalReviewRoundQueries, on rows committed once per
 * class in Acme Foods' Shop team (Lena leads it, Omar develops, Mia tests). Omar's shared draft, Round 1, holds the
 * chain A (A1 at 0, A2 at 2, A3 at 3, A2 and A3 retakes of A1) and B at 1; A1 (fixed on try 2), B and A3 have a pin,
 * and the shared card lists chain A's latest try A3 before B, by first try. The team sees only
 * the latest try of each chain, B and A3; only Omar's own draftOf gives A1 and A2. Omar also has an unshared draft with
 * the chain C (C1 at 0, C2 at 1), and Lena's review Round 2 has areas whose "before" is A1, A3 and B. Rows go in out of
 * position order, so only the queries' order puts them right. Every time is fixed in March 2027.
 */
final class DbalRoundQueriesTriesTest extends KernelTestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000014c001';
    private const string LENA = '0197a3c0-0000-7000-8000-00000014c011';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000014c012';
    private const string MIA = '0197a3c0-0000-7000-8000-00000014c013';
    private const string SHOP_TEAM = '0197a3c0-0000-7000-8000-00000014c021';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-00000014c022';
    private const string TICKET = '0197a3c0-0000-7000-8000-00000014c031';

    private const string SHARED_DRAFT = '0197a3c0-0000-7000-8000-00000014c041';
    private const string UNSHARED_DRAFT = '0197a3c0-0000-7000-8000-00000014c042';
    private const string REVIEW_ROUND = '0197a3c0-0000-7000-8000-00000014c043';

    private const string A1 = '0197a3c0-0000-7000-8000-00000014c051';
    private const string B = '0197a3c0-0000-7000-8000-00000014c052';
    private const string A2 = '0197a3c0-0000-7000-8000-00000014c053';
    private const string A3 = '0197a3c0-0000-7000-8000-00000014c054';
    private const string C1 = '0197a3c0-0000-7000-8000-00000014c055';
    private const string C2 = '0197a3c0-0000-7000-8000-00000014c056';
    private const string AFTER_OF_A1 = '0197a3c0-0000-7000-8000-00000014c057';
    private const string AFTER_OF_A3 = '0197a3c0-0000-7000-8000-00000014c058';
    private const string AFTER_OF_B = '0197a3c0-0000-7000-8000-00000014c059';

    private const string A1_PIN = '0197a3c0-0000-7000-8000-00000014c061';
    private const string B_PIN = '0197a3c0-0000-7000-8000-00000014c062';
    private const string A3_PIN = '0197a3c0-0000-7000-8000-00000014c063';

    private const string AREA_BEFORE_A1 = '0197a3c0-0000-7000-8000-00000014c071';
    private const string AREA_BEFORE_A3 = '0197a3c0-0000-7000-8000-00000014c072';
    private const string AREA_BEFORE_B = '0197a3c0-0000-7000-8000-00000014c073';

    private const string MEDIA = '0197a3c0-0000-7000-8000-00000014c081';

    private const string CREATED_AT = '2027-03-15 10:00:00+00';
    private const string SHARED_AT = '2027-03-16 09:00:00+00';
    private const string REVIEWED_AT = '2027-03-17 09:00:00+00';

    /** Every viewer's user id. */
    private const array VIEWERS = ['Lena' => self::LENA, 'Mia' => self::MIA, 'Omar' => self::OMAR];

    public static function setUpBeforeClass(): void
    {
        self::removeRows();
        self::shopTeamRows()->add(self::ACME_FOODS, self::SHOP_TEAM, self::SHOP_PROJECT, self::LENA, [
            self::LENA => ['Lena', 'lead', 'developer'],
            self::OMAR => ['Omar', 'member', 'developer'],
            self::MIA => ['Mia', 'member', 'tester'],
        ]);
        new TicketRows(self::migrator(), self::tenantContext())->addWithAudienceOf(self::ACME_FOODS, self::TICKET, self::SHOP_PROJECT, 1, 'all', self::SHOP_TEAM, self::MIA);

        $round = static fn (string $id, ?int $number, string $kind, string $ownerId, ?string $sharedAt): array => ['rvw_round', [
            'organization_id' => self::ACME_FOODS, 'id' => $id, 'ticket_id' => self::TICKET, 'number' => $number, 'kind' => $kind,
            'owner_id' => $ownerId, 'shared_at' => $sharedAt, 'state' => 'open', 'version' => 1, 'created_at' => self::CREATED_AT,
        ]];
        $screenshot = static fn (string $id, string $roundId, int $position, ?string $retakeOf): array => ['rvw_screenshot', [
            'organization_id' => self::ACME_FOODS, 'id' => $id, 'round_id' => $roundId, 'media_id' => self::MEDIA, 'position' => $position,
            'title' => 'Try at '.$position, 'retake_of' => $retakeOf, 'created_at' => self::CREATED_AT,
        ]];
        $pin = static fn (string $id, string $screenshotId, string $note, ?string $verdict = null, ?int $fixedOnTry = null): array => ['rvw_pin', [
            'organization_id' => self::ACME_FOODS, 'id' => $id, 'screenshot_id' => $screenshotId, 'number' => 1, 'x_pct' => '40.00',
            'y_pct' => '60.00', 'note' => $note, 'verdict' => $verdict, 'source' => 'human', 'author_id' => self::OMAR,
            'fixed_on_try' => $fixedOnTry, 'created_at' => self::CREATED_AT,
        ]];
        $area = static fn (string $id, int $position, string $beforeId, string $afterId): array => ['rvw_area', [
            'organization_id' => self::ACME_FOODS, 'id' => $id, 'round_id' => self::REVIEW_ROUND, 'name' => 'Area '.$position,
            'position' => $position, 'before_screenshot_id' => $beforeId, 'after_screenshot_id' => $afterId, 'created_at' => self::REVIEWED_AT,
        ]];
        // A retake's first try goes in before it (rvw_screenshot_retake_of_fkey); the positions are not in order.
        new RoundRows(self::migrator(), self::tenantContext())->addAllOf(self::ACME_FOODS, [
            $round(self::SHARED_DRAFT, 1, 'draft', self::OMAR, self::SHARED_AT),
            $round(self::UNSHARED_DRAFT, null, 'draft', self::OMAR, null),
            $round(self::REVIEW_ROUND, 2, 'review', self::LENA, self::REVIEWED_AT),
            $screenshot(self::B, self::SHARED_DRAFT, 1, null),
            $screenshot(self::A1, self::SHARED_DRAFT, 0, null),
            $screenshot(self::A3, self::SHARED_DRAFT, 3, self::A1),
            $screenshot(self::A2, self::SHARED_DRAFT, 2, self::A1),
            $screenshot(self::C1, self::UNSHARED_DRAFT, 0, null),
            $screenshot(self::C2, self::UNSHARED_DRAFT, 1, self::C1),
            $screenshot(self::AFTER_OF_A1, self::REVIEW_ROUND, 0, null),
            $screenshot(self::AFTER_OF_A3, self::REVIEW_ROUND, 1, null),
            $screenshot(self::AFTER_OF_B, self::REVIEW_ROUND, 2, null),
            $pin(self::A3_PIN, self::A3, 'Still cut off on the latest try'),
            $pin(self::A1_PIN, self::A1, 'Cut off on the first try', 'fixed', 2),
            $pin(self::B_PIN, self::B, 'The basket'),
            $area(self::AREA_BEFORE_A1, 0, self::A1, self::AFTER_OF_A1),
            $area(self::AREA_BEFORE_A3, 1, self::A3, self::AFTER_OF_A3),
            $area(self::AREA_BEFORE_B, 2, self::B, self::AFTER_OF_B),
        ]);
        self::ensureKernelShutdown();
    }

    public static function tearDownAfterClass(): void
    {
        self::removeRows();
        self::ensureKernelShutdown();
        parent::tearDownAfterClass();
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
        self::tenantContext()->reset();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function viewers(): iterable
    {
        yield 'Lena, the lead of the ticket\'s team' => ['Lena'];
        yield 'Mia, the team\'s tester' => ['Mia'];
        yield 'Omar, who owns the shared draft' => ['Omar'];
    }

    /**
     * The card shows what the team sees, so the owner gets the same latest tries as the others, each with its own pin;
     * A1's pin is nowhere. A3 comes before B, as its chain's first try A1 is at 0 and B at 1, so the card numbers them as
     * the owner's draft did, though A3's own position is after B's.
     */
    #[Test]
    #[DataProvider('viewers')]
    public function aSharedDraftShowsEveryoneOnlyTheLatestTryOfEachScreenshotInTheOrderOfTheirFirstTries(string $viewer): void
    {
        $sharedDraft = $this->sharedDraftAsSeenBy($viewer);

        self::assertNotSame([], $sharedDraft->screenshots);
        self::assertSame(
            [[self::A3, 3, self::A1, [self::A3_PIN]], [self::B, 1, null, [self::B_PIN]]],
            array_map(static fn (RoundScreenshotView $screenshot): array => [
                $screenshot->id,
                $screenshot->position,
                $screenshot->retakeOf,
                array_map(static fn (RoundPinView $pin): string => $pin->id, $screenshot->pins),
            ], $sharedDraft->screenshots),
        );
    }

    /**
     * Claude Code reads the newest shared round's notes for the person working on the ticket, so A1's pin and A2 stay
     * out; the review Round 2 is newer but is no report or draft.
     */
    #[Test]
    public function theSharedRoundNotesHoldOnlyTheLatestTryOfEachScreenshot(): void
    {
        $notes = self::transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?SharedRoundNotes => $this->roundQueries()->sharedRoundNotes(new TicketId(self::TICKET)));

        self::assertNotNull($notes);
        self::assertSame(self::SHARED_DRAFT, $notes->roundId);
        self::assertNotSame([], $notes->screenshots);
        self::assertSame(
            [[self::B, ['The basket']], [self::A3, ['Still cut off on the latest try']]],
            array_map(static fn (ScreenshotNotes $screenshot): array => [
                $screenshot->screenshotId,
                array_map(static fn (PinNote $pin): string => $pin->note, $screenshot->pins),
            ], $notes->screenshots),
        );
    }

    /**
     * Earlier tries stay with their owner (ADR-0048): draftOf gives Omar every try of his shared draft, each with its
     * pin, the tries of chain A together before B, and gives Lena or Mia nothing of it. A1's pin, fixed on try 2, says
     * so; the others have no try that fixed them.
     */
    #[Test]
    public function theOwnersDraftOfASharedDraftGivesEveryTryAndNobodyElseGetsIt(): void
    {
        $asOmar = $this->draftOfAs(self::SHARED_DRAFT, 'Omar');

        self::assertNotNull($asOmar);
        self::assertSame(
            [
                [self::A1, null, [[self::A1_PIN, 'fixed', 2]]],
                [self::A2, self::A1, []],
                [self::A3, self::A1, [[self::A3_PIN, null, null]]],
                [self::B, null, [[self::B_PIN, null, null]]],
            ],
            array_map(static fn (RoundScreenshotView $screenshot): array => [
                $screenshot->id,
                $screenshot->retakeOf,
                array_map(static fn (RoundPinView $pin): array => [$pin->id, $pin->verdict, $pin->fixedOnTry], $screenshot->pins),
            ], $asOmar->screenshots),
        );
        self::assertNull($this->draftOfAs(self::SHARED_DRAFT, 'Lena'));
        self::assertNull($this->draftOfAs(self::SHARED_DRAFT, 'Mia'));
    }

    /**
     * The owner's unshared draft is still being worked on, so the ticket's rounds give him every try of it; nobody else
     * gets the draft at all.
     */
    #[Test]
    public function theOwnersUnsharedDraftShowsHimEveryTry(): void
    {
        $unsharedDrafts = array_values(array_filter($this->roundsFor('Omar'), static fn (RoundView $round): bool => self::UNSHARED_DRAFT === $round->id));

        self::assertCount(1, $unsharedDrafts);
        self::assertSame(
            [[self::C1, null], [self::C2, self::C1]],
            array_map(static fn (RoundScreenshotView $screenshot): array => [$screenshot->id, $screenshot->retakeOf], $unsharedDrafts[0]->screenshots),
        );
        foreach (['Lena', 'Mia'] as $viewer) {
            $roundIds = array_map(static fn (RoundView $round): string => $round->id, $this->roundsFor($viewer));
            self::assertSame([self::SHARED_DRAFT, self::REVIEW_ROUND], $roundIds, $viewer);
        }
    }

    /**
     * A "before" is the earlier round's "after" as the team saw it: an area that names the earlier try A1 has none, and
     * so shows no pin of it; the latest try A3 and B are "befores" with their pins.
     */
    #[Test]
    #[DataProvider('viewers')]
    public function anAreaWhoseBeforeIsAnEarlierTryHasNoBefore(string $viewer): void
    {
        $viewerId = new UserId(self::VIEWERS[$viewer]);
        $reviewRound = self::transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?ReviewRoundView => $this->reviewRoundQueries()->roundOf(new TicketId(self::TICKET), 2, $viewerId));

        self::assertNotNull($reviewRound);
        self::assertNotSame([], $reviewRound->areas);
        self::assertSame(
            [
                [self::AREA_BEFORE_A1, null, null, []],
                [self::AREA_BEFORE_A3, self::A3, 1, [self::A3_PIN]],
                [self::AREA_BEFORE_B, self::B, 1, [self::B_PIN]],
            ],
            array_map(static fn (AreaView $area): array => [
                $area->id,
                $area->before?->id,
                $area->beforeRoundNumber,
                array_map(static fn (RoundPinView $pin): string => $pin->id, $area->before->pins ?? []),
            ], $reviewRound->areas),
        );
    }

    /**
     * set_verdict looks a picture up by its id; a guessed id of an earlier try of a shared draft is not found, as an
     * unknown one is, so the answer never tells that the try exists. The latest try is found as shared (read-only), and
     * an earlier try of an unshared draft as unshared.
     */
    #[Test]
    public function anEarlierTryOfASharedDraftIsNotFoundByItsPicture(): void
    {
        $sharedDraft = new RoundAccessFacts(new OrganizationId(self::ACME_FOODS), self::SHARED_DRAFT, new TicketId(self::TICKET), new UserId(self::OMAR), false, false);
        $unsharedDraft = new RoundAccessFacts(new OrganizationId(self::ACME_FOODS), self::UNSHARED_DRAFT, new TicketId(self::TICKET), new UserId(self::OMAR), true, false);

        self::assertNull($this->draftScreenshotOf(self::A1), 'The first try of a chain with retakes');
        self::assertNull($this->draftScreenshotOf(strtoupper(self::A1)));
        self::assertNull($this->draftScreenshotOf(self::A2), 'A retake with a later one');
        self::assertEquals($sharedDraft, $this->draftScreenshotOf(self::A3));
        self::assertEquals($sharedDraft, $this->draftScreenshotOf(self::B));
        self::assertEquals($unsharedDraft, $this->draftScreenshotOf(self::C1));
    }

    private function sharedDraftAsSeenBy(string $viewer): RoundView
    {
        $sharedDrafts = array_values(array_filter($this->roundsFor($viewer), static fn (RoundView $round): bool => self::SHARED_DRAFT === $round->id));
        self::assertCount(1, $sharedDrafts);

        return $sharedDrafts[0];
    }

    /**
     * @return list<RoundView>
     */
    private function roundsFor(string $viewer): array
    {
        $viewerId = new UserId(self::VIEWERS[$viewer]);

        return self::transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->roundQueries()->forTicket(new TicketId(self::TICKET), $viewerId)->rounds);
    }

    private function draftOfAs(string $roundId, string $viewer): ?RoundView
    {
        $viewerId = new UserId(self::VIEWERS[$viewer]);

        return self::transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?RoundView => $this->roundQueries()->draftOf($roundId, $viewerId));
    }

    private function draftScreenshotOf(string $screenshotId): ?RoundAccessFacts
    {
        return self::transactions()->rolledBackAs(self::ACME_FOODS, fn (): ?RoundAccessFacts => $this->roundQueries()->draftScreenshotOf(new TicketId(self::TICKET), $screenshotId));
    }

    private static function removeRows(): void
    {
        new RoundRows(self::migrator(), self::tenantContext())->removeAllOf(self::ACME_FOODS);
        self::shopTeamRows()->removeAllOf([self::LENA, self::OMAR, self::MIA], self::ACME_FOODS);
    }

    private function roundQueries(): RoundQueries
    {
        $roundQueries = self::getContainer()->get('test.review_round_queries');
        \assert($roundQueries instanceof DbalRoundQueries);

        return $roundQueries;
    }

    private function reviewRoundQueries(): ReviewRoundQueries
    {
        $reviewRoundQueries = self::getContainer()->get('test.review_review_round_queries');
        \assert($reviewRoundQueries instanceof DbalReviewRoundQueries);

        return $reviewRoundQueries;
    }

    private static function shopTeamRows(): ShopTeamRows
    {
        return new ShopTeamRows(self::migrator(), self::tenantContext());
    }

    private static function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::entityManager(), self::tenantContext());
    }

    private static function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private static function migrator(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.migrator_connection');
    }

    private static function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
