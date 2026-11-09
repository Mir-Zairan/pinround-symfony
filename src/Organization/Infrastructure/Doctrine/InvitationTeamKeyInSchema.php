<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Organization\Domain\Invitation;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Index;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\ToolEvents;

/**
 * Adds org_invitation_team_fkey, (organization_id, team_id) to org_team (organization_id, id), and its index to the
 * ORM schema, so the schema diff matches Version20261109100000. Invitation keeps team_id as a plain field: a
 * nullable composite association would write NULL into organization_id, which is part of the key and NOT NULL.
 */
#[AsDoctrineListener(event: ToolEvents::postGenerateSchemaTable)]
final class InvitationTeamKeyInSchema
{
    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $event): void
    {
        if (Invitation::class !== $event->getClassMetadata()->getName()) {
            return;
        }

        $event->setClassTable(
            $event->getClassTable()->edit()
                ->addForeignKeyConstraint(
                    ForeignKeyConstraint::editor()
                        ->setUnquotedName('org_invitation_team_fkey')
                        ->setUnquotedReferencingColumnNames('organization_id', 'team_id')
                        ->setUnquotedReferencedTableName('org_team')
                        ->setUnquotedReferencedColumnNames('organization_id', 'id')
                        ->create(),
                )
                ->addIndex(
                    Index::editor()
                        ->setUnquotedName('org_invitation_organization_id_team_id_idx')
                        ->setUnquotedColumnNames('organization_id', 'team_id')
                        ->create(),
                )
                ->create(),
        );
    }
}
