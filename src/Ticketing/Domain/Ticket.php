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
    // Doctrine's optimistic lock; it raises the version on every update.
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
