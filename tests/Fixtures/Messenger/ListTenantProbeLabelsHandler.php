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
     * Loads the rows as entities, so they sit in the entity manager when the handler returns.
     *
     * @return array{labels: list<string>, orgSetting: mixed}
     */
    public function __invoke(ListTenantProbeLabels $message): array
    {
        $probes = $this->entityManager->getRepository(TenantProbe::class)->findBy([], ['label' => 'ASC']);
        $sighting = [
            'labels' => array_map(static fn (TenantProbe $probe): string => $probe->label(), $probes),
            'orgSetting' => $this->connection->fetchOne("SELECT current_setting('app.org_id', true)"),
        ];

        if ($message->isFailingAfterReading) {
            throw new \RuntimeException('The handler failed after reading.');
        }

        return $sighting;
    }
}
