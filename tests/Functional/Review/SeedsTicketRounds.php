<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Seeds Acme Foods' SHOP tickets and their rounds, screenshots, pins and pictures through the migrator, committed, for
 * the functional tests of the Rounds section. The using test creates the people (CreatesAcmeFoodsTeams), the SHOP
 * project ($shopProject) and the To do step ($stepId) in its setUp, and removes the rows with removeSeededRounds in its
 * tearDown.
 */
trait SeedsTicketRounds
{
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

    private function addPin(string $screenshotId, int $number, string $x, string $y, string $note, string $author, string $source = 'human', ?string $aiState = null): void
    {
        $parameters = [$this->acmeFoods->toString(), Uuid::v7()->toRfc4122(), $screenshotId, $number, $x, $y, $note, $source, $aiState, $this->people[$author]->id()->toString()];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO rvw_pin (organization_id, id, screenshot_id, number, x_pct, y_pct, note, source, ai_state, author_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '2026-11-05 08:00:00+00')",
            $parameters,
        ));
    }

    /**
     * @param 'ready'|'pending'|'rejected' $status
     *
     * @return string the media id
     */
    private function addMedia(string $status): string
    {
        $mediaId = Uuid::v7()->toRfc4122();
        $parameters = [
            $this->acmeFoods->toString(), $mediaId, 'uploads/'.$mediaId, $this->people['Mia']->id()->toString(), $status,
            'rejected' === $status ? 'The file is not a picture.' : null,
            'ready' === $status ? hash('sha256', $mediaId) : null,
            'ready' === $status ? 1280 : null,
            'ready' === $status ? 800 : null,
            'ready' === $status ? json_encode(['view' => 'media/'.$mediaId.'/view.webp', 'thumb' => 'media/'.$mediaId.'/thumb.webp'], \JSON_THROW_ON_ERROR) : null,
        ];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO med_object (organization_id, id, storage_key, mime, bytes, uploaded_by, created_at, status, reject_reason, sha256, width, height, variants)
             VALUES (?, ?, ?, 'image/png', 2048, ?, '2026-11-05 08:00:00+00', ?, ?, ?, ?, ?, CAST(? AS jsonb))",
            $parameters,
        ));

        return $mediaId;
    }
}
