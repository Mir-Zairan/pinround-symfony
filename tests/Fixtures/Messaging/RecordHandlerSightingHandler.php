<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messaging;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Infrastructure\Messenger\MessageBeingHandled;
use App\Tests\Fixtures\Entity\TenantProbe;
use App\Workflow\Infrastructure\Cache\WorkflowCache;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * @phpstan-type HandlerSighting array{organizationId: string|null, labels: list<mixed>, messageId: string|null, identityMapSizeAtStart: int, identityMapSizeAfterLoading: int, workflowCacheOrganizationId: string|null}
 */
#[AsMessageHandler]
final class RecordHandlerSightingHandler
{
    /** One team for every organization, so only the organization of the transaction keeps their entries apart. */
    private const string WORKFLOW_CACHE_TEAM_ID = '0197a3c0-0000-7000-8000-000000002212';

    /** @var list<HandlerSighting> */
    private array $sightings = [];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly MessageBeingHandled $messageBeingHandled,
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection $connection,
        private readonly WorkflowCache $workflowCache,
    ) {
    }

    /**
     * @throws UnrecoverableMessageHandlingException after loading, when the message asks for it; not retried
     */
    public function __invoke(RecordHandlerSighting $message): void
    {
        $identityMapSizeAtStart = $this->entityManager->getUnitOfWork()->size();
        // Plain SQL, so only row-level security decides which rows it sees.
        $labels = $this->connection->fetchFirstColumn('SELECT label FROM zz_tenant_probe ORDER BY label');
        $this->entityManager->getRepository(TenantProbe::class)->findAll();

        $this->sightings[] = [
            'organizationId' => $this->tenantContext->get()?->toString(),
            'labels' => $labels,
            'messageId' => $this->messageBeingHandled->messageId(),
            'identityMapSizeAtStart' => $identityMapSizeAtStart,
            'identityMapSizeAfterLoading' => $this->entityManager->getUnitOfWork()->size(),
            'workflowCacheOrganizationId' => $this->workflowCache->remember(
                new TeamId(self::WORKFLOW_CACHE_TEAM_ID),
                'sighting',
                fn (): ?string => $this->tenantContext->get()?->toString(),
            ),
        ];

        if ($message->isFailingAfterLoading) {
            throw new UnrecoverableMessageHandlingException('The sighting handler failed after loading.');
        }
    }

    /**
     * @return list<HandlerSighting> in the order the messages were handled
     */
    public function sightings(): array
    {
        return $this->sightings;
    }
}
