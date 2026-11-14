<?php

declare(strict_types=1);

namespace App\Ticketing\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TenantOwned;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;

/**
 * A ticket of a project: a bug, story or task that moves through the workflow steps from its report to done. Step ids
 * are wf_step ids kept as text, since this layer may not see Workflow; the caller resolves them.
 */
final class Ticket implements TenantOwned
{
    public const int TITLE_MAX_LENGTH = 200;
    public const int DESCRIPTION_MAX_LENGTH = 50_000;
    private const array GROUP_CODES = ['todo', 'doing', 'check', 'wait', 'done'];

    private readonly string $organizationId;
    private readonly string $id;
    private readonly string $projectId;
    private readonly int $number;
    private readonly string $key;
    private string $title;
    private string $description;
    private TicketType $type;
    private Priority $priority;
    // Always $priority->rank(); stored so the list indexes can order by it.
    private int $priorityRank;
    private string $stepId;
    private ?string $previousStepId = null;
    private ?string $assigneeId;
    private ?string $pendingAssigneeId = null;
    private ?string $reviewerId = null;
    private ?string $testerId = null;
    private ?string $waitingOnUserId = null;
    private ?string $waitingOnLabel = null;
    private ?string $askedUserId = null;
    private ?string $askerId = null;
    private readonly string $reporterId;
    private ?string $teamId;
    private Visibility $visibility;
    private ?string $release = null;
    private ?string $parentId = null;
    /** @var list<string> */
    private array $components = [];
    /** @var list<string> */
    private array $checkReasons = [];
    private ?\DateTimeImmutable $checkedAt = null;
    private ?string $checkedBy = null;
    private ?string $closedAsDuplicateOf = null;
    // Set when the ticket's step is in the done group, cleared on reopen.
    private ?\DateTimeImmutable $closedAt = null;
    // The optimistic lock; Tickets::save raises it on every save that changes the ticket.
    private int $version = 1;
    private readonly \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        TicketId $id,
        OrganizationId $organizationId,
        ProjectId $projectId,
        TicketKey $key,
        string $title,
        string $description,
        TicketType $type,
        Priority $priority,
        Visibility $visibility,
        UserId $reporter,
        ?UserId $assignee,
        ?TeamId $team,
        string $stepId,
        \DateTimeImmutable $reportedAt,
    ) {
        $this->id = $id->toString();
        $this->organizationId = $organizationId->toString();
        $this->projectId = $projectId->toString();
        $this->number = $key->number();
        $this->key = $key->toString();
        $this->title = self::checkedTitle($title);
        $this->description = self::checkedDescription($description);
        $this->type = $type;
        $this->priority = $priority;
        $this->priorityRank = $priority->rank();
        $this->visibility = $visibility;
        $this->reporterId = $reporter->toString();
        $this->assigneeId = $assignee?->toString();
        $this->teamId = $team?->toString();
        $this->stepId = $stepId;
        $this->createdAt = $reportedAt;
        $this->updatedAt = $reportedAt;
    }

    /**
     * A new ticket starts in the To do step when it has an assignee, else in the Backlog step.
     *
     * @param string $backlogStepId the wf_step id of the Backlog step that applies to the ticket's team
     * @param string $toDoStepId    the wf_step id of the To do step that applies to the ticket's team
     *
     * @throws BlankTicketTitle         when nothing is left of the title after trimming
     * @throws TicketTitleTooLong       when the trimmed title has more than 200 characters
     * @throws TicketDescriptionTooLong when the description has more than 50,000 characters
     */
    public static function report(
        TicketId $id,
        OrganizationId $organizationId,
        ProjectId $projectId,
        TicketKey $key,
        string $title,
        string $description,
        TicketType $type,
        Priority $priority,
        Visibility $visibility,
        UserId $reporter,
        ?UserId $assignee,
        ?TeamId $team,
        string $backlogStepId,
        string $toDoStepId,
        \DateTimeImmutable $reportedAt,
    ): self {
        return new self(
            $id,
            $organizationId,
            $projectId,
            $key,
            $title,
            $description,
            $type,
            $priority,
            $visibility,
            $reporter,
            $assignee,
            $team,
            null === $assignee ? $backlogStepId : $toDoStepId,
            $reportedAt,
        );
    }

    public function id(): TicketId
    {
        return new TicketId($this->id);
    }

    public function organizationId(): OrganizationId
    {
        return new OrganizationId($this->organizationId);
    }

    public function projectId(): ProjectId
    {
        return new ProjectId($this->projectId);
    }

    public function key(): TicketKey
    {
        return TicketKey::fromString($this->key);
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function type(): TicketType
    {
        return $this->type;
    }

    public function priority(): Priority
    {
        return $this->priority;
    }

    public function visibility(): Visibility
    {
        return $this->visibility;
    }

    /**
     * The wf_step id of the step the ticket is in.
     */
    public function stepId(): string
    {
        return $this->stepId;
    }

    public function assignee(): ?UserId
    {
        return null === $this->assigneeId ? null : new UserId($this->assigneeId);
    }

    public function reporter(): UserId
    {
        return new UserId($this->reporterId);
    }

    public function team(): ?TeamId
    {
        return null === $this->teamId ? null : new TeamId($this->teamId);
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * The wf_step id of the step the ticket was in before its last move; null before its first.
     */
    public function previousStepId(): ?string
    {
        return $this->previousStepId;
    }

    public function pendingAssignee(): ?UserId
    {
        return null === $this->pendingAssigneeId ? null : new UserId($this->pendingAssigneeId);
    }

    public function reviewer(): ?UserId
    {
        return null === $this->reviewerId ? null : new UserId($this->reviewerId);
    }

    public function tester(): ?UserId
    {
        return null === $this->testerId ? null : new UserId($this->testerId);
    }

    /**
     * The person a ticket in Needs input waits on; null when it waits on a label, or is in another step.
     */
    public function waitingOnUser(): ?UserId
    {
        return null === $this->waitingOnUserId ? null : new UserId($this->waitingOnUserId);
    }

    /**
     * The label a ticket in Needs input waits on, for example "Customer"; null when it waits on a person, or is in
     * another step.
     */
    public function waitingOnLabel(): ?string
    {
        return $this->waitingOnLabel;
    }

    /**
     * The person who must answer the ticket's open question; null while no question pauses the ticket.
     */
    public function askedUser(): ?UserId
    {
        return null === $this->askedUserId ? null : new UserId($this->askedUserId);
    }

    /**
     * The person who asked the ticket's open question; null exactly when askedUser() is null.
     */
    public function asker(): ?UserId
    {
        return null === $this->askerId ? null : new UserId($this->askerId);
    }

    public function release(): ?string
    {
        return $this->release;
    }

    /**
     * True while the ticket's check reasons include "risk".
     */
    public function isRiskCheck(): bool
    {
        return \in_array('risk', $this->checkReasons, true);
    }

    /**
     * The optimistic lock version as last read or saved; Tickets::save raises it on every save that changes the ticket.
     */
    public function version(): int
    {
        return $this->version;
    }

    /**
     * When the ticket last entered the done group; null while its step is in any other group.
     */
    public function closedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    /**
     * Moves the ticket to the step. The caller has resolved the step and checked the move is allowed; the ticket only
     * keeps its own fields in step. Entering the done group closes it, and leaving that group reopens it. Who it waits
     * on is kept only in Needs input. A reviewer or tester already stored is kept. A refused move changes nothing.
     *
     * @param string         $stepId           the wf_step id of the step the ticket moves to
     * @param string         $groupCode        that step's group: todo, doing, check, wait or done
     * @param bool           $isNeedsInputStep true when that step is the core Needs input step
     * @param WaitingOn|null $waitingOn        who the ticket waits on; given exactly when $isNeedsInputStep is true
     * @param UserId|null    $chosenReviewer   the reviewer to store when the ticket has none (FR-WF-14)
     * @param UserId|null    $chosenTester     the tester to store when the ticket has none (FR-WF-14)
     *
     * @throws InvalidWaitingOn          when a Needs input step has no one to wait on, or another step has someone
     * @throws \InvalidArgumentException when the group code is none of the five
     */
    public function move(
        string $stepId,
        string $groupCode,
        bool $isNeedsInputStep,
        ?WaitingOn $waitingOn,
        \DateTimeImmutable $movedAt,
        ?UserId $chosenReviewer = null,
        ?UserId $chosenTester = null,
    ): void {
        if (!\in_array($groupCode, self::GROUP_CODES, true)) {
            throw new \InvalidArgumentException('A step group is one of todo, doing, check, wait or done.');
        }
        if ($isNeedsInputStep && null === $waitingOn) {
            throw new InvalidWaitingOn('A ticket in Needs input waits on a person or a label.');
        }
        if (!$isNeedsInputStep && null !== $waitingOn) {
            throw new InvalidWaitingOn('Only a ticket in Needs input waits on someone.');
        }

        $this->previousStepId = $this->stepId;
        $this->stepId = $stepId;
        if ('done' !== $groupCode) {
            $this->closedAt = null;
        } elseif (null === $this->closedAt) {
            $this->closedAt = $movedAt;
        }
        $this->waitingOnUserId = $waitingOn?->userId()?->toString();
        $this->waitingOnLabel = $waitingOn?->label();
        $this->reviewerId ??= $chosenReviewer?->toString();
        $this->testerId ??= $chosenTester?->toString();
        $this->updatedAt = $movedAt;
    }

    /**
     * Makes the person the ticket's assignee; the caller has checked they may take it. A new assignee clears the stored
     * reviewer, as NoSelfReview reads a stored reviewer equal to the assignee as the no-one-else fallback, and clears the
     * stored tester when it is the new assignee. The same assignee given again keeps both.
     */
    public function giveTo(UserId $assignee, \DateTimeImmutable $givenAt): void
    {
        $assigneeId = $assignee->toString();
        if ($assigneeId !== $this->assigneeId) {
            $this->reviewerId = null;
            if ($assigneeId === $this->testerId) {
                $this->testerId = null;
            }
        }
        $this->assigneeId = $assigneeId;
        $this->updatedAt = $givenAt;
    }

    /**
     * Pauses the ticket until the asked person answers the asker's question (FR-CNV-08); the step stays as it is. The
     * caller has checked the asked person is on the ticket. A refused pause changes nothing.
     *
     * @throws QuestionAlreadyOpen       when another question already pauses the ticket
     * @throws \InvalidArgumentException when the asker asks themselves
     */
    public function pauseForQuestion(UserId $asked, UserId $asker): void
    {
        if (null !== $this->askedUserId) {
            throw new QuestionAlreadyOpen('This ticket already waits for an answer.');
        }
        if ($asked->equals($asker)) {
            throw new \InvalidArgumentException('A person cannot ask themselves a question.');
        }

        $this->askedUserId = $asked->toString();
        $this->askerId = $asker->toString();
    }

    /**
     * Ends the question pause, so the turn goes back to the step's normal owner.
     *
     * @throws \LogicException when no question pauses the ticket
     */
    public function resume(): void
    {
        if (null === $this->askedUserId) {
            throw new \LogicException('This ticket waits for no answer.');
        }

        $this->askedUserId = null;
        $this->askerId = null;
    }

    private static function checkedTitle(string $title): string
    {
        $trimmedTitle = trim($title);
        if ('' === $trimmedTitle) {
            throw new BlankTicketTitle('A ticket needs a title.');
        }
        if (mb_strlen($trimmedTitle) > self::TITLE_MAX_LENGTH) {
            throw new TicketTitleTooLong('A ticket title has at most 200 characters.');
        }

        return $trimmedTitle;
    }

    private static function checkedDescription(string $description): string
    {
        if (mb_strlen($description) > self::DESCRIPTION_MAX_LENGTH) {
            throw new TicketDescriptionTooLong('A ticket description has at most 50,000 characters.');
        }

        return $description;
    }
}
