<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The keyed hash (HMAC-SHA256 under the app secret) of what identifies a login try, a password reset request, a
 * password change try or a two-step login code try (the user id, for password_change_per_user and
 * two_step_code_per_user): the counters in Valkey and the idn_login_attempt
 * rows hold it instead of the email, IP or user id, which a leaked store would otherwise show.
 */
final readonly class ThrottleKeyHasher
{
    public function __construct(
        #[Autowire(param: 'kernel.secret')]
        #[\SensitiveParameter]
        private string $secret,
    ) {
    }

    /**
     * @return string 64 lowercase hex characters
     */
    public function hexOf(string $data): string
    {
        return hash_hmac('sha256', $data, $this->secret);
    }

    /**
     * @return string the 32 raw bytes of the same hash, for a bytea column
     */
    public function bytesOf(string $data): string
    {
        return hash_hmac('sha256', $data, $this->secret, true);
    }

    /**
     * The email as Email writes it (trimmed, lowercase) but without checking its shape: a try with a mistyped address
     * is still a try.
     */
    public static function normalizeEmail(string $email): string
    {
        $trimmed = trim($email);

        return mb_check_encoding($trimmed, 'UTF-8') ? mb_strtolower($trimmed, 'UTF-8') : strtolower($trimmed);
    }
}
