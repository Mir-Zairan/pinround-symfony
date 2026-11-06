<?php

declare(strict_types=1);

namespace App\Web\UI\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\Attribute\PostMount;
use Symfony\UX\TwigComponent\Attribute\PreMount;

#[AsTwigComponent]
final class Avatar
{
    /** The person's full name; it is the accessible name, the initials are decorative. */
    public string $name = '';

    public AvatarSize $size = AvatarSize::Md;

    /**
     * Templates pass a plain string; from() throws a ValueError for an unknown size, so a typo fails at mount.
     *
     * @param array<string, mixed> $props
     *
     * @return array<string, mixed>
     */
    #[PreMount]
    public function castSize(array $props): array
    {
        if (\is_string($props['size'] ?? null)) {
            $props['size'] = AvatarSize::from($props['size']);
        }

        return $props;
    }

    #[PostMount]
    public function assertHasName(): void
    {
        if ('' === trim($this->name)) {
            throw new \InvalidArgumentException('An Avatar needs a non-empty name.');
        }
    }

    public function classes(): string
    {
        return AvatarSize::Lg === $this->size ? 'av av-lg' : 'av';
    }

    /** First letter of the first two words, uppercase. */
    public function initials(): string
    {
        $words = preg_split('/\s+/u', trim($this->name), -1, \PREG_SPLIT_NO_EMPTY);
        $initials = '';
        foreach (\array_slice(false === $words ? [] : $words, 0, 2) as $word) {
            $initials .= mb_strtoupper(mb_substr($word, 0, 1));
        }

        return $initials;
    }
}
