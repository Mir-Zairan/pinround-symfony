<?php

declare(strict_types=1);

namespace App\Web\Application;

use App\Organization\Api\ActorContext;
use App\Web\Domain\HomeFocus;

/**
 * The chip Home opens with: the actor's saved one while they still get it, else All. A role lost since the choice
 * (no longer an Admin, say) falls back to All without changing the saved row.
 */
final readonly class ShowHomeFocus
{
    public function __construct(private HomeFocusPreferences $preferences)
    {
    }

    public function __invoke(ActorContext $actor): HomeFocus
    {
        $savedFocus = $this->preferences->focusOf($actor->userId());
        if (null === $savedFocus || !HomeFocusChoices::isOfferedTo($savedFocus, $actor->roles())) {
            return HomeFocus::All;
        }

        return $savedFocus;
    }
}
