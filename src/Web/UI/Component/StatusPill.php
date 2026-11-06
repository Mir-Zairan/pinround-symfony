<?php

declare(strict_types=1);

namespace App\Web\UI\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PreMount;

#[AsTwigComponent]
final class StatusPill
{
    public Group $group = Group::ToDo;

    /**
     * Templates pass a plain string; from() throws a ValueError for an unknown group, so a typo fails at mount.
     *
     * @param array<string, mixed> $props
     *
     * @return array<string, mixed>
     */
    #[PreMount]
    public function castGroup(array $props): array
    {
        if (\is_string($props['group'] ?? null)) {
            $props['group'] = Group::from($props['group']);
        }

        return $props;
    }

    public function classes(): string
    {
        return 'pill pill-'.$this->group->value;
    }
}
