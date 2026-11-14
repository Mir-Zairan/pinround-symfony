<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Doctrine;

use App\Notification\Domain\Ping;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Adds ntf_ping_ticket_fkey, (organization_id, ticket_id) to tkt_ticket (organization_id, id) ON DELETE CASCADE, to the
 * ORM schema, so the schema diff matches Version20261114130000. Ping keeps ticket_id as a plain field, as an aggregate
 * names another by id. The ntf_ping_ticket_id_idx index serves the key.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchemaTable)]
final class PingTicketKeyInSchema
{
    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $event): void
    {
        if (Ping::class !== $event->getClassMetadata()->getName()) {
            return;
        }

        $event->setClassTable(
            $event->getClassTable()->edit()
                ->addForeignKeyConstraint(
                    ForeignKeyConstraint::editor()
                        ->setUnquotedName('ntf_ping_ticket_fkey')
                        ->setUnquotedReferencingColumnNames('organization_id', 'ticket_id')
                        ->setUnquotedReferencedTableName('tkt_ticket')
                        ->setUnquotedReferencedColumnNames('organization_id', 'id')
                        ->setOnDeleteAction(ReferentialAction::CASCADE)
                        ->create(),
                )
                ->create(),
        );
    }
}
