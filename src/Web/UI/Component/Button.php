<?php

declare(strict_types=1);

namespace App\Web\UI\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PreMount;

#[AsTwigComponent]
final class Button
{
    // Props are public and mutable because the component system sets them after construction.
    public ButtonVariant $variant = ButtonVariant::Filled;

    public ButtonSize $size = ButtonSize::Md;

    public ?string $href = null;

    public string $type = 'button';

    public bool $disabled = false;

    /**
     * Templates pass plain strings; from() throws a ValueError for an unknown one, so a typo fails at mount.
     *
     * @param array<string, mixed> $props
     *
     * @return array<string, mixed>
     */
    #[PreMount]
    public function castEnums(array $props): array
    {
        if (\is_string($props['variant'] ?? null)) {
            $props['variant'] = ButtonVariant::from($props['variant']);
        }
        if (\is_string($props['size'] ?? null)) {
            $props['size'] = ButtonSize::from($props['size']);
        }

        return $props;
    }

    public function classes(): string
    {
        $classes = 'btn btn-'.$this->variant->value;

        return ButtonSize::Sm === $this->size ? $classes.' btn-sm' : $classes;
    }
}
