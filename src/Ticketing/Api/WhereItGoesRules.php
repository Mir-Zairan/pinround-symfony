<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * Report a problem's "Where it goes" for another module's page that sends a report (a report draft's page and its
 * Send): the same defaults, the same check of what was picked, and the same "When you send:" words. Every method reads
 * under the caller's open transaction whose tenant is $organizationId, with $reporter's roles in it.
 */
interface WhereItGoesRules
{
    /**
     * The picks a page starts with for a report of the project with $projectKey: its team (or "Not sure" when no team
     * owns it), that project, "The team decides", Bug and Normal. A key no project has gives "Not sure" with no project,
     * which checkedOf refuses.
     */
    public function defaultFor(OrganizationId $organizationId, UserId $reporter, string $projectKey): PostedWhereItGoes;

    /**
     * @throws WhereItGoesRefused for a team that takes no report, a project not of the picked team (of any team for
     *                            "Not sure"), a person "Give it to" does not offer the reporter for that team, or an
     *                            unknown type or priority; with ReportGiveTo::NOT_OFFERED for the person
     */
    public function checkedOf(OrganizationId $organizationId, UserId $reporter, PostedWhereItGoes $posted): WhereItGoes;

    /**
     * The words after "When you send:", or an empty string while the posted project is not one of the picked team's.
     *
     * @param list<string> $pinNotes the notes of the report's pins, in order, which the check reasons read
     */
    public function previewOf(OrganizationId $organizationId, UserId $reporter, PostedWhereItGoes $posted, string $title, string $description, array $pinNotes): string;
}
