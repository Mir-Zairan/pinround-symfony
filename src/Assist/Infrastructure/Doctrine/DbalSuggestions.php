<?php

declare(strict_types=1);

namespace App\Assist\Infrastructure\Doctrine;

use App\Assist\Api\IntakeCheckId;
use App\Assist\Api\IntakeSuggestion;
use App\Assist\Application\Intake\IntakeSuggestionMapper;
use App\Assist\Application\Suggestions;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use Doctrine\DBAL\Connection;

/**
 * Suggestions on ast_suggestion, which pinround_app may only select and insert. Names the tenant's organization_id as
 * well as row-level security, so a row can only land in, and be read from, the tenant's organization; a read names the
 * requester too, so nobody else gets their suggestion.
 */
final readonly class DbalSuggestions implements Suggestions
{
    private const string INTAKE = 'intake';

    private const string INSERT = 'INSERT INTO ast_suggestion (organization_id, id, user_id, purpose, result, model, prompt_version, created_at)'
        .' VALUES (?, ?, ?, ?, ?, ?, ?, ?)';

    private const string SELECT_OF_REQUESTER = 'SELECT result, model, prompt_version FROM ast_suggestion'
        .' WHERE organization_id = ? AND id = ? AND user_id = ? AND purpose = ?';

    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function addIntake(IntakeCheckId $checkId, OrganizationId $organizationId, UserId $requesterId, IntakeSuggestion $suggestion, \DateTimeImmutable $createdAt): void
    {
        $this->requireTenant($organizationId);
        $this->connection->executeStatement(self::INSERT, [
            $organizationId->toString(),
            $checkId->toString(),
            $requesterId->toString(),
            self::INTAKE,
            json_encode(IntakeSuggestionMapper::resultOf($suggestion), \JSON_THROW_ON_ERROR),
            $suggestion->model,
            $suggestion->promptVersion,
            $createdAt->format('Y-m-d H:i:s.uP'),
        ]);
    }

    public function intakeOf(OrganizationId $organizationId, IntakeCheckId $checkId, UserId $requesterId): ?IntakeSuggestion
    {
        $this->requireTenant($organizationId);
        $row = $this->connection->fetchAssociative(self::SELECT_OF_REQUESTER, [$organizationId->toString(), $checkId->toString(), $requesterId->toString(), self::INTAKE]);
        if (false === $row) {
            return null;
        }
        if (!\is_string($row['result']) || !\is_string($row['model']) || !\is_string($row['prompt_version'])) {
            throw new \UnexpectedValueException('A stored intake suggestion has no result or label.');
        }
        try {
            $result = json_decode($row['result'], true, 3, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $unreadable) {
            throw new \UnexpectedValueException('A stored intake suggestion holds no JSON.', 0, $unreadable);
        }
        if (!\is_array($result)) {
            throw new \UnexpectedValueException('A stored intake suggestion holds no object.');
        }

        return IntakeSuggestionMapper::fromCheckedData($result, $row['model'], $row['prompt_version']);
    }

    /**
     * @throws \LogicException when no transaction is open or $organizationId is not its tenant
     */
    private function requireTenant(OrganizationId $organizationId): void
    {
        // TenantMiddleware sets the tenant when a transaction begins; outside one the policy would hide every row.
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('Suggestions are kept in a transaction under the organization as tenant; none is open.');
        }
        if (!$this->tenantContext->require()->equals($organizationId)) {
            throw new \LogicException('Suggestions are kept under their own organization as tenant.');
        }
    }
}
