<?php

declare(strict_types=1);

namespace App\Identity\Application;

/**
 * The 6-digit codes of an authenticator app (TOTP, RFC 6238: SHA-1, 30-second steps), made from a seed the app and
 * pinround share.
 */
interface OneTimeCodes
{
    public const int STEP_SECONDS = 30;

    /**
     * @return string 20 random bytes in base32 (RFC 4648, upper case, no padding): 32 characters
     */
    public function newSeed(): string;

    /**
     * Allows one step before and one after $at, for a phone clock that is a little off.
     *
     * @param string $code the typed code, 6 digits
     *
     * @return int|null the time step (Unix seconds divided by 30) whose code it is, the latest one when two match;
     *                  null when it is the code of no step in that window
     *
     * @throws \InvalidArgumentException when the seed is empty
     */
    public function matchingStep(#[\SensitiveParameter] string $seed, #[\SensitiveParameter] string $code, \DateTimeImmutable $at): ?int;

    /**
     * The otpauth:// link an authenticator app reads from the QR code. It holds the seed.
     *
     * @param string $accountName what the app shows under the issuer, the user's email
     *
     * @throws \InvalidArgumentException when the seed or the account name is empty
     */
    public function provisioningUri(#[\SensitiveParameter] string $seed, string $accountName): string;
}
