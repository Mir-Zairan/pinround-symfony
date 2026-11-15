<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Doctrine;

use App\Conversation\Api\MessageQueries;
use App\SharedKernel\Application\TenantContext;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * MessageQueries on cnv_message's primary key (organization_id, id).
 */
final readonly class DbalMessageQueries implements MessageQueries
{
    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function isOpenQuestion(string $messageId): bool
    {
        return $this->existsWhere($messageId, "kind = 'question' AND resolved_at IS NULL AND removed_at IS NULL");
    }

    public function isPresent(string $messageId): bool
    {
        return $this->existsWhere($messageId, 'removed_at IS NULL');
    }

    /**
     * @param literal-string $condition
     */
    private function existsWhere(string $messageId, string $condition): bool
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A message is read in a transaction under the organization as tenant; none is open.');
        }
        $organizationId = $this->tenantContext->require()->toString();
        if (!Uuid::isValid($messageId)) {
            return false;
        }

        $exists = $this->connection->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM cnv_message WHERE organization_id = CAST(? AS uuid) AND id = CAST(? AS uuid) AND '.$condition.')',
            [$organizationId, $messageId],
        );
        if (!\is_bool($exists)) {
            throw new \LogicException('Whether a message exists came back from the database as no boolean.');
        }

        return $exists;
    }
}
