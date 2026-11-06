<?php

declare(strict_types=1);

namespace App\Web\UI\Component;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Renders nothing for an empty list, so a page can pass a list that may legitimately have no people.
 */
#[AsTwigComponent]
final class AvatarStack
{
    private const int MAX_SHOWN = 4;

    /** @var list<string> */
    public array $names = [];

    /**
     * @return list<string>
     */
    public function shownNames(): array
    {
        return \array_slice($this->names, 0, self::MAX_SHOWN);
    }

    /**
     * @return list<string>
     */
    public function hiddenNames(): array
    {
        return \array_slice($this->names, self::MAX_SHOWN);
    }
}
