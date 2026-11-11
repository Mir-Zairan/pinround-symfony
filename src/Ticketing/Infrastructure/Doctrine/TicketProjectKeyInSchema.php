<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\Ticketing\Domain\Ticket;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Adds tkt_ticket_project_fkey, (organization_id, project_id) to tkt_project (organization_id, id), to the ORM schema,
 * so the schema diff matches Version20261111140000. Ticket keeps project_id as a plain field, as an aggregate names
 * another by id. The unique (organization_id, project_id, number) index serves the key.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchemaTable)]
final class TicketProjectKeyInSchema
{
    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $event): void
    {
        if (Ticket::class !== $event->getClassMetadata()->getName()) {
            return;
        }

        $event->setClassTable(
            $event->getClassTable()->edit()
                ->addForeignKeyConstraint(
                    ForeignKeyConstraint::editor()
                        ->setUnquotedName('tkt_ticket_project_fkey')
                        ->setUnquotedReferencingColumnNames('organization_id', 'project_id')
                        ->setUnquotedReferencedTableName('tkt_project')
                        ->setUnquotedReferencedColumnNames('organization_id', 'id')
                        ->create(),
                )
                ->create(),
        );
    }
}
