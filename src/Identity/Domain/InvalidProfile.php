<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * User::changeProfile() refused at least one field and changed nothing.
 */
final class InvalidProfile extends \InvalidArgumentException
{
    /**
     * @param list<BlankUserName|UserNameTooLong|UnknownTimeZone|UnknownTheme> $refusals one per refused field, in form order
     */
    public function __construct(public readonly array $refusals)
    {
        parent::__construct('A user cannot keep this profile.');
    }
}
