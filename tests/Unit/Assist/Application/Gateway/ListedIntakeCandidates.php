<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Application\Gateway;

use App\SharedKernel\Domain\OrganizationId;
use App\Ticketing\Api\IntakeCandidate;
use App\Ticketing\Api\IntakeCandidateQueries;
use App\Ticketing\Api\ListFilter;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilityFacts;

/**
 * One organization's open tickets, newest updated first, as DbalIntakeCandidateQueries reads them: only the project's
 * tickets the reporter's TicketVisibility lets them see (TicketVisibility::isVisible is the PHP twin of the SQL it
 * renders), team-only ones only when asked, at most CANDIDATE_LIMIT; a project id that is not a canonical uuid or a
 * reporter of another organization throws before anything is read.
 */
final class ListedIntakeCandidates implements IntakeCandidateQueries
{
    /** @var list<array{projectId: string, candidate: IntakeCandidate, facts: TicketVisibilityFacts}> */
    private array $tickets = [];

    /** @var list<array{projectId: string, viewerId: string, includeTeamOnly: bool}> */
    public array $reads = [];

    public function __construct(private readonly OrganizationId $organizationId)
    {
    }

    /**
     * Each ticket added is updated later than the ones before it, so it comes first.
     */
    public function add(string $projectId, IntakeCandidate $candidate, TicketVisibilityFacts $facts): void
    {
        array_unshift($this->tickets, ['projectId' => $projectId, 'candidate' => $candidate, 'facts' => $facts]);
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
                && ($includeTeamOnly || TicketVisibilityFacts::FOR_EVERYONE === $ticket['facts']->visibility)
                && $reporter->isVisible($ticket['facts'])) {
                $candidates[] = $ticket['candidate'];
            }
        }

        return \array_slice($candidates, 0, self::CANDIDATE_LIMIT);
    }

    public function openCandidatesByKeys(TicketVisibility $reporter, array $keys): array
    {
        if (\count($keys) > self::CANDIDATE_LIMIT) {
            throw new \InvalidArgumentException(\sprintf('At most %d intake candidates are read by key at once.', self::CANDIDATE_LIMIT));
        }
        if (!$reporter->organizationId()->equals($this->organizationId)) {
            throw new \LogicException('Intake candidates are read under the reporter\'s own organization as tenant.');
        }

        $candidates = [];
        foreach (array_unique($keys) as $key) {
            foreach ($this->tickets as $ticket) {
                if ($ticket['candidate']->key === $key && $reporter->isVisible($ticket['facts'])) {
                    $candidates[] = $ticket['candidate'];
                }
            }
        }

        return $candidates;
    }
}
