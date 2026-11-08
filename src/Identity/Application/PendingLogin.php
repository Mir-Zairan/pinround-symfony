<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\SharedKernel\Domain\UserId;

/**
 * A login that waits for its two-step login code (ADR-0012): the password was right, and the browser session of the
 * request holds who typed it and when. Nobody is logged in while it waits.
 */
interface PendingLogin
{
    /** How long after the right password the code is still taken. */
    public const int PENDING_LOGIN_SECONDS = 300;

    /**
     * At the right password of a user whose two-step login is on; replaces a pending login from before.
     */
    public function begin(UserId $userId, \DateTimeImmutable $now): void;

    /**
     * @return UserId|null the user who waits for a code; null when nobody does, or the password was typed more than
     *                     PENDING_LOGIN_SECONDS before $now
     */
    public function userIdAt(\DateTimeImmutable $now): ?UserId;

    public function forget(): void;

    /**
     * After a right code: forgets the pending login and logs the user in exactly as the password form does, with a new
     * session id. The caller has checked the code.
     *
     * @return string|null the address to send the person to: the page they asked for before the login, or Home; null
     *                     when the user may no longer log in (gone, blocked or without a password), and then nobody
     *                     was logged in
     */
    public function finish(UserId $userId): ?string;
}
