<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Application\Gateway;

use App\SharedKernel\Domain\OrganizationId;
use App\Ticketing\Api\IntakeCandidate;
use App\Ticketing\Api\IntakeCandidateQueries;
use App\Ticketing\Api\ListFilter;
use App\Ticketing\Api\TicketVisibility;

/**
 * One organization's open tickets, newest updated first, as DbalIntakeCandidateQueries reads them: only the project's
 * tickets the reporter may see, team-only ones only when asked, at most CANDIDATE_LIMIT; a project id that is not a
 * canonical uuid or a reporter of another organization throws before anything is read.
 */
final class ListedIntakeCandidates implements IntakeCandidateQueries
{
    /** @var list<array{projectId: string, candidate: IntakeCandidate, isTeamOnly: bool, viewerIds: list<string>}> */
    private array $tickets = [];

    /** @var list<array{projectId: string, viewerId: string, includeTeamOnly: bool}> */
    public array $reads = [];

    public function __construct(private readonly OrganizationId $organizationId)
    {
    }

    /**
     * Each ticket added is updated later than the ones before it, so it comes first.
     *
     * @param list<string> $viewerIds the users who may see it
     */
    public function add(string $projectId, IntakeCandidate $candidate, bool $isTeamOnly, array $viewerIds): void
    {
        array_unshift($this->tickets, ['projectId' => $projectId, 'candidate' => $candidate, 'isTeamOnly' => $isTeamOnly, 'viewerIds' => $viewerIds]);
    }

    public function openCandidatesIn(string $projectId, TicketVisibility $reporter, bool $includeTeamOnly): array
    {
        if (!ListFilter::isCanonicalUuid($projectId)) {
            throw new \InvalidArgumentException('An intake candidate\'s project id must be a canonical lowercase uuid.');
        }
        if (!$reporter->organizationId()->equals($this->organizationId)) {
            throw new \LogicException('Intake candidates are read under the reporter\'s own organization as tenant.');
        }
        $this->reads[] = ['projectId' => $projectId, 'viewerId' => $reporter->viewerId()->toString(), 'includeTeamOnly' => $includeTeamOnly];

        $candidates = [];
        foreach ($this->tickets as $ticket) {
            if ($ticket['projectId'] === $projectId
                && ($includeTeamOnly || !$ticket['isTeamOnly'])
                && \in_array($reporter->viewerId()->toString(), $ticket['viewerIds'], true)) {
                $candidates[] = $ticket['candidate'];
            }
        }

        return \array_slice($candidates, 0, self::CANDIDATE_LIMIT);
    }
}
