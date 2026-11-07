<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\SharedKernel\Domain\UserId;

/**
 * The browser session of the request: who is logged in, and the two ways a security page changes the session.
 */
interface CurrentSession
{
    /**
     * @throws \LogicException when nobody is logged in
     */
    public function userId(): UserId;

    /**
     * After ChangePassword rotated the session stamp: puts the user as now saved into this session and gives the
     * session a new id, so this browser stays logged in while every other session of the user ends.
     *
     * @throws \LogicException when nobody is logged in
     */
    public function keepAfterPasswordChange(): void;

    /**
     * Logs this browser out and starts an empty session, which can still carry a flash message.
     *
     * @throws \LogicException when nobody is logged in
     */
    public function end(): void;
}
