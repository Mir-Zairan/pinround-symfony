<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messenger;

use App\Tests\Fixtures\Entity\TenantProbe;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SaveTenantProbeHandler
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(SaveTenantProbe $message): void
    {
        $this->entityManager->persist(new TenantProbe($message->probeId, $message->organizationId, $message->label));
    }
}
