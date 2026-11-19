<?php

declare(strict_types=1);

namespace App\Ticketing\UI;

use App\Identity\Api\UserQueries;
use App\Organization\Api\ActorContext;
use App\Organization\Api\CurrentActor;
use App\Organization\Api\SettingsQueries;
use App\Organization\Api\TeamQueries;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\Transactions;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Http\RequestTransaction;
use App\Ticketing\Api\IntakeCandidate;
use App\Ticketing\Api\NewTicketReport;
use App\Ticketing\Api\NewTicketReportRefused;
use App\Ticketing\Api\ProblemReport;
use App\Ticketing\Api\ProjectQueries;
use App\Ticketing\Api\ReportedTicket;
use App\Ticketing\Api\TeamProjectChoice;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Api\TicketKeyQueries;
use App\Ticketing\Api\TicketPermission;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Application\CreateTicket\AssigneeNotInTeam;
use App\Ticketing\Application\CreateTicket\ProjectHasNoTeam;
use App\Ticketing\Application\CreateTicket\TicketDescriptionOverLimit;
use App\Ticketing\Application\CreateTicket\TicketTitleMissing;
use App\Ticketing\Application\CreateTicket\TicketTitleOverLimit;
use App\Ticketing\Application\Intake\ReportPreviewFacts;
use App\Ticketing\Application\Intake\ReportPreviews;
use App\Ticketing\Application\ProjectNotFound;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Report a problem at /report (P06.T1, FR-INT-01, FR-INT-02, report-a-problem.md): anyone in the organization reports,
 * and the report becomes a ticket at once, with its screenshot and pins as Round 0. Where it goes: a team that owns a
 * project, or "Not sure"; for the reporter's own team, "The team (<lead> decides)" or one of its people; a project of
 * that team (any team's for "Not sure"); the type and the priority. The check reasons and team-only visibility are
 * Ticketing's to work out (TicketCommands::report), never the form's.
 *
 * Without the request transaction, as NewTicketController: a refused create has taken the project's next number, and
 * only rolling back the whole transaction gives it back. A refusal shows the form again with what was typed.
 */
final class ReportController extends AbstractController
{
    public const string ROUTE = 'ticketing_report';
    public const string PREVIEW_ROUTE = 'ticketing_report_preview';
    public const string CSRF_ID = 'ticketing_report';
    public const string CSRF_FIELD = '_csrf_token';
    public const string TEAM_FIELD = 'team_id';
    /**
     * Review's upload target kind for a picture of a report not made yet (its NewReportMediaTarget), whose id is a
     * project key; this module may not read Review's constant.
     */
    public const string UPLOAD_TARGET_KIND = 'new_report';

    public const string TEAM_UNKNOWN = 'Pick a team, or Not sure.';
    public const string PROJECT_UNKNOWN = 'Pick a project of that team.';
    public const string NO_PROJECT_YET = 'No project takes reports yet. Ask a team lead to add one.';
    public const string ASSIGNEE_UNKNOWN = 'Pick someone from the list, or the team.';
    public const string TITLE_MISSING = 'Say what is wrong in one line.';
    /** With the key. */
    public const string CREATED_UNCHECKED = 'Ticket %s made. Nobody needs to check it.';
    /** With the key and the checker's first name. */
    public const string CREATED_CHECKED = 'Ticket %s made. %s takes a quick look.';
    public const string CREATED_FOR_YOU_TO_CHECK = 'Ticket %s made. It is on your list to check.';
    public const string CREATED_NO_CHECKER = 'Ticket %s made. Nobody is chosen yet to check tickets with no team.';

    private const string TOAST = 'toast';

    public function __construct(
        private readonly Transactions $transactions,
        private readonly TenantContext $tenantContext,
        private readonly CurrentActor $currentActor,
        private readonly ReportFormChoices $reportFormChoices,
        private readonly TicketCommands $ticketCommands,
        private readonly TicketKeyQueries $ticketKeyQueries,
        private readonly NewTicketReport $newTicketReport,
        private readonly TeamQueries $teamQueries,
        private readonly SettingsQueries $settingsQueries,
        private readonly UserQueries $userQueries,
        private readonly ReportPreviews $reportPreviews,
        private readonly TicketVisibilities $ticketVisibilities,
        private readonly ProjectQueries $projectQueries,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route('/report', name: self::ROUTE, methods: ['GET', 'POST'], defaults: [RequestTransaction::ROUTE_DEFAULT => false])]
    public function open(Request $request): Response
    {
        if (null === $this->tenantContext->get()) {
            throw $this->createAccessDeniedException();
        }
        if ($request->isMethod('GET')) {
            return $this->transactions->run(function (): Response {
                $actor = $this->reporter();
                $teams = $this->reportFormChoices->teamsFor($actor);

                return $this->renderForm($actor, $teams, self::defaultInputOf($teams));
            });
        }

        $input = ReportFormInput::fromRequest($request);
        $csrfToken = $request->request->getString(self::CSRF_FIELD);
        $now = $this->clock->now();
        try {
            /** @var array{ReportedTicket, string} $made */
            $made = $this->transactions->run(function () use ($input, $csrfToken, $now): array {
                $actor = $this->reporter();
                $teams = $this->reportFormChoices->teamsFor($actor);
                if (!$this->isCsrfTokenValid(self::CSRF_ID, $csrfToken)) {
                    throw new TicketFormRefused($this->renderForm($actor, $teams, $input, ['form' => NewTicketController::FORM_EXPIRED]));
                }
                [$team, $project, $assignee, $errors] = $this->whereItGoes($teams, $input);
                $errors = [...$errors, ...self::fieldErrorsOf($input)];
                $screenshotRefusal = $this->newTicketReport->refusalOf($actor->organizationId(), $actor->userId(), $input->report);
                if (null !== $screenshotRefusal) {
                    $errors['screenshot'] = $screenshotRefusal;
                }
                if ([] !== $errors || null === $project) {
                    throw new TicketFormRefused($this->renderForm($actor, $teams, $input, $errors));
                }

                // The same duplicates the preview showed, asked again now, so "dupe" holds exactly when one is shown.
                $facts = $this->previewFactsOf($actor, $input, null !== $team);
                try {
                    $reported = $this->ticketCommands->report(new ProblemReport(
                        $actor->organizationId(),
                        $actor->userId(),
                        $project->id(),
                        $team?->id,
                        trim($input->title),
                        $input->description,
                        $input->type,
                        $input->priority,
                        $assignee,
                        $input->pinNotes(),
                        $now,
                        array_map(static fn (IntakeCandidate $duplicate): string => $duplicate->key, $facts->duplicates),
                    ));
                    $ticketId = $this->ticketKeyQueries->idOf($reported->key) ?? throw new \LogicException(\sprintf('The ticket %s just made is not found by its key.', $reported->key));
                    $this->newTicketReport->attach($actor->organizationId(), $actor->userId(), $ticketId, $input->report, $now);

                    return [$reported, $this->toastOf($actor, $reported, $team)];
                } catch (TicketTitleMissing) {
                    $errors = ['title' => self::TITLE_MISSING];
                } catch (TicketTitleOverLimit) {
                    $errors = ['title' => NewTicketController::TITLE_OVER_LIMIT];
                } catch (TicketDescriptionOverLimit) {
                    $errors = ['description' => NewTicketController::DESCRIPTION_OVER_LIMIT];
                } catch (AssigneeNotInTeam) {
                    $errors = ['assignee' => self::ASSIGNEE_UNKNOWN];
                } catch (NewTicketReportRefused $screenshotRefused) {
                    $errors = ['screenshot' => $screenshotRefused->getMessage()];
                } catch (ProjectNotFound|ProjectHasNoTeam) {
                    // The project was removed or lost its team after the choices were read.
                    $errors = ['project' => self::PROJECT_UNKNOWN];
                }

                throw new TicketFormRefused($this->renderForm($actor, $teams, $input, $errors));
            });
        } catch (TicketFormRefused $refused) {
            return $refused->formPage();
        }
        [$reported, $toast] = $made;
        $this->addFlash(self::TOAST, $toast);

        return $this->redirectToRoute(TicketShowController::ROUTE, ['key' => $reported->key]);
    }

    /**
     * The live part of the form as it is typed (P06.T2, P06.T4): the possible duplicates as HTML and the "When you
     * send:" words. It reads and saves nothing, so it needs no form token; it answers only a person who may report.
     */
    #[Route('/report/preview', name: self::PREVIEW_ROUTE, methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        $actor = $this->reporter();
        $teams = $this->reportFormChoices->teamsFor($actor);
        [$duplicatesHtml, $sentence] = $this->liveParts($actor, $teams, ReportFormInput::fromRequest($request));

        return new JsonResponse(['duplicates' => $duplicatesHtml, 'preview' => $sentence]);
    }

    /**
     * Inside the transaction run opens, as NewTicketController reads it.
     */
    private function reporter(): ActorContext
    {
        $actor = $this->currentActor->actorFor($this->getUser()) ?? throw $this->createAccessDeniedException();
        $this->denyAccessUnlessGranted(TicketPermission::REPORT, $actor->organizationId());

        return $actor;
    }

    /**
     * The team (null for "Not sure"), the project and the person the form picked, each checked against the choices it
     * offered, and the field errors of those that are not among them.
     *
     * @param list<ReportTeamChoice> $teams
     *
     * @return array{?ReportTeamChoice, ?TeamProjectChoice, ?UserId, array<string, string>}
     */
    private function whereItGoes(array $teams, ReportFormInput $input): array
    {
        if ([] === $teams) {
            return [null, null, null, ['form' => self::NO_PROJECT_YET]];
        }
        $team = null;
        if (!$input->isNotSure()) {
            $team = array_values(array_filter($teams, static fn (ReportTeamChoice $choice): bool => $choice->id->toString() === $input->teamId))[0] ?? null;
            if (null === $team) {
                return [null, null, null, ['team' => self::TEAM_UNKNOWN]];
            }
        }
        $projects = null === $team ? array_merge(...array_map(static fn (ReportTeamChoice $choice): array => $choice->projects, $teams)) : $team->projects;
        $project = array_values(array_filter($projects, static fn (TeamProjectChoice $choice): bool => $choice->id() === $input->projectId))[0] ?? null;
        $errors = null === $project ? ['project' => self::PROJECT_UNKNOWN] : [];
        $assignee = null;
        if ('' !== $input->assigneeId) {
            $offered = null === $team ? [] : array_column($team->giveTo, 'id');
            if (\in_array($input->assigneeId, $offered, true)) {
                $assignee = new UserId($input->assigneeId);
            } else {
                $errors['assignee'] = self::ASSIGNEE_UNKNOWN;
            }
        }

        return [$team, $project, $assignee, $errors];
    }

    /**
     * @return array<string, string>
     */
    private static function fieldErrorsOf(ReportFormInput $input): array
    {
        $errors = [];
        $title = trim($input->title);
        if ('' === $title) {
            $errors['title'] = self::TITLE_MISSING;
        } elseif (mb_strlen($title) > NewTicketController::TITLE_MAX_LENGTH) {
            $errors['title'] = NewTicketController::TITLE_OVER_LIMIT;
        }
        if (mb_strlen($input->description) > NewTicketController::DESCRIPTION_MAX_LENGTH) {
            $errors['description'] = NewTicketController::DESCRIPTION_OVER_LIMIT;
        }
        if (!\array_key_exists($input->type, NewTicketController::TYPE_LABELS)) {
            $errors['type'] = NewTicketController::TYPE_UNKNOWN;
        }
        if (!\array_key_exists($input->priority, NewTicketController::PRIORITY_LABELS)) {
            $errors['priority'] = NewTicketController::PRIORITY_UNKNOWN;
        }

        return $errors;
    }

    /**
     * report-a-problem.md, Create ticket: with reasons the checker is named (the team's lead, or the organization's
     * triager with no team); without, nobody needs to check it.
     */
    private function toastOf(ActorContext $actor, ReportedTicket $reported, ?ReportTeamChoice $team): string
    {
        if ([] === $reported->checkReasons) {
            return \sprintf(self::CREATED_UNCHECKED, $reported->key);
        }
        $checker = $this->checkerNameOf($actor, $team);

        return match ($checker) {
            null => \sprintf(self::CREATED_NO_CHECKER, $reported->key),
            'You' => \sprintf(self::CREATED_FOR_YOU_TO_CHECK, $reported->key),
            default => \sprintf(self::CREATED_CHECKED, $reported->key, $checker),
        };
    }

    private function previewFactsOf(ActorContext $actor, ReportFormInput $input, bool $hasTeam): ReportPreviewFacts
    {
        return $this->reportPreviews->of($this->ticketVisibilities->of($actor->organizationId(), $actor->userId()), trim($input->title), $input->description, $input->pinNotes(), $hasTeam);
    }

    /**
     * The duplicates box as HTML and the "When you send:" words, or an empty one while no project is picked.
     *
     * @param list<ReportTeamChoice> $teams
     *
     * @return array{string, string}
     */
    private function liveParts(ActorContext $actor, array $teams, ReportFormInput $input): array
    {
        [$team, $project, $assignee] = $this->whereItGoes($teams, $input);
        $facts = $this->previewFactsOf($actor, $input, !$input->isNotSure());
        $duplicatesHtml = $this->renderView('@Ticketing/_report_duplicates.html.twig', ['duplicates' => $facts->duplicates]);
        $nextKey = null === $project ? null : $this->projectQueries->nextTicketKeyOf($project->id());
        if (null === $nextKey || (null === $team && !$input->isNotSure())) {
            return [$duplicatesHtml, ''];
        }
        $person = null;
        if (null !== $team && null !== $assignee) {
            $person = $assignee->equals($actor->userId()) ? 'you' : (array_values(array_filter($team->giveTo, static fn (array $choice): bool => $choice['id'] === $assignee->toString()))[0]['label'] ?? null);
        }

        return [$duplicatesHtml, ReportPreviewSentence::of($nextKey, $facts->reasonPhrases, $this->checkerNameOf($actor, $team), $person, null === $team ? null : ($team->leadFirstName ?? $this->leadFirstNameOf($team)), $facts->isTeamOnly)];
    }

    /**
     * "You" when the reporter checks, the checker's first name, or null when nobody is chosen: the team's lead, or the
     * organization's triager for no team.
     */
    private function checkerNameOf(ActorContext $actor, ?ReportTeamChoice $team): ?string
    {
        $checkerId = null === $team ? $this->settingsQueries->triagerOf($actor->organizationId()) : $this->teamQueries->leadOf($team->id);
        if (null === $checkerId) {
            return null;
        }
        if ($checkerId->equals($actor->userId())) {
            return 'You';
        }

        return ($this->userQueries->byIds([$checkerId])[$checkerId->toString()] ?? null)?->firstName();
    }

    private function leadFirstNameOf(?ReportTeamChoice $team): ?string
    {
        $leadId = null === $team ? null : $this->teamQueries->leadOf($team->id);

        return null === $leadId ? null : ($this->userQueries->byIds([$leadId])[$leadId->toString()] ?? null)?->firstName();
    }

    /**
     * @param list<ReportTeamChoice> $teams
     */
    private static function defaultInputOf(array $teams): ReportFormInput
    {
        $ownTeam = array_values(array_filter($teams, static fn (ReportTeamChoice $choice): bool => $choice->isOwn))[0] ?? null;
        if (null !== $ownTeam) {
            return new ReportFormInput(teamId: $ownTeam->id->toString(), projectId: $ownTeam->projects[0]->id());
        }
        // A reporter in no team that owns a project is "Not sure", with the first project of all.
        $firstTeam = $teams[0] ?? null;

        return new ReportFormInput(teamId: ReportFormInput::NO_TEAM, projectId: null === $firstTeam ? '' : $firstTeam->projects[0]->id());
    }

    /**
     * @param list<ReportTeamChoice> $teams
     * @param array<string, string>  $errors
     */
    private function renderForm(ActorContext $actor, array $teams, ReportFormInput $input, array $errors = []): Response
    {
        [$duplicatesHtml, $preview] = $this->liveParts($actor, $teams, $input);
        $allProjects = array_merge([], ...array_map(static fn (ReportTeamChoice $choice): array => $choice->projects, $teams));
        $uploadProject = array_values(array_filter($allProjects, static fn (TeamProjectChoice $choice): bool => $choice->id() === $input->projectId))[0] ?? $allProjects[0] ?? null;

        return $this->render('@Ticketing/report.html.twig', [
            'teams' => $teams,
            'notSureProjects' => $allProjects,
            'noTeam' => ReportFormInput::NO_TEAM,
            'input' => $input,
            'report' => $input->report,
            'errors' => $errors,
            'csrfId' => self::CSRF_ID,
            'uploadTargetKind' => self::UPLOAD_TARGET_KIND,
            'uploadProjectKey' => $uploadProject?->key(),
            'typeLabels' => NewTicketController::TYPE_LABELS,
            'priorityLabels' => NewTicketController::PRIORITY_LABELS,
            'titleMaxLength' => NewTicketController::TITLE_MAX_LENGTH,
            'descriptionMaxLength' => NewTicketController::DESCRIPTION_MAX_LENGTH,
            'duplicatesHtml' => $duplicatesHtml,
            'preview' => $preview,
        ], new Response(null, [] === $errors ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
