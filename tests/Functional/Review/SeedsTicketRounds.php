<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Uid\Uuid;

/**
 * Seeds Acme Foods' SHOP tickets and their rounds, screenshots, pins and pictures through the migrator, committed, for
 * the functional tests of the Rounds section. The using test creates the people (CreatesAcmeFoodsTeams), the SHOP
 * project ($shopProject) and the To do step ($stepId) in its setUp, and removes the rows with removeSeededRounds in its
 * tearDown.
 */
trait SeedsTicketRounds
{
    use SeedsMediaObjects;

    /**
     * Through the migrator, as the app may not delete rounds or tickets.
     */
    private function removeSeededRounds(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (['rvw_round', 'med_object', 'tkt_ticket_event', 'ins_ticket_turn', 'ins_project_count_ticket', 'ins_project_counts', 'tkt_ticket', 'wf_step'] as $table) {
                    $migrator->executeStatement(\sprintf('DELETE FROM %s WHERE organization_id = ?', $table), [$organizationId]);
                }

                return 0;
            });
        }
    }

    /**
     * @return string the ticket's id
     */
    private function addTicket(string $key, string $visibility = 'all', int $number = 1): string
    {
        $ticketId = Uuid::v7()->toRfc4122();
        $parameters = [
            $this->acmeFoods->toString(), $ticketId, $this->shopProject, $this->shop->toString(), $number, $key, $this->stepId,
            $this->people['Mia']->id()->toString(), $this->people['Omar']->id()->toString(), $visibility,
        ];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                 step_id, reporter_id, assignee_id, visibility, version, components, check_reasons, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 'Cart total shows the wrong sum', '', 'bug', 'major', 2, ?, ?, ?, ?, 3, '{}', '{}',
                 TIMESTAMPTZ '2026-11-04 10:00:00+00', TIMESTAMPTZ '2026-11-05 10:00:00+00')",
            $parameters,
        ));

        return $ticketId;
    }

    /**
     * @param int|null $number null for an unshared draft
     *
     * @return string the round's id
     */
    private function addRound(string $ticketId, ?int $number, string $kind, string $owner, ?string $sharedAt): string
    {
        $roundId = Uuid::v7()->toRfc4122();
        $parameters = [$this->acmeFoods->toString(), $roundId, $ticketId, $number, $kind, $this->people[$owner]->id()->toString(), $sharedAt];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO rvw_round (organization_id, id, ticket_id, number, kind, owner_id, shared_at, state, version, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'open', 1, '2026-11-05 08:00:00+00')",
            $parameters,
        ));

        return $roundId;
    }

    /**
     * @return string the screenshot's id
     */
    private function addScreenshot(string $roundId, string $mediaId, int $position, ?string $title, ?string $summary, ?string $verdict = null): string
    {
        $screenshotId = Uuid::v7()->toRfc4122();
        $parameters = [$this->acmeFoods->toString(), $screenshotId, $roundId, $mediaId, $position, $verdict, $title, $summary];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO rvw_screenshot (organization_id, id, round_id, media_id, position, verdict, title, summary, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, '2026-11-05 08:00:00+00')",
            $parameters,
        ));

        return $screenshotId;
    }

    /**
     * @return string the pin's id
     */
    private function addPin(string $screenshotId, int $number, string $x, string $y, string $note, string $author, string $source = 'human', ?string $aiState = null): string
    {
        $pinId = Uuid::v7()->toRfc4122();
        $parameters = [$this->acmeFoods->toString(), $pinId, $screenshotId, $number, $x, $y, $note, $source, $aiState, $this->people[$author]->id()->toString()];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO rvw_pin (organization_id, id, screenshot_id, number, x_pct, y_pct, note, source, ai_state, author_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '2026-11-05 08:00:00+00')",
            $parameters,
        ));

        return $pinId;
    }

    /**
     * An item of a checklist round for a pin of the ticket's report; an item past "open" was last changed by Omar.
     *
     * @param 'open'|'fixed'|'ok'|'not_fixed' $state
     *
     * @return string the item's id
     */
    private function addCheckItem(string $checklistRoundId, string $pinId, string $state): string
    {
        $itemId = Uuid::v7()->toRfc4122();
        $isChanged = 'open' !== $state;
        $parameters = [
            $this->acmeFoods->toString(), $itemId, $checklistRoundId, $pinId, $state,
            $isChanged ? $this->people['Omar']->id()->toString() : null,
            $isChanged ? '2026-11-05 10:00:00+00' : null,
        ];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO rvw_check_item (organization_id, id, round_id, pin_id, state, changed_by, changed_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, '2026-11-05 09:00:00+00')",
            $parameters,
        ));

        return $itemId;
    }

    private function setPinVerdict(string $screenshotId, int $number, string $verdict): void
    {
        $organizationId = $this->acmeFoods->toString();
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'UPDATE rvw_pin SET verdict = ? WHERE organization_id = ? AND screenshot_id = ? AND number = ?',
            [$verdict, $organizationId, $screenshotId, $number],
        ));
    }

    /**
     * A screen area of a review round; a verdict given is Mia's.
     *
     * @return string the area's id
     */
    private function addArea(string $roundId, string $name, int $position, string $beforeId, string $afterId, ?string $verdict, ?string $note): string
    {
        $areaId = Uuid::v7()->toRfc4122();
        $decidedBy = null === $verdict ? null : $this->people['Mia']->id()->toString();
        $parameters = [$this->acmeFoods->toString(), $areaId, $roundId, $name, $position, $beforeId, $afterId, $verdict, $note, $decidedBy, null === $verdict ? null : '2026-11-06 10:00:00+00'];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO rvw_area (organization_id, id, round_id, name, position, before_screenshot_id, after_screenshot_id, verdict, note, decided_by, decided_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '2026-11-05 08:00:00+00')",
            $parameters,
        ));

        return $areaId;
    }

    /**
     * Posts one DraftRoundEditor live action with the props of the owner's rendered editor (data-live-props-value), so
     * only the round's voter stands between the logged-in person and the draft.
     *
     * @param array<string, mixed> $args
     */
    private function postLiveAction(KernelBrowser $client, string $props, string $action, array $args): void
    {
        $client->request(
            'POST',
            '/_components/DraftRoundEditor/'.$action,
            ['data' => json_encode(['props' => json_decode($props, true), 'updated' => [], 'args' => $args], \JSON_THROW_ON_ERROR)],
            [],
            ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/vnd.live-component+html'],
        );
    }

    /**
     * @return list<array<string, mixed>> the round's screenshots as stored
     */
    private function screenshotRowsOf(string $roundId): array
    {
        $rows = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($roundId, &$rows): int {
            $rows = $migrator->fetchAllAssociative('SELECT id, position, verdict, title, summary FROM rvw_screenshot WHERE round_id = ? ORDER BY position', [$roundId]);

            return \count($rows);
        });

        return $rows;
    }

    /**
     * @return list<array<string, mixed>> the round's pins as stored, by screenshot position then number
     */
    private function pinRowsOf(string $roundId): array
    {
        $rows = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($roundId, &$rows): int {
            $rows = $migrator->fetchAllAssociative(
                'SELECT p.screenshot_id, p.number, p.x_pct, p.y_pct, p.note FROM rvw_pin p JOIN rvw_screenshot s ON s.organization_id = p.organization_id AND s.id = p.screenshot_id WHERE s.round_id = ? ORDER BY s.position, p.number',
                [$roundId],
            );

            return \count($rows);
        });

        return $rows;
    }

    /**
     * @return list<array<string, mixed>> the ticket's rounds as stored, oldest first
     */
    private function roundRowsOf(string $ticketId): array
    {
        $rows = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($ticketId, &$rows): int {
            $rows = $migrator->fetchAllAssociative(
                'SELECT id, kind, owner_id, number, shared_at, state, version, decision, decided_at, deleted_at FROM rvw_round WHERE ticket_id = ? ORDER BY created_at, id',
                [$ticketId],
            );

            return \count($rows);
        });

        return $rows;
    }
}
