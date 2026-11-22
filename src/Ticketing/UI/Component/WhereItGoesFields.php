<?php

declare(strict_types=1);

namespace App\Ticketing\UI\Component;

use App\Organization\Api\ActorContext;
use App\Organization\Api\MembershipQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\PostedWhereItGoes;
use App\Ticketing\UI\NewTicketController;
use App\Ticketing\UI\ReportFormChoices;
use App\Ticketing\UI\ReportTeamChoice;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\ExposeInTemplate;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * Report a problem's "Where it goes" box for another module's page that sends a report (a report draft's), by its
 * component name: the same chips, from the same ReportFormChoices, as _report_where_it_goes.html.twig shows on Report a
 * problem, without Assist's check. The page's form carries the report-form controller, so a team change works as there.
 */
#[AsTwigComponent(name: 'WhereItGoesFields', template: '@Ticketing/components/WhereItGoesFields.html.twig')]
final class WhereItGoesFields
{
    public OrganizationId $organizationId;
    public UserId $reporterId;
    public PostedWhereItGoes $input;
    /** @var array<string, string> by part, as WhereItGoesRefused::$errors */
    public array $errors = [];

    /** @var list<ReportTeamChoice> */
    #[ExposeInTemplate]
    private array $teams = [];

    public function __construct(private readonly ReportFormChoices $reportFormChoices, private readonly MembershipQueries $membershipQueries)
    {
    }

    /**
     * The reporter's roles are read here, in the transaction the page renders in, as an ActorContext a request read
     * before its transaction holds no team role (CurrentActor under row-level security).
     */
    #[PostMount]
    public function readChoices(): void
    {
        $reporter = new ActorContext($this->reporterId, $this->organizationId, $this->membershipQueries->rolesOf($this->reporterId, $this->organizationId));
        $this->teams = $this->reportFormChoices->teamsFor($reporter);
    }

    /**
     * @return list<ReportTeamChoice>
     */
    public function getTeams(): array
    {
        return $this->teams;
    }

    /**
     * @return list<\App\Ticketing\Api\TeamProjectChoice> every team's projects, which "Not sure" offers
     */
    #[ExposeInTemplate]
    public function getNotSureProjects(): array
    {
        return array_merge([], ...array_map(static fn (ReportTeamChoice $choice): array => $choice->projects, $this->teams));
    }

    #[ExposeInTemplate]
    public function getNoTeam(): string
    {
        return PostedWhereItGoes::NO_TEAM;
    }

    /**
     * @return array<string, string>
     */
    #[ExposeInTemplate]
    public function getTypeLabels(): array
    {
        return NewTicketController::TYPE_LABELS;
    }

    /**
     * @return array<string, string>
     */
    #[ExposeInTemplate]
    public function getPriorityLabels(): array
    {
        return NewTicketController::PRIORITY_LABELS;
    }
}
