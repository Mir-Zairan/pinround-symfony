<?php

declare(strict_types=1);

namespace App\Identity\Domain;

use App\SharedKernel\Domain\UserId;

interface Users
{
    /**
     * A new id no user has yet: a UUIDv7, so ids sort by creation time (ADR-0006).
     */
    public function nextIdentity(): UserId;

    public function byId(UserId $id): ?User;

    /**
     * Matches in any letter case.
     */
    public function byEmail(Email $email): ?User;

    /**
     * Saves the user at once, so an email another user already has, in any letter case, fails here: the database's
     * unique index refuses it.
     *
     * @throws EmailTaken when another user has the email, also one another transaction saved after a byEmail() check;
     *                    the transaction is then failed and must be rolled back
     */
    public function add(User $user): void;

    /**
     * Saves the changes to a user already added, at once.
     */
    public function save(User $user): void;
}
