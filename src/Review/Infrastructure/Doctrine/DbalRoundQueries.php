<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Doctrine;

use App\Review\Api\DraftScreenshotChain;
use App\Review\Api\OpenDraftQueries;
use App\Review\Api\OpenDraftScreenshots;
use App\Review\Api\PinNote;
use App\Review\Api\RoundAccessFacts;
use App\Review\Api\RoundNotes;
use App\Review\Api\RoundPinView;
use App\Review\Api\RoundQueries;
use App\Review\Api\RoundScreenshotView;
use App\Review\Api\RoundView;
use App\Review\Api\ScreenshotChains;
use App\Review\Api\ScreenshotNotes;
use App\Review\Api\SharedRoundNotes;
use App\Review\Api\TicketRounds;
use App\Review\Application\OpenSharedRound;
use App\Review\Application\OpenSharedRoundQueries;
use App\Review\Application\RoundsVisibleTo;
use App\Review\Domain\DraftDecision;
use App\Review\Domain\RoundId;
use App\Review\Domain\ScreenshotId;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibility;
use Doctrine\DBAL\Connection;

/**
 * The rounds of a ticket from rvw_round, rvw_screenshot and rvw_pin through DBAL, after Ticketing's batch visibility
 * check of the ticket. Each level reads the next one's index ((organization_id, ticket_id, number), (organization_id,
 * round_id, position), (organization_id, screenshot_id, number)) and says whether it has rows below it, so a round with
 * no screenshot or a screenshot with no pin costs no query. lowestOpenSharedRoundOf reads one rvw_round row and
 * nothing below it, for the round bar. accessFactsOf, draftScreenshotOf, openDraftOf,
 * openDraftScreenshotsOf, roundNotes and sharedRoundNotes send one query each and make no visibility check: the first
 * two leave the decision to RoundVoter, the next three read only the owner's own draft, and the last leaves it to the caller's TicketVoter and
 * RoundVoter. draftOf reads one draft of its owner with its screenshots and pins, and needs none either. Of a shared
 * round, forTicket and sharedRoundNotes give only the latest try of each screenshot (ADR-0048), to everyone, and
 * draftScreenshotOf finds no earlier try; draftOf and the viewer's unshared draft give every try. forTicket and
 * forVisibleTo count each round's screenshots in the rounds query; forVisibleTo loads no shared draft's screenshots.
 */
final readonly class DbalRoundQueries implements RoundQueries, OpenDraftQueries, RoundsVisibleTo, OpenSharedRoundQueries
{
    /** The picture and pin columns of roundNotes and sharedRoundNotes, which screenshotNotesOf reads. */
    private const string NOTE_COLUMNS = 'screenshot.id AS screenshot_id, screenshot.media_id, screenshot.position,'
        .' screenshot.title, screenshot.summary, screenshot.verdict, pin.number AS pin_number, pin.x_pct, pin.y_pct, pin.note';

    /** The pictures of the round aliased round; sharedRoundNotes adds the latest-try rule to its ON clause. */
    private const string NOTE_SCREENSHOT_JOIN = ' LEFT JOIN rvw_screenshot AS screenshot ON screenshot.organization_id = round.organization_id AND screenshot.round_id = round.id';

    /**
     * The pins of each picture that count. The pin rule is in the ON clause, so a picture whose only pins are AI
     * suggestions still comes back, with none.
     */
    private const string NOTE_PIN_JOIN = ' LEFT JOIN rvw_pin AS pin ON pin.organization_id = screenshot.organization_id AND pin.screenshot_id = screenshot.id'
        .' AND '.DbalReviewRoundQueries::COUNTED_PIN;

    /**
     * The chain's first try of the row of rvw_screenshot aliased screenshot, found by its primary key, also when the
     * team does not see it; CHAIN_ORDER needs it.
     */
    private const string FIRST_TRY_JOIN = ' LEFT JOIN rvw_screenshot AS first_try ON first_try.organization_id = screenshot.organization_id AND first_try.id = screenshot.retake_of';

    /**
     * By the place of each chain's first try, then by position, as RoundView lists screenshots: the order ScreenshotChains
     * numbers them in, so a shared round lists its latest tries in the order, and with the numbers, its owner's draft
     * gave them.
     */
    private const string CHAIN_ORDER = ' ORDER BY COALESCE(first_try.position, screenshot.position), screenshot.position, screenshot.id';

    public function __construct(
        private Connection $connection,
        private TenantContext $tenantContext,
        private TicketVisibilities $ticketVisibilities,
        private TicketQueries $ticketQueries,
    ) {
    }

    /**
     * The SQL condition that the row of rvw_screenshot aliased $screenshotAlias has no later try in its chain, as
     * Round::latestTries has it: what anyone but the owner sees of a shared round (ADR-0048). A later try points at the
     * chain's first try, so the lookup is served by rvw_screenshot_retake_of_idx.
     *
     * @param string $screenshotAlias an SQL alias the caller wrote, never outside input
     */
    public static function latestTrySql(string $screenshotAlias): string
    {
        return \sprintf(
            'NOT EXISTS (SELECT 1 FROM rvw_screenshot AS later_try WHERE later_try.organization_id = %1$s.organization_id'
            .' AND later_try.round_id = %1$s.round_id AND later_try.retake_of = COALESCE(%1$s.retake_of, %1$s.id) AND later_try.position > %1$s.position)',
            $screenshotAlias,
        );
    }

    public function forTicket(TicketId $ticketId, UserId $viewer): TicketRounds
    {
        $organizationId = $this->requireTransactionAndTenant();

        return $this->roundsOf($organizationId, $ticketId, $this->ticketVisibilities->of($organizationId, $viewer), true);
    }

    public function forVisibleTo(TicketId $ticketId, TicketVisibility $viewer): TicketRounds
    {
        $organizationId = $this->requireTransactionAndTenant();
        if (!$viewer->organizationId()->equals($organizationId)) {
            throw new \InvalidArgumentException('The ticket visibility given is not one of the tenant\'s organization.');
        }

        return $this->roundsOf($organizationId, $ticketId, $viewer, false);
    }

    public function sharedRoundOf(TicketId $ticketId, TicketVisibility $viewer, int $number): ?RoundView
    {
        $organizationId = $this->requireTransactionAndTenant();
        if (!$viewer->organizationId()->equals($organizationId)) {
            throw new \InvalidArgumentException('The ticket visibility given is not one of the tenant\'s organization.');
        }
        if ($number < 0 || [] === $this->ticketQueries->visibleTicketIdsOf($viewer, [$ticketId])) {
            return null;
        }

        // By rvw_round_ticket_id_number_key; only a shared round has a number. has_screenshots spares the screenshot query
        // of a round with none and of a checklist or review round, which is loaded with none.
        $roundRow = $this->connection->fetchAssociative(
            'SELECT round.id, round.number, round.kind, round.owner_id, round.shared_at, round.state,'
            ." (round.kind IN ('report', 'draft') AND EXISTS (SELECT 1 FROM rvw_screenshot AS screenshot WHERE screenshot.organization_id = round.organization_id AND screenshot.round_id = round.id)) AS has_screenshots"
            .' FROM rvw_round AS round'
            .' WHERE round.organization_id = CAST(:organization AS uuid) AND round.ticket_id = CAST(:ticket AS uuid)'
            .' AND round.number = :number AND round.shared_at IS NOT NULL AND round.deleted_at IS NULL',
            ['organization' => $organizationId->toString(), 'ticket' => $ticketId->toString(), 'number' => $number],
        );
        if (false === $roundRow) {
            return null;
        }
        $roundId = self::text($roundRow['id'], 'id');
        $sharedAt = self::text($roundRow['shared_at'], 'shared_at');
        $roundIdsWithScreenshots = self::boolean($roundRow['has_screenshots'], 'has_screenshots') ? [$roundId] : [];

        return new RoundView(
            $roundId,
            self::integerOrNull($roundRow['number'], 'number'),
            self::text($roundRow['kind'], 'kind'),
            new UserId(self::text($roundRow['owner_id'], 'owner_id')),
            new \DateTimeImmutable($sharedAt),
            self::text($roundRow['state'], 'state'),
            $this->screenshotsOf($organizationId->toString(), $roundIdsWithScreenshots, [])[$roundId] ?? [],
        );
    }

    public function previousHandOffRoundOf(TicketId $ticketId, TicketVisibility $viewer, int $number): ?RoundView
    {
        $organizationId = $this->requireTransactionAndTenant();
        if (!$viewer->organizationId()->equals($organizationId)) {
            throw new \InvalidArgumentException('The ticket visibility given is not one of the tenant\'s organization.');
        }
        if ($number < 2 || [] === $this->ticketQueries->visibleTicketIdsOf($viewer, [$ticketId])) {
            return null;
        }

        // By rvw_round_ticket_id_number_key, walked down from $number; Round::isOpenSharedRound's kinds, open or closed.
        $roundRow = $this->connection->fetchAssociative(
            'SELECT round.id, round.number, round.kind, round.owner_id, round.shared_at, round.state FROM rvw_round AS round'
            .' WHERE round.organization_id = CAST(:organization AS uuid) AND round.ticket_id = CAST(:ticket AS uuid)'
            ." AND round.number < :number AND round.kind IN ('review', 'draft')"
            .' AND round.shared_at IS NOT NULL AND round.deleted_at IS NULL'
            .' ORDER BY round.number DESC LIMIT 1',
            ['organization' => $organizationId->toString(), 'ticket' => $ticketId->toString(), 'number' => $number],
        );
        if (false === $roundRow) {
            return null;
        }
        $roundId = self::text($roundRow['id'], 'id');

        return new RoundView(
            $roundId,
            self::integerOrNull($roundRow['number'], 'number'),
            self::text($roundRow['kind'], 'kind'),
            new UserId(self::text($roundRow['owner_id'], 'owner_id')),
            new \DateTimeImmutable(self::text($roundRow['shared_at'], 'shared_at')),
            self::text($roundRow['state'], 'state'),
            $this->screenshotsOf($organizationId->toString(), [$roundId], [])[$roundId] ?? [],
        );
    }

    public function lowestOpenSharedRoundOf(TicketId $ticketId): ?OpenSharedRound
    {
        $organizationId = $this->requireTransactionAndTenant();
        // Round::isOpenSharedRound's predicate, as DbalRounds::openSharedRoundsOf reads it, without its locks; by
        // rvw_round_ticket_id_number_key, which gives each shared round of a ticket its own number.
        $roundRow = $this->connection->fetchAssociative(
            'SELECT round.number, round.kind, round.owner_id, round.decision FROM rvw_round AS round'
            .' WHERE round.organization_id = CAST(:organization AS uuid) AND round.ticket_id = CAST(:ticket AS uuid)'
            ." AND round.kind IN ('review', 'draft') AND round.shared_at IS NOT NULL AND round.deleted_at IS NULL AND round.state = 'open'"
            .' ORDER BY round.number LIMIT 1',
            ['organization' => $organizationId->toString(), 'ticket' => $ticketId->toString()],
        );
        if (false === $roundRow) {
            return null;
        }

        return new OpenSharedRound(
            self::integer($roundRow['number'], 'number'),
            self::text($roundRow['kind'], 'kind'),
            new UserId(self::text($roundRow['owner_id'], 'owner_id')),
            DraftDecision::Shared->value === $roundRow['decision'],
        );
    }

    public function accessFactsOf(string $roundId): ?RoundAccessFacts
    {
        $organizationId = $this->requireTransactionAndTenant();
        try {
            $id = new RoundId($roundId);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $roundRow = $this->connection->fetchAssociative(
            "SELECT round.ticket_id, round.owner_id, (round.kind = 'draft' AND round.shared_at IS NULL) AS is_unshared_draft,"
            .' round.deleted_at IS NOT NULL AS is_deleted'
            .' FROM rvw_round AS round WHERE round.organization_id = CAST(:organization AS uuid) AND round.id = CAST(:round AS uuid)',
            ['organization' => $organizationId->toString(), 'round' => $id->toString()],
        );
        if (false === $roundRow) {
            return null;
        }

        return new RoundAccessFacts(
            $organizationId,
            $id->toString(),
            new TicketId(self::text($roundRow['ticket_id'], 'ticket_id')),
            new UserId(self::text($roundRow['owner_id'], 'owner_id')),
            self::boolean($roundRow['is_unshared_draft'], 'is_unshared_draft'),
            self::boolean($roundRow['is_deleted'], 'is_deleted'),
        );
    }

    public function draftOf(string $roundId, UserId $owner): ?RoundView
    {
        $organizationId = $this->requireTransactionAndTenant();
        try {
            $id = new RoundId($roundId);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $roundRow = $this->connection->fetchAssociative(
            'SELECT round.id, round.number, round.kind, round.owner_id, round.shared_at, round.state FROM rvw_round AS round'
            .' WHERE round.organization_id = CAST(:organization AS uuid) AND round.id = CAST(:round AS uuid)'
            ." AND round.owner_id = CAST(:owner AS uuid) AND round.kind = 'draft' AND round.deleted_at IS NULL",
            ['organization' => $organizationId->toString(), 'round' => $id->toString(), 'owner' => $owner->toString()],
        );
        if (false === $roundRow) {
            return null;
        }
        $draftId = self::text($roundRow['id'], 'id');
        $sharedAt = self::textOrNull($roundRow['shared_at'], 'shared_at');

        return new RoundView(
            $draftId,
            self::integerOrNull($roundRow['number'], 'number'),
            self::text($roundRow['kind'], 'kind'),
            new UserId(self::text($roundRow['owner_id'], 'owner_id')),
            null === $sharedAt ? null : new \DateTimeImmutable($sharedAt),
            self::text($roundRow['state'], 'state'),
            $this->screenshotsOf($organizationId->toString(), [$draftId], [$draftId])[$draftId] ?? [],
        );
    }

    public function draftScreenshotOf(TicketId $ticketId, string $screenshotId): ?RoundAccessFacts
    {
        $organizationId = $this->requireTransactionAndTenant();
        try {
            $id = new ScreenshotId($screenshotId);
        } catch (\InvalidArgumentException) {
            return null;
        }

        // Both tables by their primary key (organization_id, id). An earlier try of a shared draft is the owner's alone
        // (ADR-0048): not found, as an unknown picture is, since read-only would tell the team it exists.
        $roundRow = $this->connection->fetchAssociative(
            'SELECT round.id, round.owner_id, round.shared_at IS NULL AS is_unshared_draft, round.deleted_at IS NOT NULL AS is_deleted'
            .' FROM rvw_screenshot AS screenshot'
            .' JOIN rvw_round AS round ON round.organization_id = screenshot.organization_id AND round.id = screenshot.round_id'
            .' WHERE screenshot.organization_id = CAST(:organization AS uuid) AND screenshot.id = CAST(:screenshot AS uuid)'
            ." AND round.ticket_id = CAST(:ticket AS uuid) AND round.kind = 'draft'"
            .' AND (round.shared_at IS NULL OR '.self::latestTrySql('screenshot').')',
            ['organization' => $organizationId->toString(), 'screenshot' => $id->toString(), 'ticket' => $ticketId->toString()],
        );
        if (false === $roundRow) {
            return null;
        }

        return new RoundAccessFacts(
            $organizationId,
            self::text($roundRow['id'], 'id'),
            $ticketId,
            new UserId(self::text($roundRow['owner_id'], 'owner_id')),
            self::boolean($roundRow['is_unshared_draft'], 'is_unshared_draft'),
            self::boolean($roundRow['is_deleted'], 'is_deleted'),
        );
    }

    public function openDraftOf(TicketId $ticketId, UserId $owner): ?RoundAccessFacts
    {
        $organizationId = $this->requireTransactionAndTenant();
        // The predicate of rvw_round_ticket_id_owner_id_open_draft_key, which holds at most one such draft; a deleted
        // draft is closed (rvw_round_deleted_check).
        $draftId = $this->connection->fetchOne(
            'SELECT round.id FROM rvw_round AS round'
            .' WHERE round.organization_id = CAST(:organization AS uuid) AND round.ticket_id = CAST(:ticket AS uuid)'
            ." AND round.owner_id = CAST(:owner AS uuid) AND round.kind = 'draft' AND round.shared_at IS NULL AND round.state = 'open'",
            ['organization' => $organizationId->toString(), 'ticket' => $ticketId->toString(), 'owner' => $owner->toString()],
        );
        if (false === $draftId) {
            return null;
        }

        return new RoundAccessFacts($organizationId, self::text($draftId, 'id'), $ticketId, $owner, true, false);
    }

    public function openDraftScreenshotsOf(TicketId $ticketId, UserId $owner): ?OpenDraftScreenshots
    {
        $organizationId = $this->requireTransactionAndTenant();
        // One row per try, or one for a draft with no screenshot; the round conditions are openDraftOf's.
        $tryRows = $this->connection->fetchAllAssociative(
            'SELECT round.id AS round_id, screenshot.id, screenshot.media_id, screenshot.position, screenshot.title, screenshot.retake_of'
            .' FROM rvw_round AS round'
            .' LEFT JOIN rvw_screenshot AS screenshot ON screenshot.organization_id = round.organization_id AND screenshot.round_id = round.id'
            .self::FIRST_TRY_JOIN
            .' WHERE round.organization_id = CAST(:organization AS uuid) AND round.ticket_id = CAST(:ticket AS uuid)'
            ." AND round.owner_id = CAST(:owner AS uuid) AND round.kind = 'draft' AND round.shared_at IS NULL AND round.state = 'open'"
            .self::CHAIN_ORDER,
            ['organization' => $organizationId->toString(), 'ticket' => $ticketId->toString(), 'owner' => $owner->toString()],
        );
        if ([] === $tryRows) {
            return null;
        }

        $tries = [];
        foreach ($tryRows as $tryRow) {
            $tryId = self::textOrNull($tryRow['id'], 'id');
            if (null !== $tryId) {
                // Only what ScreenshotChains and the chain's fields read: no pins, verdict or summary is selected.
                $tries[] = new RoundScreenshotView(
                    $tryId,
                    self::text($tryRow['media_id'], 'media_id'),
                    self::integer($tryRow['position'], 'position'),
                    null,
                    null,
                    self::textOrNull($tryRow['title'], 'title'),
                    null,
                    [],
                    self::textOrNull($tryRow['retake_of'], 'retake_of'),
                );
            }
        }
        $chains = [];
        foreach (ScreenshotChains::of($tries) as $chainIndex => $chainTries) {
            $chains[] = new DraftScreenshotChain($chainTries[0]->id, $chainIndex + 1, $chainTries[\count($chainTries) - 1]->title, \count($chainTries));
        }

        return new OpenDraftScreenshots(
            new RoundAccessFacts($organizationId, self::text($tryRows[0]['round_id'], 'round_id'), $ticketId, $owner, true, false),
            $chains,
        );
    }

    public function roundNotes(TicketId $ticketId, UserId $owner): ?RoundNotes
    {
        $organizationId = $this->requireTransactionAndTenant();
        // One row per pin that counts, or per picture with none, or one for a draft with no picture; the round conditions are
        // those of rvw_round_ticket_id_owner_id_open_draft_key, which holds at most one such draft.
        $noteRows = $this->connection->fetchAllAssociative(
            'SELECT round.id AS round_id, '.self::NOTE_COLUMNS
            .' FROM rvw_round AS round'
            .self::NOTE_SCREENSHOT_JOIN
            .self::NOTE_PIN_JOIN
            .' WHERE round.organization_id = CAST(:organization AS uuid) AND round.ticket_id = CAST(:ticket AS uuid)'
            ." AND round.owner_id = CAST(:owner AS uuid) AND round.kind = 'draft' AND round.shared_at IS NULL AND round.state = 'open'"
            .' AND round.deleted_at IS NULL'
            .' ORDER BY screenshot.position, pin.number',
            ['organization' => $organizationId->toString(), 'ticket' => $ticketId->toString(), 'owner' => $owner->toString()],
        );
        if ([] === $noteRows) {
            return null;
        }

        return new RoundNotes(self::text($noteRows[0]['round_id'], 'round_id'), self::screenshotNotesOf($noteRows));
    }

    public function sharedRoundNotes(TicketId $ticketId): ?SharedRoundNotes
    {
        $organizationId = $this->requireTransactionAndTenant();
        // The newest is picked on (organization_id, ticket_id, number) before any picture is joined, so the answer holds
        // one round's rows; rvw_round_ticket_id_number_key gives each shared round of a ticket its own number.
        $noteRows = $this->connection->fetchAllAssociative(
            'SELECT round.id AS round_id, round.number AS round_number, round.kind, '.self::NOTE_COLUMNS
            .' FROM ('
            .'SELECT newest.organization_id, newest.id, newest.number, newest.kind FROM rvw_round AS newest'
            .' WHERE newest.organization_id = CAST(:organization AS uuid) AND newest.ticket_id = CAST(:ticket AS uuid)'
            ." AND newest.shared_at IS NOT NULL AND newest.kind IN ('report', 'draft') AND newest.deleted_at IS NULL"
            .' ORDER BY newest.number DESC LIMIT 1'
            .') AS round'
            .self::NOTE_SCREENSHOT_JOIN.' AND '.self::latestTrySql('screenshot')
            .self::NOTE_PIN_JOIN
            .' ORDER BY screenshot.position, pin.number',
            ['organization' => $organizationId->toString(), 'ticket' => $ticketId->toString()],
        );
        if ([] === $noteRows) {
            return null;
        }

        return new SharedRoundNotes(
            self::text($noteRows[0]['round_id'], 'round_id'),
            self::integer($noteRows[0]['round_number'], 'round_number'),
            self::text($noteRows[0]['kind'], 'kind'),
            self::screenshotNotesOf($noteRows),
        );
    }

    /**
     * @param list<array<string, mixed>> $noteRows one per pin, or per picture with no pin that counts, or one for a
     *                                             round with no picture; by position, then pin number
     *
     * @return list<ScreenshotNotes> by position
     */
    private static function screenshotNotesOf(array $noteRows): array
    {
        /** @var array<string, array{int, ?string, ?string, ?string, list<PinNote>, string}> $screenshotFields by screenshot id */
        $screenshotFields = [];
        foreach ($noteRows as $noteRow) {
            $screenshotId = self::textOrNull($noteRow['screenshot_id'], 'screenshot_id');
            if (null === $screenshotId) {
                continue;
            }
            $screenshotFields[$screenshotId] ??= [
                self::integer($noteRow['position'], 'position'),
                self::textOrNull($noteRow['title'], 'title'),
                self::textOrNull($noteRow['summary'], 'summary'),
                self::textOrNull($noteRow['verdict'], 'verdict'),
                [],
                self::text($noteRow['media_id'], 'media_id'),
            ];
            $pinNumber = self::integerOrNull($noteRow['pin_number'], 'pin_number');
            if (null !== $pinNumber) {
                $screenshotFields[$screenshotId][4][] = new PinNote(
                    $pinNumber,
                    (float) self::text($noteRow['x_pct'], 'x_pct'),
                    (float) self::text($noteRow['y_pct'], 'y_pct'),
                    self::text($noteRow['note'], 'note'),
                );
            }
        }

        $screenshots = [];
        foreach ($screenshotFields as $screenshotId => [$position, $title, $summary, $verdict, $pins, $mediaId]) {
            $screenshots[] = new ScreenshotNotes($screenshotId, $position, $title, $summary, $verdict, $pins, $mediaId);
        }

        return $screenshots;
    }

    private function requireTransactionAndTenant(): OrganizationId
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Rounds are read in a transaction under the organization as tenant; none is open.');
        }

        return $this->tenantContext->require();
    }

    /**
     * @param bool $loadsSharedDraftScreenshots false for the ticket page, which shows a shared draft as a row with its
     *                                          count: then only the report and the viewer's unshared draft are loaded
     *                                          with their screenshots
     */
    private function roundsOf(OrganizationId $organizationId, TicketId $ticketId, TicketVisibility $visibility, bool $loadsSharedDraftScreenshots): TicketRounds
    {
        $viewer = $visibility->viewerId();
        if ([] === $this->ticketQueries->visibleTicketIdsOf($visibility, [$ticketId])) {
            return new TicketRounds([]);
        }

        // screenshot_count is computed after the cap, so it costs nothing for a round the cap drops; it counts what the
        // team sees of a shared round (latestTrySql), and the chains of an unshared draft.
        // A round with no number is an unshared draft; only its owner gets it, after every shared round. Round 0 and the
        // draft are never capped: the numbered rounds (1 and up) are ranked newest first among themselves and only the
        // newest MAX_NUMBERED_ROUNDS go on, with the size of the numbered group to tell how many were left out.
        $roundRows = $this->connection->fetchAllAssociative(
            'SELECT listed.id, listed.number, listed.kind, listed.owner_id, listed.shared_at, listed.state, listed.group_size,'
            .' (SELECT COUNT(*) FROM rvw_screenshot AS counted WHERE counted.organization_id = listed.organization_id AND counted.round_id = listed.id'
            .' AND '.self::latestTrySql('counted').') AS screenshot_count'
            .' FROM ('
            .'SELECT round.organization_id, round.id, round.number, round.kind, round.owner_id, round.shared_at, round.state,'
            .' ROW_NUMBER() OVER (PARTITION BY (round.number >= 1) ORDER BY round.number DESC) AS newest_rank,'
            .' COUNT(*) OVER (PARTITION BY (round.number >= 1)) AS group_size'
            .' FROM rvw_round AS round'
            .' WHERE round.organization_id = CAST(:organization AS uuid) AND round.ticket_id = CAST(:ticket AS uuid)'
            .' AND (round.shared_at IS NOT NULL OR round.owner_id = CAST(:viewer AS uuid)) AND round.deleted_at IS NULL'
            .') AS listed'
            .' WHERE listed.number IS NULL OR listed.number = 0 OR listed.newest_rank <= :cap'
            .' ORDER BY listed.number NULLS LAST, listed.id',
            ['organization' => $organizationId->toString(), 'ticket' => $ticketId->toString(), 'viewer' => $viewer->toString(), 'cap' => self::MAX_NUMBERED_ROUNDS],
        );
        $olderRoundsNotShown = 0;
        $roundIdsWithScreenshots = [];
        $unsharedDraftIds = [];
        foreach ($roundRows as $roundRow) {
            $roundId = self::text($roundRow['id'], 'id');
            $kind = self::text($roundRow['kind'], 'kind');
            $isUnsharedDraft = null === $roundRow['shared_at'];
            // A checklist or review round is a row on the page and loads no screenshot or pin.
            $loadsScreenshots = 'report' === $kind || (RoundView::DRAFT === $kind && ($isUnsharedDraft || $loadsSharedDraftScreenshots));
            if ($loadsScreenshots && self::integer($roundRow['screenshot_count'], 'screenshot_count') > 0) {
                $roundIdsWithScreenshots[] = $roundId;
            }
            if ($isUnsharedDraft) {
                $unsharedDraftIds[] = $roundId;
            }
            if ((self::integerOrNull($roundRow['number'], 'number') ?? 0) >= 1) {
                $olderRoundsNotShown = max(0, self::integer($roundRow['group_size'], 'group_size') - self::MAX_NUMBERED_ROUNDS);
            }
        }
        // The viewer's own unshared draft shows every try; a shared round shows the latest of each, to its owner too, as
        // the card shows what the team sees.
        $screenshotsByRound = $this->screenshotsOf($organizationId->toString(), $roundIdsWithScreenshots, $unsharedDraftIds);

        $rounds = array_map(static function (array $roundRow) use ($screenshotsByRound): RoundView {
            $roundId = self::text($roundRow['id'], 'id');
            $sharedAt = self::textOrNull($roundRow['shared_at'], 'shared_at');

            return new RoundView(
                $roundId,
                self::integerOrNull($roundRow['number'], 'number'),
                self::text($roundRow['kind'], 'kind'),
                new UserId(self::text($roundRow['owner_id'], 'owner_id')),
                null === $sharedAt ? null : new \DateTimeImmutable($sharedAt),
                self::text($roundRow['state'], 'state'),
                $screenshotsByRound[$roundId] ?? [],
                self::integer($roundRow['screenshot_count'], 'screenshot_count'),
            );
        }, $roundRows);

        return new TicketRounds($rounds, $olderRoundsNotShown);
    }

    /**
     * @param list<string> $roundIds
     * @param list<string> $roundIdsWithEveryTry those of $roundIds the owner reads, whose earlier tries come back too;
     *                                           the other rounds give only the latest try of each screenshot
     *
     * @return array<string, list<RoundScreenshotView>> by round id, each by its chain's first try's position, then by
     *                                                  position
     */
    private function screenshotsOf(string $organizationId, array $roundIds, array $roundIdsWithEveryTry): array
    {
        if ([] === $roundIds) {
            return [];
        }
        // pdo_pgsql binds no arrays, so the ids travel as one uuid[] literal; a uuid holds no comma or brace.
        $screenshotRows = $this->connection->fetchAllAssociative(
            'SELECT screenshot.id, screenshot.round_id, screenshot.media_id, screenshot.position, screenshot.variant_label,'
            .' screenshot.verdict, screenshot.title, screenshot.summary, screenshot.retake_of,'
            .' EXISTS (SELECT 1 FROM rvw_pin AS pin WHERE pin.organization_id = screenshot.organization_id AND pin.screenshot_id = screenshot.id) AS has_pins'
            .' FROM rvw_screenshot AS screenshot'
            .self::FIRST_TRY_JOIN
            .' WHERE screenshot.organization_id = CAST(:organization AS uuid) AND screenshot.round_id = ANY(CAST(:rounds AS uuid[]))'
            .' AND (screenshot.round_id = ANY(CAST(:roundsWithEveryTry AS uuid[])) OR '.self::latestTrySql('screenshot').')'
            .self::CHAIN_ORDER,
            [
                'organization' => $organizationId,
                'rounds' => '{'.implode(',', $roundIds).'}',
                'roundsWithEveryTry' => '{'.implode(',', $roundIdsWithEveryTry).'}',
            ],
        );
        $screenshotIdsWithPins = [];
        foreach ($screenshotRows as $screenshotRow) {
            if (self::boolean($screenshotRow['has_pins'], 'has_pins')) {
                $screenshotIdsWithPins[] = self::text($screenshotRow['id'], 'id');
            }
        }
        $pinsByScreenshot = $this->pinsOf($organizationId, $screenshotIdsWithPins);

        $screenshotsByRound = [];
        foreach ($screenshotRows as $screenshotRow) {
            $screenshotId = self::text($screenshotRow['id'], 'id');
            $screenshotsByRound[self::text($screenshotRow['round_id'], 'round_id')][] = new RoundScreenshotView(
                $screenshotId,
                self::text($screenshotRow['media_id'], 'media_id'),
                self::integer($screenshotRow['position'], 'position'),
                self::textOrNull($screenshotRow['variant_label'], 'variant_label'),
                self::textOrNull($screenshotRow['verdict'], 'verdict'),
                self::textOrNull($screenshotRow['title'], 'title'),
                self::textOrNull($screenshotRow['summary'], 'summary'),
                $pinsByScreenshot[$screenshotId] ?? [],
                self::textOrNull($screenshotRow['retake_of'], 'retake_of'),
            );
        }

        return $screenshotsByRound;
    }

    /**
     * @param list<string> $screenshotIds
     *
     * @return array<string, list<RoundPinView>> by screenshot id, each by number
     */
    private function pinsOf(string $organizationId, array $screenshotIds): array
    {
        if ([] === $screenshotIds) {
            return [];
        }
        $pinRows = $this->connection->fetchAllAssociative(
            'SELECT pin.id, pin.screenshot_id, pin.number, pin.x_pct, pin.y_pct, pin.note, pin.verdict, pin.source, pin.ai_state, pin.author_id, pin.fixed_on_try'
            .' FROM rvw_pin AS pin'
            .' WHERE pin.organization_id = CAST(:organization AS uuid) AND pin.screenshot_id = ANY(CAST(:screenshots AS uuid[]))'
            .' ORDER BY pin.number, pin.id',
            ['organization' => $organizationId, 'screenshots' => '{'.implode(',', $screenshotIds).'}'],
        );
        $pinsByScreenshot = [];
        foreach ($pinRows as $pinRow) {
            $pinsByScreenshot[self::text($pinRow['screenshot_id'], 'screenshot_id')][] = new RoundPinView(
                self::text($pinRow['id'], 'id'),
                self::integer($pinRow['number'], 'number'),
                // NUMERIC(5, 2) comes back as its decimal text.
                (float) self::text($pinRow['x_pct'], 'x_pct'),
                (float) self::text($pinRow['y_pct'], 'y_pct'),
                self::text($pinRow['note'], 'note'),
                self::textOrNull($pinRow['verdict'], 'verdict'),
                self::text($pinRow['source'], 'source'),
                self::textOrNull($pinRow['ai_state'], 'ai_state'),
                new UserId(self::text($pinRow['author_id'], 'author_id')),
                self::integerOrNull($pinRow['fixed_on_try'], 'fixed_on_try'),
            );
        }

        return $pinsByScreenshot;
    }

    private static function text(mixed $value, string $column): string
    {
        if (!\is_string($value)) {
            throw new \LogicException(\sprintf('A round %s from the database is not a string.', $column));
        }

        return $value;
    }

    private static function textOrNull(mixed $value, string $column): ?string
    {
        return null === $value ? null : self::text($value, $column);
    }

    private static function integer(mixed $value, string $column): int
    {
        if (!\is_int($value)) {
            throw new \LogicException(\sprintf('A round %s from the database is not an integer.', $column));
        }

        return $value;
    }

    private static function integerOrNull(mixed $value, string $column): ?int
    {
        return null === $value ? null : self::integer($value, $column);
    }

    private static function boolean(mixed $value, string $column): bool
    {
        if (!\is_bool($value)) {
            throw new \LogicException(\sprintf('A round %s from the database is not a boolean.', $column));
        }

        return $value;
    }
}
