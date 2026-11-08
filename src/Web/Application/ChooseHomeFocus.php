<?php

declare(strict_types=1);

namespace App\Web\Application;

use App\Organization\Api\ActorContext;
use App\Web\Domain\HomeFocus;

/**
 * A person picks a Home focus chip (FR-HOME-08); it is saved for them in the actor's organization, so the next visit
 * on any device opens with it.
 */
final readonly class ChooseHomeFocus
{
    public function __construct(private HomeFocusPreferences $preferences)
    {
    }

    /**
     * @param string $focus a HomeFocus value, such as "my_team"
     *
     * @return bool false when $focus is not a HomeFocus value or not a chip the actor gets; nothing is saved then
     */
    public function __invoke(ActorContext $actor, string $focus, \DateTimeImmutable $at): bool
    {
        $chosenFocus = HomeFocus::tryFrom($focus);
        if (null === $chosenFocus || !HomeFocusChoices::isOfferedTo($chosenFocus, $actor->roles())) {
            return false;
        }

        $this->preferences->remember($actor->userId(), $chosenFocus, $at);

        return true;
    }
}
