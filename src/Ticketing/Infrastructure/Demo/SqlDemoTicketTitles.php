<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Demo;

use App\SharedKernel\Application\TenantContext;
use App\Ticketing\Api\DemoTicketTitles;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\When;

// Never registered in prod: only the demo seed looks tickets up by title.
#[When('dev')]
#[When('test')]
final readonly class SqlDemoTicketTitles implements DemoTicketTitles
{
    public function __construct(private Connection $connection, private TenantContext $tenantContext)
    {
    }

    public function keysByTitleIn(string $projectKey): array
    {
        if (!$this->connection->isTransactionActive() || null === $this->tenantContext->get()) {
            throw new \LogicException('Tickets are looked up by title in an open transaction under the tenant of their organization.');
        }

        $keysByTitle = [];
        // Ordered by number, so of two tickets with one title the later one is kept.
        foreach ($this->connection->fetchAllNumeric(
            'SELECT t.title, t.key FROM tkt_ticket t
             JOIN tkt_project p ON p.organization_id = t.organization_id AND p.id = t.project_id
             WHERE p.key = ? ORDER BY t.number',
            [$projectKey],
        ) as [$title, $key]) {
            \assert(\is_string($title) && \is_string($key));
            $keysByTitle[$title] = $key;
        }

        return $keysByTitle;
    }
}
