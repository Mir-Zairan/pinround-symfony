<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Doctrine;

use App\Conversation\Api\QuestionQueries;
use App\SharedKernel\Application\TenantContext;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * QuestionQueries on cnv_message's primary key (organization_id, id).
 */
final readonly class DbalQuestionQueries implements QuestionQueries
{
    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function isOpen(string $questionId): bool
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A question is read in a transaction under the organization as tenant; none is open.');
        }
        $organizationId = $this->tenantContext->require()->toString();
        if (!Uuid::isValid($questionId)) {
            return false;
        }

        $isOpen = $this->connection->fetchOne(
            "SELECT EXISTS (SELECT 1 FROM cnv_message WHERE organization_id = CAST(? AS uuid) AND id = CAST(? AS uuid) AND kind = 'question'"
            .' AND resolved_at IS NULL AND removed_at IS NULL)',
            [$organizationId, $questionId],
        );
        if (!\is_bool($isOpen)) {
            throw new \LogicException('Whether a question is open came back from the database as no boolean.');
        }

        return $isOpen;
    }
}
