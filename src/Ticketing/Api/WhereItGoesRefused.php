<?php

declare(strict_types=1);

namespace App\Ticketing\Api;

/**
 * One part of a posted "Where it goes" that is not among the reporter's choices; the message says what to pick.
 */
final class WhereItGoesRefused extends \DomainException
{
    /**
     * @param array<string, string> $errors by part: form, team, project, assignee, type or priority; never empty
     */
    public function __construct(public readonly array $errors)
    {
        if ([] === $errors) {
            throw new \InvalidArgumentException('A refusal of "Where it goes" names at least one part.');
        }
        parent::__construct(array_values($errors)[0]);
    }
}
