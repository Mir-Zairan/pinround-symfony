<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * Reads the form fields of a token or revocation request (RFC 6749 section 4.1.3, RFC 7009 section 2.1).
 */
final class OAuthTokenParameters
{
    /**
     * @param array<array-key, mixed> $parameters the request's form fields
     * @param int                     $maxLength  in bytes; longer is refused before anything is looked up
     *
     * @throws OAuthTokenRefused invalid_request when the field is absent, empty, not one string (such as name[]=x) or
     *                           longer than $maxLength
     */
    public static function required(array $parameters, string $name, int $maxLength): string
    {
        $value = $parameters[$name] ?? null;
        if (!\is_string($value) || '' === $value) {
            throw OAuthTokenRefused::invalidRequest(\sprintf('%s is required, once.', $name));
        }
        if (\strlen($value) > $maxLength) {
            throw OAuthTokenRefused::invalidRequest(\sprintf('%s has at most %d characters.', $name, $maxLength));
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $parameters the request's form fields
     *
     * @return string|null null when the field is absent or empty
     *
     * @throws OAuthTokenRefused invalid_request when the field is not one string or is longer than $maxLength
     */
    public static function optional(array $parameters, string $name, int $maxLength): ?string
    {
        $value = $parameters[$name] ?? null;
        if (null === $value || '' === $value) {
            return null;
        }

        return self::required($parameters, $name, $maxLength);
    }
}
