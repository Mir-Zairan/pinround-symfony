<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Changes to the tickets of the current organization. Each command runs in the caller's transaction, begun under the
 * organization as tenant, and writes the ticket, its history row, its turn row and its outbox event there, so a
 * rollback leaves none of them. Who may run it is the voter's question, asked by the caller.
 */
interface TicketCommands
{
    /**
     * Reports a ticket in a project that a team owns. It takes the project's next number, so the project's row stays
     * locked until the caller's transaction ends. It starts in the team's To do step when it has an assignee, else in
     * Backlog, and it is visible to everyone in the organization.
     *
     * @param string $projectId a project's uuid, as the caller may not build Ticketing's ProjectId
     * @param string $type      bug, story or task
     * @param string $priority  blocker, critical, major, normal or minor
     *
     * @return string the new ticket's key, for example "SHOP-12"
     *
     * @throws \DomainException          when the organization has no project with that id, or no team owns the project;
     *                                   nothing is saved
     * @throws \InvalidArgumentException when the title is blank or over 200 characters, the description over 50,000
     *                                   characters, or the type or priority unknown; the number taken is given back only
     *                                   when the caller rolls back
     * @throws \LogicException           when no transaction is open, or the team's steps lack Backlog or To do
     */
    public function create(
        OrganizationId $organizationId,
        UserId $reporter,
        string $projectId,
        string $title,
        string $description,
        string $type,
        string $priority,
        ?UserId $assignee,
        \DateTimeImmutable $reportedAt,
    ): string;
}
