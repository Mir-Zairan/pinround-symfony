<?php

declare(strict_types=1);

namespace App\Identity\Api;

/**
 * How strongly the person behind a security token proved who they are, for voters that need more than a login, such
 * as the admin pages (ADR-0012, ADR-0013). Both questions are allow-lists: a token kind they do not know is refused.
 */
interface LoginAssurance
{
    /**
     * @param mixed $token the security token a voter decides on
     *
     * @return bool true only when the person typed their password in this session (the login form, or the new token
     *              after a password change); false for remember-me, a personal access token and any other token kind
     */
    public function isPasswordSession(mixed $token): bool;

    /**
     * @param mixed $token the security token a voter decides on
     *
     * @return bool true when the person confirmed their two-step login code in this session
     */
    public function hasConfirmedTwoStepLogin(mixed $token): bool;
}
