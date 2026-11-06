<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messenger;

use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class InsertTenantProbeLabelLeavingTransactionOpenHandler
{
    public function __construct(private Connection $connection)
    {
    }

    public function __invoke(InsertTenantProbeLabelLeavingTransactionOpen $message): void
    {
        $this->connection->beginTransaction();
        $this->connection->executeStatement(
            'INSERT INTO zz_tenant_probe (organization_id, label) VALUES (?, ?)',
            [$message->organizationId, $message->label],
        );
    }
}
