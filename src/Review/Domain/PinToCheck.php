<?php

declare(strict_types=1);

namespace App\Review\Domain;

/**
 * A pin of the earlier round as a review round checks it: on the "before" picture of one of the round's areas. Only a
 * person's pin or an AI pin a person kept counts (FR-RND-07, ADR-0020); a suggested or dismissed AI pin never gets a
 * verdict.
 */
final readonly class PinToCheck
{
    public const string NOT_COUNTED = 'This AI suggestion does not count until a person keeps it.';
    public const string VERDICT_GIVEN = 'This pin has a verdict already. Undo it first.';
    public const string NO_VERDICT = 'This pin has no verdict to undo.';

    public function __construct(
        private PinId $id,
        private PinSource $source,
        private ?AiPinState $aiState,
        private ?PinVerdict $verdict,
    ) {
    }

    /**
     * The pin with $verdict; this one stays as it was.
     *
     * @throws \DomainException when the pin does not count (NOT_COUNTED) or has a verdict already (VERDICT_GIVEN)
     */
    public function judge(PinVerdict $verdict): self
    {
        $this->requireCounted();
        if (null !== $this->verdict) {
            throw new \DomainException(self::VERDICT_GIVEN);
        }

        return new self($this->id, $this->source, $this->aiState, $verdict);
    }

    /**
     * The pin with no verdict; this one stays as it was.
     *
     * @throws \DomainException when the pin does not count (NOT_COUNTED) or has no verdict (NO_VERDICT)
     */
    public function undo(): self
    {
        $this->requireCounted();
        if (null === $this->verdict) {
            throw new \DomainException(self::NO_VERDICT);
        }

        return new self($this->id, $this->source, $this->aiState, null);
    }

    public function counts(): bool
    {
        return PinSource::Human === $this->source || AiPinState::Kept === $this->aiState;
    }

    public function id(): PinId
    {
        return $this->id;
    }

    public function verdict(): ?PinVerdict
    {
        return $this->verdict;
    }

    /**
     * @throws \DomainException NOT_COUNTED when the pin does not count
     */
    private function requireCounted(): void
    {
        if (!$this->counts()) {
            throw new \DomainException(self::NOT_COUNTED);
        }
    }
}
