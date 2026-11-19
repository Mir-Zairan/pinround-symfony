<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\Ticketing\Api\IntakeCandidate;
use App\Ticketing\Api\IntakeCandidateQueries;
use App\Ticketing\Api\ListFilter;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilitySql;
use App\Ticketing\Domain\TicketKey;
use App\Workflow\Api\ListedStepView;
use App\Workflow\Api\StepQueries;
use Doctrine\DBAL\Connection;

final readonly class DbalIntakeCandidateQueries implements IntakeCandidateQueries
{
    private const string TICKET_ALIAS = 't';

    public function __construct(private Connection $connection, private TenantContext $tenantContext, private StepQueries $stepQueries)
    {
    }

    public function similarOpenTickets(TicketVisibility $reporter, string $text): array
    {
        $organizationId = $this->reporterTenant($reporter);
        $openStepIds = array_values(array_map(
            static fn (ListedStepView $step): string => $step->id,
            array_filter($this->stepQueries->listedSteps(), static fn (ListedStepView $step): bool => 'done' !== $step->groupCode),
        ));
        if ('' === trim($text) || [] === $openStepIds) {
            return [];
        }
        $visibleToReporter = TicketVisibilitySql::of($reporter, self::TICKET_ALIAS);
        // As TicketVisibilitySql binds them: pdo_pgsql binds no arrays, and a uuid holds no comma or brace.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT t.key, t.title FROM search_ticket_ids(CAST(:organization AS uuid), CAST(:text AS text), CAST(:viewer AS uuid),'
            .' CAST(:team_ids AS uuid[]), CAST(:is_admin AS boolean), CAST(:is_triager AS boolean), NULL, CAST(:step_ids AS uuid[]), NULL)'
            .' WITH ORDINALITY AS found(ticket_id, found_position)'
            .' JOIN tkt_ticket t ON t.id = found.ticket_id'
            .' WHERE t.organization_id = CAST(:organization AS uuid) AND t.closed_at IS NULL AND '.$visibleToReporter->sql()
            .' ORDER BY found.found_position LIMIT '.self::SIMILAR_LIMIT,
            [
                ...$visibleToReporter->parameters(),
                'organization' => $organizationId->toString(),
                'text' => $text,
                'viewer' => $reporter->viewerId()->toString(),
                'team_ids' => '{'.implode(',', array_map(static fn (TeamId $teamId): string => $teamId->toString(), $reporter->teamIds())).'}',
                'is_admin' => $reporter->isAdmin() ? 'true' : 'false',
                'is_triager' => $reporter->isTriager() ? 'true' : 'false',
                'step_ids' => '{'.implode(',', $openStepIds).'}',
            ],
        );

        return array_map(
            static fn (array $row): IntakeCandidate => new IntakeCandidate(self::text($row['key'], 'key'), self::text($row['title'], 'title')),
            $rows,
        );
    }

    public function openCandidatesIn(string $projectId, TicketVisibility $reporter, bool $includeTeamOnly): array
    {
        if (!ListFilter::isCanonicalUuid($projectId)) {
            throw new \InvalidArgumentException('An intake candidate\'s project id must be a canonical lowercase uuid.');
        }
        $organizationId = $this->reporterTenant($reporter);

        $visibleToReporter = TicketVisibilitySql::of($reporter, self::TICKET_ALIAS);
        $audienceSql = $visibleToReporter->sql();
        $audienceParameters = $visibleToReporter->parameters();
        if (!$includeTeamOnly) {
            // Both conditions bind :t_visible_org; they name the same organization, so merging their parameters is safe.
            $forEveryone = TicketVisibilitySql::forEveryoneIn($reporter->organizationId(), self::TICKET_ALIAS);
            $audienceSql .= ' AND '.$forEveryone->sql();
            $audienceParameters = [...$audienceParameters, ...$forEveryone->parameters()];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT t.key, t.title FROM tkt_ticket t'
            .' WHERE t.organization_id = CAST(:organization AS uuid) AND t.project_id = CAST(:project AS uuid)'
            .' AND t.closed_at IS NULL AND '.$audienceSql
            .' ORDER BY t.updated_at DESC, t.id DESC'
            .' LIMIT '.self::CANDIDATE_LIMIT,
            [
                ...$audienceParameters,
                'organization' => $organizationId->toString(),
                'project' => $projectId,
            ],
        );

        return array_map(
            static fn (array $row): IntakeCandidate => new IntakeCandidate(self::text($row['key'], 'key'), self::text($row['title'], 'title')),
            $rows,
        );
    }

    public function openCandidatesByKeys(TicketVisibility $reporter, array $keys): array
    {
        if (\count($keys) > self::CANDIDATE_LIMIT) {
            throw new \InvalidArgumentException(\sprintf('At most %d intake candidates are read by key at once.', self::CANDIDATE_LIMIT));
        }
        $organizationId = $this->reporterTenant($reporter);
        $storableKeys = array_values(array_unique(array_filter($keys, TicketKey::canBe(...))));
        if ([] === $storableKeys) {
            return [];
        }

        $visibleToReporter = TicketVisibilitySql::of($reporter, self::TICKET_ALIAS);
        // pdo_pgsql binds no arrays, so the keys travel as one text[] literal; a key TicketKey::canBe accepts holds only
        // capital letters, digits and a hyphen.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT t.key, t.title FROM tkt_ticket t'
            .' WHERE t.organization_id = CAST(:organization AS uuid) AND t.key = ANY(CAST(:keys AS text[]))'
            .' AND t.closed_at IS NULL AND '.$visibleToReporter->sql(),
            [
                ...$visibleToReporter->parameters(),
                'organization' => $organizationId->toString(),
                'keys' => '{'.implode(',', $storableKeys).'}',
            ],
        );
        $titlesByKey = [];
        foreach ($rows as $row) {
            $titlesByKey[self::text($row['key'], 'key')] = self::text($row['title'], 'title');
        }

        $candidates = [];
        foreach ($storableKeys as $key) {
            if (isset($titlesByKey[$key])) {
                $candidates[] = new IntakeCandidate($key, $titlesByKey[$key]);
            }
        }

        return $candidates;
    }

    /**
     * @throws \LogicException when no transaction is open or the reporter's organization is not the tenant
     */
    private function reporterTenant(TicketVisibility $reporter): OrganizationId
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Intake candidates are read in a transaction under the organization as tenant; none is open.');
        }
        $organizationId = $this->tenantContext->require();
        if (!$organizationId->equals($reporter->organizationId())) {
            throw new \LogicException('Intake candidates are read under the reporter\'s own organization as tenant.');
        }

        return $organizationId;
    }

    private static function text(mixed $value, string $column): string
    {
        if (!\is_string($value)) {
            throw new \LogicException(\sprintf('The column %s of an intake candidate is not text.', $column));
        }

        return $value;
    }
}
