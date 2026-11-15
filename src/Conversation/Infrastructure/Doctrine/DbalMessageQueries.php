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

    public function isPresent(string $messageId): bool
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A message is read in a transaction under the organization as tenant; none is open.');
        }
        $organizationId = $this->tenantContext->require()->toString();
        if (!Uuid::isValid($messageId)) {
            return false;
        }

        $isPresent = $this->connection->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM cnv_message WHERE organization_id = CAST(? AS uuid) AND id = CAST(? AS uuid) AND removed_at IS NULL)',
            [$organizationId, $messageId],
        );
        if (!\is_bool($isPresent)) {
            throw new \LogicException('Whether a message is present came back from the database as no boolean.');
        }

        return $isPresent;
    }
}
