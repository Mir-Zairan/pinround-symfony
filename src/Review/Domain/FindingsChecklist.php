<?php

declare(strict_types=1);

namespace App\Review\Domain;

use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;

/**
 * The check items of a findings round (ADR-0044, FR-RND-17): a shared draft round whose owner is not the ticket's
 * assignee, with one open item per pin on the latest try of each screenshot, as the team sees no earlier try (ADR-0048).
 * The round is the draft itself, so unlike Checklist no round is added. A self-check round, whose owner is the
 * assignee, gets none.
 */
final readonly class FindingsChecklist
{
    /**
     * @param non-empty-list<CheckItem> $items by screenshot position, then pin number
     */
    private function __construct(
        private RoundId $roundId,
        private OrganizationId $organizationId,
        private \DateTimeImmutable $createdAt,
        private array $items,
    ) {
    }

    /**
     * @param \Closure(): ?UserId     $assigneeOf the ticket's assignee as the share reads it, null when it has none,
     *                                            so every shared draft of the ticket is findings; called at most once,
     *                                            and only for a round with pins
     * @param \Closure(): CheckItemId $nextItemId called once per pin of findings
     *
     * @return self|null null for a self-check round and for a round without pins, as there is nothing to check
     *
     * @throws \InvalidArgumentException when the round is not a shared draft, or was deleted
     */
    public static function ofSharedDraft(Round $round, \Closure $assigneeOf, \Closure $nextItemId, \DateTimeImmutable $createdAt): ?self
    {
        if (RoundKind::Draft !== $round->kind() || null === $round->sharedAt() || null !== $round->deletedAt()) {
            throw new \InvalidArgumentException('Only a shared draft round has findings to check.');
        }
        $pins = [];
        foreach ($round->latestTries() as $screenshot) {
            foreach ($screenshot->pins() as $pin) {
                $pins[] = $pin;
            }
        }
        if ([] === $pins) {
            return null;
        }
        $assigneeId = $assigneeOf();
        if (null !== $assigneeId && $round->ownerId()->equals($assigneeId)) {
            return null;
        }
        $items = array_map(static fn (Pin $pin): CheckItem => CheckItem::open($nextItemId(), $round->id(), $round->ticketId(), $pin->id()), $pins);

        return new self($round->id(), $round->organizationId(), $createdAt, $items);
    }

    public function roundId(): RoundId
    {
        return $this->roundId;
    }

    public function organizationId(): OrganizationId
    {
        return $this->organizationId;
    }

    /**
     * When the items were made, which is when the round was shared.
     */
    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @return non-empty-list<CheckItem> by screenshot position, then pin number
     */
    public function items(): array
    {
        return $this->items;
    }
}
