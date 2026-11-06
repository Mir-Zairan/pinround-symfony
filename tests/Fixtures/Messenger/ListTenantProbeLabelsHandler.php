<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messenger;

use App\Tests\Fixtures\Entity\TenantProbe;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ListTenantProbeLabelsHandler
{
    public function __construct(private EntityManagerInterface $entityManager, private Connection $connection)
    {
    }

    /**
     * Through the ORM it loads the rows as entities, so they sit in the entity manager when the handler returns.
     *
     * @return array{labels: list<mixed>, orgSetting: mixed}
     */
    public function __invoke(ListTenantProbeLabels $message): array
    {
        $sighting = [
            'labels' => $message->isReadingWithSql
                ? $this->connection->fetchFirstColumn('SELECT label FROM zz_tenant_probe ORDER BY label')
                : array_map(
                    static fn (TenantProbe $probe): string => $probe->label(),
                    $this->entityManager->getRepository(TenantProbe::class)->findBy([], ['label' => 'ASC']),
                ),
            'orgSetting' => $this->connection->fetchOne("SELECT current_setting('app.org_id', true)"),
        ];

        if ($message->isFailingAfterReading) {
            throw new \RuntimeException('The handler failed after reading.');
        }

        return $sighting;
    }
}
