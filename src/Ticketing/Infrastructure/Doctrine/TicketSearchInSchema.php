<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Doctrine;

use App\Ticketing\Domain\Ticket;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Adds the generated search column of tkt_ticket and its two GIN indexes to the ORM schema, so the schema diff matches
 * Version20261112100000 while Ticket never reads or writes the column. DBAL has no generated columns: it reads this
 * one as a nullable text column without a default, and reads neither an index's access method nor its operator class.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchemaTable)]
final class TicketSearchInSchema
{
    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $event): void
    {
        if (Ticket::class !== $event->getClassMetadata()->getName()) {
            return;
        }

        $event->setClassTable(
            $event->getClassTable()->edit()
                ->addColumn(Column::editor()->setUnquotedName('search')->setTypeName(Types::TEXT)->setNotNull(false)->create())
                ->addIndex(Index::editor()->setUnquotedName('tkt_ticket_search_idx')->setUnquotedColumnNames('organization_id', 'search')->create())
                ->addIndex(Index::editor()->setUnquotedName('tkt_ticket_title_trgm_idx')->setUnquotedColumnNames('organization_id', 'title')->create())
                ->create(),
        );
    }
}
