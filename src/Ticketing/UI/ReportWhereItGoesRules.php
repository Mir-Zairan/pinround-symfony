<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\Identity\Api\UserQueries;
use App\Organization\Api\ActorContext;
use App\Organization\Api\MembershipQueries;
use App\Organization\Api\PossibleAssignee;
use App\Organization\Api\SettingsQueries;
use App\Organization\Api\TeamPeopleQueries;
use App\Organization\Api\TeamQueries;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\PostedWhereItGoes;
use App\Ticketing\Api\ProjectQueries;
use App\Ticketing\Api\ReportGiveTo;
use App\Ticketing\Api\TeamProjectChoice;
use App\Ticketing\Api\WhereItGoes;
use App\Ticketing\Api\WhereItGoesRefused;
use App\Ticketing\Api\WhereItGoesRules;
use App\Ticketing\Application\Intake\ReportPreviewFacts;
use App\Ticketing\Application\Intake\ReportPreviews;
use App\Ticketing\Application\RoleTicketVisibilities;

/**
 * Report a problem's "Where it goes" rules (report-a-problem.md, Where it goes), for Report a problem itself and for a
 * report draft's page and Send: which picks are among the reporter's choices (ReportFormChoices), and the words after
 * "When you send:" (ReportPreviewSentence). A report draft shows no possible duplicates and its Send names none, so its
 * words promise no "maybe the same as" reason.
 */
final readonly class ReportWhereItGoesRules implements WhereItGoesRules
{
    public const string TEAM_UNKNOWN = 'Pick a team, or Not sure.';
    public const string PROJECT_UNKNOWN = 'Pick a project of that team.';
    public const string NO_PROJECT_YET = 'No project takes reports yet. Ask a team lead to add one.';

    public function __construct(
        private ReportFormChoices $reportFormChoices,
        private MembershipQueries $membershipQueries,
        private ProjectQueries $projectQueries,
        private TeamPeopleQueries $teamPeopleQueries,
        private TeamQueries $teamQueries,
        private SettingsQueries $settingsQueries,
        private UserQueries $userQueries,
        private ReportPreviews $reportPreviews,
        private RoleTicketVisibilities $ticketVisibilities,
    ) {
    }

    public function defaultFor(OrganizationId $organizationId, UserId $reporter, string $projectKey): PostedWhereItGoes
    {
        $project = $this->projectQueries->projectByKey($projectKey);
        if (null === $project) {
            return new PostedWhereItGoes(PostedWhereItGoes::NO_TEAM);
        }

        return new PostedWhereItGoes($project->teamId()?->toString() ?? PostedWhereItGoes::NO_TEAM, strtolower($project->id()));
    }

    public function checkedOf(OrganizationId $organizationId, UserId $reporter, PostedWhereItGoes $posted): WhereItGoes
    {
        $teams = $this->reportFormChoices->teamsFor($this->actorOf($organizationId, $reporter));
        [$team, $project, $assignee, $errors] = $this->picksAmong($teams, $posted);
        if ([] !== $errors || null === $project) {
            throw new WhereItGoesRefused([] === $errors ? ['project' => self::PROJECT_UNKNOWN] : $errors);
        }

        return new WhereItGoes($project->id(), $team?->id, $assignee, $posted->type, $posted->priority);
    }

    public function previewOf(OrganizationId $organizationId, UserId $reporter, PostedWhereItGoes $posted, string $title, string $description, array $pinNotes): string
    {
        $actor = $this->actorOf($organizationId, $reporter);
        $facts = $this->reportPreviews->of($this->ticketVisibilities->ofActor($actor), trim($title), $description, $pinNotes, !$posted->isNotSure(), showsDuplicates: false);

        return $this->sentenceOf($actor, $posted, $facts);
    }

    /**
     * The team (null for "Not sure"), the project and the person picked, each checked against the choices offered, and
     * the errors of the parts that are not among them, the type and the priority included.
     *
     * @param list<ReportTeamChoice> $teams ReportFormChoices::teamsFor the reporter
     *
     * @return array{?ReportTeamChoice, ?TeamProjectChoice, ?UserId, array<string, string>}
     */
    public function picksAmong(array $teams, PostedWhereItGoes $posted): array
    {
        if ([] === $teams) {
            return [null, null, null, ['form' => self::NO_PROJECT_YET]];
        }
        $errors = [];
        if (!\array_key_exists($posted->type, NewTicketController::TYPE_LABELS)) {
            $errors['type'] = NewTicketController::TYPE_UNKNOWN;
        }
        if (!\array_key_exists($posted->priority, NewTicketController::PRIORITY_LABELS)) {
            $errors['priority'] = NewTicketController::PRIORITY_UNKNOWN;
        }
        $team = null;
        if (!$posted->isNotSure()) {
            $team = array_values(array_filter($teams, static fn (ReportTeamChoice $choice): bool => $choice->id->toString() === $posted->teamId))[0] ?? null;
            if (null === $team) {
                return [null, null, null, ['team' => self::TEAM_UNKNOWN, ...$errors]];
            }
        }
        $projects = $team->projects ?? array_merge(...array_map(static fn (ReportTeamChoice $choice): array => $choice->projects, $teams));
        $project = array_values(array_filter($projects, static fn (TeamProjectChoice $choice): bool => $choice->id() === $posted->projectId))[0] ?? null;
        if (null === $project) {
            $errors['project'] = self::PROJECT_UNKNOWN;
        }
        $assignee = null;
        if ('' !== $posted->assigneeId) {
            if (true === $team?->giveTo?->offers($posted->assigneeId)) {
                $assignee = new UserId($posted->assigneeId);
            } else {
                $errors['assignee'] = ReportGiveTo::NOT_OFFERED;
            }
        }

        return [$team, $project, $assignee, $errors];
    }

    /**
     * The words after "When you send:", or empty words while the posted project is not one of the picked team's (of
     * any team for "Not sure"). The preview runs this on every pause in typing, so it reads the posted project and the
     * picked team's cached people only, never the form's choices with their ticket counts. A picked tester, or anyone
     * when the reporter is not in the team, reads as "the team decides", as the create refuses them.
     */
    public function sentenceOf(ActorContext $actor, PostedWhereItGoes $posted, ReportPreviewFacts $facts): string
    {
        $nextKey = $this->projectQueries->nextTicketKeyOf($posted->projectId);
        $teamId = $nextKey?->teamId;
        if (null === $nextKey || null === $teamId || (!$posted->isNotSure() && $teamId->toString() !== $posted->teamId)) {
            return '';
        }
        if ($posted->isNotSure()) {
            return ReportPreviewSentence::of($nextKey->key, $facts->reasonPhrases, $this->checkerNameOf($actor, null), null, null, $facts->isTeamOnly);
        }
        $people = $this->teamPeopleQueries->namedPossibleAssigneesOf($teamId);
        $lead = ReportTeamPeople::leadOf($people);
        $checker = null === $lead ? null : ($lead->userId->equals($actor->userId()) ? 'You' : $lead->firstName);

        return ReportPreviewSentence::of($nextKey->key, $facts->reasonPhrases, $checker, $this->pickedPersonOf($actor, $teamId, $people, $posted->assigneeId), $lead?->firstName, $facts->isTeamOnly);
    }

    /**
     * "You" when the reporter checks, the checker's first name, or null when nobody is chosen: the team's lead, or the
     * organization's triager for no team.
     */
    public function checkerNameOf(ActorContext $actor, ?TeamId $teamId): ?string
    {
        $checkerId = null === $teamId ? $this->settingsQueries->triagerOf($actor->organizationId()) : $this->teamQueries->leadOf($teamId);
        if (null === $checkerId) {
            return null;
        }
        if ($checkerId->equals($actor->userId())) {
            return 'You';
        }

        return ($this->userQueries->byIds([$checkerId])[$checkerId->toString()] ?? null)?->firstName();
    }

    /**
     * @param list<PossibleAssignee> $people the team's
     *
     * @return string|null the picked person's first name, "you" for the reporter, or null for "the team decides"
     */
    private function pickedPersonOf(ActorContext $actor, TeamId $teamId, array $people, string $assigneeId): ?string
    {
        if ('' === $assigneeId || !$actor->roles()->isInTeam($teamId)) {
            return null;
        }
        $picked = array_values(array_filter(ReportTeamPeople::giveToPeopleOf($people), static fn (PossibleAssignee $person): bool => $person->userId->toString() === $assigneeId))[0] ?? null;
        if (null === $picked) {
            return null;
        }

        return $picked->userId->equals($actor->userId()) ? 'you' : $picked->firstName;
    }

    private function actorOf(OrganizationId $organizationId, UserId $reporter): ActorContext
    {
        return new ActorContext($reporter, $organizationId, $this->membershipQueries->rolesOf($reporter, $organizationId));
    }
}
