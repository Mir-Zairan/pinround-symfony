<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Doctrine;

use App\Conversation\Domain\Message;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Adds cnv_message_ticket_fkey, (organization_id, ticket_id) to tkt_ticket (organization_id, id) ON DELETE CASCADE, to
 * the ORM schema, so the schema diff matches Version20261114120000. Message keeps ticket_id as a plain field, as an
 * aggregate names another by id. The cnv_message_ticket_id_created_at_id_idx index serves the key.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchemaTable)]
final class MessageTicketKeyInSchema
{
    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $event): void
    {
        if (Message::class !== $event->getClassMetadata()->getName()) {
            return;
        }

        $event->setClassTable(
            $event->getClassTable()->edit()
                ->addForeignKeyConstraint(
                    ForeignKeyConstraint::editor()
                        ->setUnquotedName('cnv_message_ticket_fkey')
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
