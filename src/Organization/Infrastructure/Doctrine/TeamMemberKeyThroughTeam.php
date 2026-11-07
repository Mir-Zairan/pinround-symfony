<?php

declare(strict_types=1);

namespace App\Organization\Infrastructure\Doctrine;

use App\Organization\Domain\TeamMember;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;

/**
 * TeamMember's key columns organization_id and team_id are also the join columns of its team association, which gives
 * the composite foreign key to org_team (organization_id, id). Doctrine would write each column twice, so the two id
 * fields are made read-only here and the association writes them; the XML mapping cannot mark an id so.
 */
#[AsDoctrineListener(event: Events::loadClassMetadata)]
final class TeamMemberKeyThroughTeam
{
    public function loadClassMetadata(LoadClassMetadataEventArgs $event): void
    {
        $metadata = $event->getClassMetadata();
        if (TeamMember::class !== $metadata->getName()) {
            return;
        }

        foreach (['organizationId', 'teamId'] as $field) {
            $metadata->fieldMappings[$field]->notInsertable = true;
            $metadata->fieldMappings[$field]->notUpdatable = true;
        }
    }
}
