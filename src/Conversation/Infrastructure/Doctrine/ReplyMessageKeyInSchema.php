<?php

declare(strict_types=1);

namespace App\Conversation\Infrastructure\Doctrine;

use App\Conversation\Domain\Reply;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Adds cnv_reply_message_fkey, (organization_id, message_id) to cnv_message (organization_id, id) ON DELETE CASCADE,
 * to the ORM schema, so the schema diff matches Version20261114120000. Reply keeps message_id as a plain field: a
 * composite association would share organization_id with the key. The cnv_reply_message_id_created_at_id_idx index
 * serves the key.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchemaTable)]
final class ReplyMessageKeyInSchema
{
    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $event): void
    {
        if (Reply::class !== $event->getClassMetadata()->getName()) {
            return;
        }

        $event->setClassTable(
            $event->getClassTable()->edit()
                ->addForeignKeyConstraint(
                    ForeignKeyConstraint::editor()
                        ->setUnquotedName('cnv_reply_message_fkey')
                        ->setUnquotedReferencingColumnNames('organization_id', 'message_id')
                        ->setUnquotedReferencedTableName('cnv_message')
                        ->setUnquotedReferencedColumnNames('organization_id', 'id')
                        ->setOnDeleteAction(ReferentialAction::CASCADE)
                        ->create(),
                )
                ->create(),
        );
    }
}
