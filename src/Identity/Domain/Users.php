<?php

declare(strict_types=1);

namespace App\Identity\Domain;

interface Users
{
    public function byId(UserId $id): ?User;

    /**
     * Matches in any letter case.
     */
    public function byEmail(Email $email): ?User;

    /**
     * Saves the user at once, so an email another user already has, in any letter case, fails here: the database's
     * unique index refuses it and the save throws.
     */
    public function add(User $user): void;
}
