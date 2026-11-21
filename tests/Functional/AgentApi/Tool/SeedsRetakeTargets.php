<?php

declare(strict_types=1);

namespace App\Tests\Functional\AgentApi\Tool;

use App\SharedKernel\Domain\OrganizationId;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Seeds the screenshots a retake_of of add_screenshot names, through the migrator, committed: later tries of an Acme
 * Foods chain, and a screenshot in a draft round of another organization. The using test seeds Acme Foods' rounds
 * (SeedsTicketRounds) and removes the rows with removeSeededRounds and removeCommittedProjects in its tearDown.
 */
trait SeedsRetakeTargets
{
    /**
     * A later try of $firstTryId's chain, as Review stores it: pointing at the chain's first try.
     *
     * @return string the try's id
     */
    private function addRetake(string $roundId, string $firstTryId, int $position, string $owner, ?string $title = null): string
    {
        $screenshotId = Uuid::v7()->toRfc4122();
        $parameters = [$this->acmeFoods->toString(), $screenshotId, $roundId, $this->addMedia('ready', $owner), $position, $firstTryId, $title];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO rvw_screenshot (organization_id, id, round_id, media_id, position, retake_of, title, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, '2026-11-05 08:30:00+00')",
            $parameters,
        ));

        return $screenshotId;
    }

    /**
     * A ticket of $organizationId's own SHOP project with an unshared draft of $owner holding one ready picture.
     *
     * @return string the screenshot's id
     */
    private function addOtherOrganizationsDraftScreenshot(OrganizationId $organizationId, string $owner): string
    {
        $projectId = $this->addProject(null, 'SHOP', 'Shop', $organizationId);
        $organization = $organizationId->toString();
        $ownerId = $this->people[$owner]->id()->toString();
        [$stepId, $ticketId, $roundId, $mediaId, $screenshotId] = [Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122(), Uuid::v7()->toRfc4122()];
        $this->committedAs($organizationId, static function (Connection $migrator) use ($organization, $projectId, $ownerId, $stepId, $ticketId, $roundId, $mediaId, $screenshotId): int|string {
            $migrator->executeStatement(
                "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'To do', 'todo', 'todo', 'core', 1)",
                [$organization, $stepId],
            );
            $migrator->executeStatement(
                "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                     step_id, reporter_id, assignee_id, visibility, version, components, check_reasons, created_at, updated_at)
                 VALUES (?, ?, ?, NULL, 1, 'SHOP-1', 'Bread prices are rounded', '', 'bug', 'major', 2, ?, ?, ?, 'all', 3, '{}', '{}',
                     TIMESTAMPTZ '2026-11-04 10:00:00+00', TIMESTAMPTZ '2026-11-05 10:00:00+00')",
                [$organization, $ticketId, $projectId, $stepId, $ownerId, $ownerId],
            );
            $migrator->executeStatement(
                "INSERT INTO rvw_round (organization_id, id, ticket_id, number, kind, owner_id, shared_at, state, version, created_at)
                 VALUES (?, ?, ?, NULL, 'draft', ?, NULL, 'open', 1, TIMESTAMPTZ '2026-11-05 10:00:00+00')",
                [$organization, $roundId, $ticketId, $ownerId],
            );
            $migrator->executeStatement(
                "INSERT INTO med_object (organization_id, id, storage_key, mime, bytes, uploaded_by, created_at, status, sha256, width, height, variants)
                 VALUES (?, ?, ?, 'image/png', 2048, ?, TIMESTAMPTZ '2026-11-05 10:00:00+00', 'ready', ?, 1280, 800, CAST(? AS jsonb))",
                [$organization, $mediaId, 'uploads/'.$mediaId, $ownerId, hash('sha256', $mediaId), json_encode(['view' => 'media/'.$mediaId.'/view.webp', 'thumb' => 'media/'.$mediaId.'/thumb.webp'], \JSON_THROW_ON_ERROR)],
            );

            return $migrator->executeStatement(
                "INSERT INTO rvw_screenshot (organization_id, id, round_id, media_id, position, title, created_at)
                 VALUES (?, ?, ?, ?, 0, 'Bread shelf', TIMESTAMPTZ '2026-11-05 10:00:00+00')",
                [$organization, $screenshotId, $roundId, $mediaId],
            );
        });

        return $screenshotId;
    }
}
