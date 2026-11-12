<?php

declare(strict_types=1);

namespace App\Web\UI\Component;

use App\Ticketing\Api\ListFilter;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;
use Symfony\UX\TwigComponent\Attribute\PreMount;

/**
 * The person picker (prototype "Person picker"): a chip that opens a Dialog with the mode's title and sub line, the
 * PersonPickerSearch live component and Cancel. A plain component, so it re-renders with its parent's live component
 * at no cost of its own; the search sits inside it with props that never change after the page renders.
 */
#[AsTwigComponent]
final class PersonPicker
{
    // Props are public and mutable because the component system sets them after construction.
    public PersonPickerMode $mode = PersonPickerMode::Filter;

    /** The Dialog's id; the caller keeps it unique on the page. */
    public string $id = '';

    /** The team whose lead and members are listed, a canonical uuid. */
    public string $team = '';

    /** The project whose open tickets are counted, a canonical uuid; empty for every project. */
    public string $projectId = '';

    /** The person picked now, pressed among the rows; empty for none. */
    public string $pressedPerson = '';

    public string $triggerLabel = '';

    public bool $isTriggerPressed = false;

    /** The live event a row emits up to the parent component, with the picked person's id. */
    public string $pickedEvent = '';

    /**
     * Templates pass a plain string; from() throws a ValueError for an unknown one, so a typo fails at mount. A missing
     * mode fails too: a picker that guessed it could assign a ticket where the caller meant to filter.
     *
     * @param array<string, mixed> $props
     *
     * @return array<string, mixed>
     */
    #[PreMount]
    public function castMode(array $props): array
    {
        if (!\is_string($props['mode'] ?? null)) {
            throw new \InvalidArgumentException('A PersonPicker needs a mode.');
        }
        $props['mode'] = PersonPickerMode::from($props['mode']);

        return $props;
    }

    #[PostMount]
    public function assertIsValid(): void
    {
        if (!ListFilter::isCanonicalUuid($this->team)) {
            throw new \InvalidArgumentException('A PersonPicker needs a team given by its canonical uuid.');
        }
        if ('' !== $this->projectId && !ListFilter::isCanonicalUuid($this->projectId)) {
            throw new \InvalidArgumentException('A PersonPicker\'s project must be a canonical uuid or empty.');
        }
        if ('' !== $this->pressedPerson && !ListFilter::isCanonicalUuid($this->pressedPerson)) {
            throw new \InvalidArgumentException('A PersonPicker\'s pressed person must be a canonical uuid or empty.');
        }
        if ('' === trim($this->triggerLabel) || '' === $this->pickedEvent) {
            throw new \InvalidArgumentException('A PersonPicker needs a trigger label and the event its rows emit.');
        }
    }
}
