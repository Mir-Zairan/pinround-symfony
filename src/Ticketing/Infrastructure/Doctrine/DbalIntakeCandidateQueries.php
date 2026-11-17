<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\SharedKernel\Application\TenantContext;
use App\Ticketing\Api\IntakeCandidate;
use App\Ticketing\Api\IntakeCandidateQueries;
use App\Ticketing\Api\ListFilter;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilitySql;
use Doctrine\DBAL\Connection;

final readonly class DbalIntakeCandidateQueries implements IntakeCandidateQueries
{
    private const string TICKET_ALIAS = 't';

    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function openCandidatesIn(string $projectId, TicketVisibility $reporter): array
    {
        if (!ListFilter::isCanonicalUuid($projectId)) {
            throw new \InvalidArgumentException('An intake candidate\'s project id must be a canonical lowercase uuid.');
        }
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Intake candidates are read in a transaction under the organization as tenant; none is open.');
        }
        $organizationId = $this->tenantContext->require();
        if (!$organizationId->equals($reporter->organizationId())) {
            throw new \LogicException('Intake candidates are read under the reporter\'s own organization as tenant.');
        }

        // Both conditions bind :t_visible_org; they name the same organization, so merging their parameters is safe.
        $visibleToReporter = TicketVisibilitySql::of($reporter, self::TICKET_ALIAS);
        $forEveryone = TicketVisibilitySql::forEveryoneIn($reporter->organizationId(), self::TICKET_ALIAS);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT t.key, t.title FROM tkt_ticket t'
            .' WHERE t.organization_id = CAST(:organization AS uuid) AND t.project_id = CAST(:project AS uuid)'
            .' AND t.closed_at IS NULL AND '.$visibleToReporter->sql().' AND '.$forEveryone->sql()
            .' ORDER BY t.updated_at DESC, t.id DESC'
            .' LIMIT '.self::CANDIDATE_LIMIT,
            [
                ...$visibleToReporter->parameters(),
                ...$forEveryone->parameters(),
                'organization' => $organizationId->toString(),
                'project' => $projectId,
            ],
        );

        return array_map(
            static fn (array $row): IntakeCandidate => new IntakeCandidate(self::text($row['key'], 'key'), self::text($row['title'], 'title')),
            $rows,
        );
    }

    private static function text(mixed $value, string $column): string
    {
        if (!\is_string($value)) {
            throw new \LogicException(\sprintf('The column %s of an intake candidate is not text.', $column));
        }

        return $value;
    }
}
