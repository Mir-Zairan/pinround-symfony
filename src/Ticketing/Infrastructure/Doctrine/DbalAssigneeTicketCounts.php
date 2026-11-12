<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantContext;
use App\Ticketing\Api\AssigneeTicketCounts;
use App\Ticketing\Api\ListFilter;
use App\Ticketing\Api\TicketListGroup;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilitySql;
use App\Workflow\Api\StepQueries;
use Doctrine\DBAL\Connection;

/**
 * One grouped count over tkt_ticket by assignee, filtered by the open steps, the project when there is one and the
 * visibility predicate.
 */
final readonly class DbalAssigneeTicketCounts implements AssigneeTicketCounts
{
    private const string TICKET_ALIAS = 'ticket';

    public function __construct(private Connection $connection, private TenantContext $tenantContext, private StepQueries $stepQueries)
    {
    }

    public function openTicketsAssignedTo(TicketVisibility $viewer, array $assigneeIds, ?string $projectId): array
    {
        if (null !== $projectId && !ListFilter::isCanonicalUuid($projectId)) {
            throw new \InvalidArgumentException('Open tickets are counted in a project given by its canonical lowercase uuid.');
        }
        $counts = [];
        foreach ($assigneeIds as $assigneeId) {
            $counts[$assigneeId->toString()] = 0;
        }
        if ([] === $counts) {
            return [];
        }
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Open tickets are counted in a transaction under the organization as tenant; none is open.');
        }
        $organizationId = $this->tenantContext->require();
        if (!$organizationId->equals($viewer->organizationId())) {
            throw new \LogicException('Open tickets are counted under the viewer\'s own organization as tenant.');
        }

        $openStepIds = [];
        foreach ($this->stepQueries->listedSteps() as $step) {
            if (TicketListGroup::DONE !== $step->groupCode) {
                $openStepIds[] = $step->id;
            }
        }
        if ([] === $openStepIds) {
            return $counts;
        }

        $visibleTicket = TicketVisibilitySql::of($viewer, self::TICKET_ALIAS);
        // Array literals keep the SQL text the same for any number of ids; user and step ids are uuids, so need no quoting.
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT ticket.assignee_id, count(*) FROM tkt_ticket AS ticket'
            .' WHERE ticket.organization_id = CAST(:organization AS uuid)'
            .' AND ticket.assignee_id = ANY(CAST(:assignees AS uuid[]))'
            .' AND ticket.step_id = ANY(CAST(:open_steps AS uuid[]))'
            .(null === $projectId ? '' : ' AND ticket.project_id = CAST(:project AS uuid)')
            .' AND '.$visibleTicket->sql()
            .' GROUP BY ticket.assignee_id',
            [
                'organization' => $organizationId->toString(),
                ...(null === $projectId ? [] : ['project' => $projectId]),
                'assignees' => '{'.implode(',', array_keys($counts)).'}',
                'open_steps' => '{'.implode(',', $openStepIds).'}',
                ...$visibleTicket->parameters(),
            ],
        );
        foreach ($rows as $assigneeId => $openTickets) {
            if (!\is_int($openTickets) || !\array_key_exists($assigneeId, $counts)) {
                throw new \LogicException('An assignee\'s ticket count from the database is not an integer of a given person.');
            }
            $counts[$assigneeId] = $openTickets;
        }

        return $counts;
    }
}
