<?php

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * The random part of Identity's tokens, codes and client ids.
 */
final class RandomToken
{
    private function __construct()
    {
    }

    /**
     * $bytes from the system's secure random source in base64url without padding (RFC 4648 section 5), safe in a URL,
     * a header and a form field as it is: ceil($bytes * 4 / 3) characters of A-Z, a-z, 0-9, "-" and "_".
     *
     * @param int<1, max> $bytes
     */
    public static function base64Url(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
