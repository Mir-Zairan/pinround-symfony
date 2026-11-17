<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * For the tests of the e2e seed commands, which find an organization only as the demo seed names it: by slug, through
 * its first admin's email. The using test also uses CreatesAcmeFoodsTeams, CreatesTeams, LogsInUsers and
 * SeedsTicketRounds, whose $people, $shopProject and $stepId this sets.
 */
trait SeedsAssignedDemoTicket
{
    /**
     * @return string the ticket's id; SHOP-1 is given to Omar
     */
    private function seedAssignedTicket(): string
    {
        $this->people = $this->createAcmeFoodsPeople('member');
        $this->migrator()->executeStatement('UPDATE idn_user SET email = ? WHERE id = ?', ['sara@acme-foods.example', $this->people['Sara']->id()->toString()]);
        $organizationId = $this->acmeFoods->toString();
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE org_organization SET slug = 'acme-foods' WHERE organization_id = ?",
            [$organizationId],
        ));
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->stepId = Uuid::v7()->toRfc4122();
        $stepId = $this->stepId;
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'To do', 'todo', 'todo', 'core', 1)",
            [$organizationId, $stepId],
        ));

        return $this->addTicket('SHOP-1');
    }
}
